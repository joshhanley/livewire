# Script Module Loading: Race Condition Analysis and Solution Design

## The Problem

Livewire v4 SFC/MFC components can have co-located JavaScript files (either `<script>` blocks in SFCs or separate `.js` files in MFCs). These are compiled into ES modules served from `/livewire/js/{component}.js` and loaded dynamically via `import()`.

The core issue: **script modules load asynchronously, but Alpine processes `x-data` synchronously**. When a script module contains `Alpine.data()` registrations, those registrations aren't available when Alpine evaluates `x-data="componentName"`, causing "Can't find variable" errors.

**Reported in:**
- [#9591](https://github.com/livewire/livewire/discussions/9591) - SFC with `Alpine.data()` in `<script>` block
- [#9737](https://github.com/livewire/livewire/discussions/9737) - Same issue, also affects MFC `.js` files
- [#9830](https://github.com/livewire/livewire/discussions/9830) - Detailed analysis of MFC `.js` files not working with `Alpine.data()`

## Current Architecture

### How script modules are served

1. The compiler (`Parser.php`) injects a `scriptModuleSrc()` method into the component class, pointing to the compiled `.js` file
2. `SupportJsModules.php` adds a `scriptModule` effect (a hash of the file's mtime) during `dehydrate()`
3. A route at `/livewire/js/{encoded-name}.js` serves the file contents

### How script modules are loaded (JS side)

In `supportJsModules.js`:

```
effect hook fires
  -> extract scriptModule hash from effects
  -> construct module URL
  -> import(url)              <-- ASYNC
  -> .then(module => {
       module.run($wire, $js)  <-- Alpine.data() registrations happen HERE
     })
```

### The race condition in detail

On initial page load, `lifecycle.js` calls `Alpine.start()`, which walks the DOM via `initTree()`. For each element:

```
initTree(el)
  -> deferHandlingDirectives(() => {
       walker(el, (el, skip) => {
         interceptInit callbacks run     <-- Livewire creates Component, fires processEffects()
                                             which triggers the effect hook, starting async import()
         directives(el).forEach(handle)  <-- x-data directive handler is COLLECTED here
       })
     })                                  <-- all collected directive handlers FLUSH here (synchronous)
```

The `x-data` directive evaluates `Alpine.data('componentName')` lookups synchronously. But the `import()` that would register those data components hasn't resolved yet.

### Why `@script`/`@endscript` works

The `@script` directive stores script content inline in `wire:effects` as the `scripts` effect. `supportScriptsAndAssets.js` evaluates this content **synchronously** via `Alpine.evaluateExpression()` during the same `effect` hook. So `Alpine.data()` registrations happen before `x-data` is processed.

### Existing partial fix: `$js` actions

`$wire.js` already handles the race condition for `$js` actions: when `assetIsPendingFor(component)` is true, `$js` property access returns a promise that resolves after the module loads. But this doesn't help with `Alpine.data()` registrations since those go through Alpine's own data resolution, not through `$wire`.

---

## Three Scenarios to Solve

### Scenario 1: Initial page load

Components present in the server-rendered HTML have `wire:effects` containing a `scriptModule` hash. Their script modules must be loaded and executed before Alpine processes `x-data` on those components.

### Scenario 2: Lazy/deferred components

Lazy components render a placeholder on initial page load. `SupportJsModules::dehydrate()` returns early when `isLazyLoadMounting` is true, so no `scriptModule` effect is added to the placeholder. The real component (with its `scriptModule` effect) is loaded via an AJAX request triggered by `x-intersect` or `x-init`.

### Scenario 3: Dynamically added components

A parent component re-renders and its new HTML includes a child component that wasn't there before. The morph adds the child to the DOM, Alpine's mutation observer detects it, and `initTree()` runs on the new element. The child's `scriptModule` effect triggers an async import, but `x-data` is processed before it resolves.

This differs from scenarios 1 and 2 because we don't have advance notice of which modules will be needed; they appear embedded in the parent's HTML response.

---

## Solution: Pre-import + Deferred Init + Morph Delay

The solution has three parts, one for each scenario. Each part uses a different mechanism suited to its context, but they all share a common module cache.

### Part 1: Pre-import for initial page load (scenario 1)

Before calling `Alpine.start()` in `lifecycle.js`, scan all `[wire:id]` elements on the page, parse their `wire:effects` attributes to find `scriptModule` hashes, and pre-import all modules in parallel. Wait for all imports to resolve, cache the modules, then start Alpine.

```js
// lifecycle.js
export async function start() {
    // ... dispatch events, register plugins ...

    await preloadInitialScriptModules()

    Alpine.start()
    // ...
}

async function preloadInitialScriptModules() {
    let els = document.querySelectorAll('[wire\\:id][wire\\:effects]')
    let promises = []

    els.forEach(el => {
        let effects = JSON.parse(el.getAttribute('wire:effects'))
        if (!effects.scriptModule) return

        let snapshot = JSON.parse(el.getAttribute('wire:snapshot'))
        let name = snapshot.memo.name
        let hash = effects.scriptModule
        let encodedName = name.replace(/\./g, '--').replace(/::/g, '---').replace(/:/g, '----')
        let path = `${getModuleUrl()}/js/${encodedName}.js?v=${hash}`

        promises.push(
            import(/* @vite-ignore */ path).then(module => {
                moduleCache.set(`${name}:${hash}`, module)
            })
        )
    })

    await Promise.all(promises)
}
```

This makes `start()` async, but it's called from a `DOMContentLoaded` listener so that's fine.

In `supportJsModules.js`, the `effect` handler checks the cache first. If the module is already cached, it calls `module.run()` **synchronously**. If not (scenarios 2/3), it falls back to async import and the pending asset mechanism.

```js
let moduleCache = new Map()

on('effect', ({ component, effects }) => {
    let hash = effects.scriptModule
    if (!hash) return

    let cacheKey = `${component.name}:${hash}`
    let cached = moduleCache.get(cacheKey)

    if (cached) {
        // Module already loaded - run synchronously
        cached.run.call(component.$wire, component.$wire, component.$wire.js)
        return
    }

    // Module not cached - load async, mark as pending
    pendingComponentAssets.set(component, Alpine.reactive({
        loading: true,
        afterLoaded: [],
    }))

    let encodedName = component.name.replace(/\./g, '--').replace(/::/g, '---').replace(/:/g, '----')
    let path = `${getModuleUrl()}/js/${encodedName}.js?v=${hash}`

    import(/* @vite-ignore */ path).then(module => {
        module.run.call(component.$wire, component.$wire, component.$wire.js)
        pendingComponentAssets.get(component).loading = false
        pendingComponentAssets.get(component).afterLoaded.forEach(cb => cb())
        pendingComponentAssets.delete(component)
    })
})
```

### Part 2: Deferred init safety net (all scenarios)

As a safety net for any component that starts initialising before its module is ready (e.g. dynamically added components picked up by Alpine's mutation observer), defer Alpine's directive processing on the component until the module loads.

In `lifecycle.js`'s `interceptInit` callback, after creating the Component:

```js
if (el.hasAttribute('wire:id') && !el.__livewire && !hasComponent(el.getAttribute('wire:id'))) {
    let component = initComponent(el)

    Alpine.onAttributeRemoved(el, 'wire:id', () => {
        destroyComponent(component.id)
    })

    // If the component has a pending script module, defer Alpine init
    if (assetIsPendingFor(component)) {
        el._x_ignore = true
        skip()

        runAfterAssetIsLoadedFor(component, () => {
            delete el._x_ignore
            Alpine.initTree(el)
        })
        return
    }
}
```

#### Why there's no double-init problem

On the **first pass** (module pending):
- `interceptInit` creates the Component, detects the pending asset, sets `el._x_ignore = true`, calls `skip()`, and **returns early**
- `_x_ignore` prevents `_x_marker` from being set on the element (Alpine's `initTree` only sets `_x_marker` when `_x_ignore` is falsy)
- `skip()` prevents the walker from visiting any child elements
- The early `return` prevents `directive.global.init` and `directive.init` hooks from firing
- Alpine's directive handlers are collected for the root element but early-return when flushed (they check `_x_ignore` at execution time)

On the **re-init pass** (module loaded, `_x_ignore` deleted):
- `_x_marker` was never set, so `initTree` proceeds
- `interceptInit` fires but skips Component creation (`el.__livewire` exists)
- `directive.global.init` and `directive.init` fire for the **first time**
- Alpine's directive handlers (including `x-data`) fire for the **first time**
- Children are visited for the **first time**

Everything fires exactly once. No guards needed.

### Part 3: Delay morph until modules are ready (scenarios 2 and 3)

When a Livewire AJAX response contains new child components with script modules (or a lazy component's own module), the morph should not apply until those modules are loaded and cached. This prevents any flash of uninitialised content; the parent keeps its old state (or the lazy placeholder stays visible) until everything is ready.

The module loading concern should stay in `supportJsModules.js`, not bleed into `morph.js`. We use the same `interceptMessage` pattern that `supportMorphDom.js` uses, hooking into the request lifecycle.

The challenge is timing: we need module loading to complete **before** the morph runs. Both would use the message lifecycle hooks. The available hooks in order are:

```
onSuccess → onSync → onEffect → onMorph → onFinish → onRender
```

The morph runs in `onMorph`. We need module pre-loading to complete before that. There are several options:

#### Option A: Make `onEffect` awaitable

Currently `invokeOnEffect()` is not awaited (line 435 in `request/index.js`). If we make it awaitable (like `invokeOnMorph` is), `supportJsModules.js` could use `onEffect` to wait for pending modules:

```js
// supportJsModules.js
interceptMessage(({ message, onSuccess }) => {
    onSuccess(({ payload, onEffect }) => {
        onEffect(async () => {
            let promises = []

            // Component's own module (lazy load case)
            if (assetIsPendingFor(message.component)) {
                promises.push(new Promise(resolve => {
                    runAfterAssetIsLoadedFor(message.component, resolve)
                }))
            }

            // New child component modules in the HTML
            let html = payload.effects.html
            if (html) {
                let wrapper = document.createElement('div')
                wrapper.innerHTML = html
                wrapper.querySelectorAll('[wire\\:effects]').forEach(el => {
                    let effects = JSON.parse(el.getAttribute('wire:effects'))
                    if (!effects.scriptModule) return
                    let snapshot = JSON.parse(el.getAttribute('wire:snapshot'))
                    // ... import and cache module
                    promises.push(importAndCacheModule(snapshot.memo.name, effects.scriptModule))
                })
            }

            if (promises.length) await Promise.all(promises)
        })
    })
})
```

The `processEffects` call happens before `invokeOnEffect` (line 433-435 in `request/index.js`), so the async `import()` is already in flight by the time `onEffect` fires. The `onEffect` handler just waits for it to resolve.

**Trade-offs:**
- (+) Uses existing hook, semantically correct ("after effects processed, wait for them to settle")
- (+) Keeps module logic in `supportJsModules.js`
- (-) Making `onEffect` async could have unintended consequences for other `onEffect` listeners
- (-) Changes the documented hook contract (though the docs currently describe `onEffect` as "After effects processed" which is vague)

#### Option B: Make `invokeOnMorph` run callbacks sequentially

Change `invokeOnMorph` from `Promise.all` to sequential execution (in registration order). Then ensure `supportJsModules.js` registers its `onMorph` callback before `supportMorphDom.js` by importing it first in `features/index.js`.

```js
// message.js
async invokeOnMorph() {
    for (let interceptor of this.interceptors) {
        await interceptor.onMorph()
    }
}
```

**Trade-offs:**
- (+) No new hooks needed
- (-) Relies on import order in `features/index.js`, which is fragile
- (-) Sequential execution of all `onMorph` callbacks could be slower than parallel
- (-) Semantically confusing: the module loading step isn't really "morphing"

#### Option C: Add a dedicated `onBeforeMorph` hook

Add a new awaited hook that runs between `onEffect` and `onMorph`:

```
onSuccess → onSync → onEffect → onBeforeMorph → onMorph → onFinish → onRender
```

```js
// request/index.js
message.component.processEffects(effects, request)
message.invokeOnEffect()
await message.invokeOnBeforeMorph()  // new, awaited
await message.invokeOnMorph()
```

**Trade-offs:**
- (+) Explicit, self-documenting, no ordering ambiguity
- (+) Clean separation of concerns
- (-) Adds a new hook to the public API / lifecycle
- (-) More code to maintain

---

## `<link rel="modulepreload">` (Enhancement, not a fix)

Injecting `<link rel="modulepreload" href="/livewire/js/{component}.js">` tags into `<head>` from PHP would tell the browser to start fetching modules immediately, before any JS runs. This makes the Part 1 pre-import resolve faster since the browser has already started (or finished) downloading the modules by the time `import()` is called.

This also works with `wire:navigate`. The navigate plugin's `mergeNewHead` function swaps in the new page's `<head>` elements. `<link rel="modulepreload">` tags don't match `isAsset()` (which only checks for stylesheets, styles, and scripts), so they're treated as non-asset elements: old ones are removed, new ones are appended. The browser starts preloading as soon as the link is appended to `<head>`, so modules for the new page begin downloading immediately.

`rel="modulepreload"` does work when dynamically added to the DOM (not just when present in the initial HTML). This has been verified.

---

## Resolved Questions

### Lazy component modules: defer, don't preload

Lazy components are lazy for a reason; the user has explicitly said "don't load this until needed." Loading their JS module eagerly contradicts that intent and wastes network if the user never scrolls to the component. When the lazy load AJAX response arrives, the module is handled by the morph delay mechanism (Part 3) and the deferred init safety net (Part 2).

### `wire:navigate`: no special handling needed

Navigate works the same as an initial page load. The navigate plugin calls `Alpine.initTree(document.body)` on the new page, which is the same code path as `Alpine.start()`. The deferred init safety net (Part 2) handles any components with pending modules.

If we implement `<link rel="modulepreload">` tags (the enhancement above), navigate's head merge would swap in the new page's preload hints, and the browser would start fetching modules before `initTree` runs. The `import()` calls would resolve near-instantly from the browser's module cache.

**Important:** Navigate needs to be tested after implementation to verify it all works correctly.

### Double-init of wire directives: not a problem

Detailed analysis confirmed that the deferred init approach (Part 2) does not cause any double initialisation. See the "Why there's no double-init problem" section above for the full walkthrough.

### Flash of content: morph waits, don't rely on user

The morph should delay until all child component modules are loaded. The parent keeps its old state (or lazy placeholder stays visible) until everything is ready. This is handled by Part 3 (delay morph until modules are ready). The user should not need to add `x-cloak` or any other attribute to prevent flash.

Note: `wire:cloak` would NOT help here anyway. It has its own `interceptInit` callback that removes the attribute immediately when the element is first seen, before any module loading. `x-cloak` would work (it's removed by the `x-data` directive handler, which wouldn't run until re-init), but relying on the user to add it is a poor experience.

### Error handling: catch, warn, continue

If a module fails to load (network error, 404), the component should still initialise without its script module's functionality. A partially working component is better than one that never appears.

- **Part 1 (pre-import):** Catch per-module errors. One failed module should not block other components from initialising. Log a console warning.
- **Part 2 (deferred init):** If the module fails, remove `_x_ignore` and let the component init without the module. Log a console warning.
- **Part 3 (morph delay):** Don't block the morph forever. Log a console warning and proceed.
