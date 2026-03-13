# Script Module Loading: Race Condition Analysis and Solution Ideas

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

## Three Scenarios to Solve

### Scenario 1: Initial page load

Components present in the server-rendered HTML have `wire:effects` containing a `scriptModule` hash. Their script modules must be loaded and executed before Alpine processes `x-data` on those components.

### Scenario 2: Lazy/deferred components

Lazy components render a placeholder on initial page load. `SupportJsModules::dehydrate()` returns early when `isLazyLoadMounting` is true, so no `scriptModule` effect is added to the placeholder. The real component (with its `scriptModule` effect) is loaded via an AJAX request triggered by `x-intersect` or `x-init`.

**Decision needed:** Should we preload lazy component modules on page load (faster when they become visible) or wait until the lazy load request fires (less upfront network)?

### Scenario 3: Dynamically added components

A parent component re-renders and its new HTML includes a child component that wasn't there before. The morph adds the child to the DOM, Alpine's mutation observer detects it, and `initTree()` runs on the new element. The child's `scriptModule` effect triggers an async import, but `x-data` is processed before it resolves.

This differs from scenarios 1 and 2 because we don't have advance notice of which modules will be needed; they appear embedded in the parent's HTML response.

---

## Solution Ideas

### Idea A: Pre-import modules before `Alpine.start()` (solves scenario 1)

Before calling `Alpine.start()` in `lifecycle.js`, scan all `[wire:id]` elements on the page, parse their `wire:effects` attributes to find `scriptModule` hashes, and pre-import all modules in parallel. Wait for all imports to resolve, then start Alpine.

```js
// lifecycle.js
export async function start() {
    // ... plugin registration ...

    // Pre-load all script modules before Alpine starts
    let moduleCache = await preloadScriptModules()

    Alpine.start()
    // ...
}
```

The `preloadScriptModules()` function would:
1. Query `document.querySelectorAll('[wire\\:id][wire\\:effects]')`
2. Parse each element's `wire:effects` JSON for a `scriptModule` key
3. Parse each element's `wire:snapshot` JSON for the component name
4. Construct the module URL and call `import()`
5. Store resolved modules in a cache keyed by component name + hash
6. Return the cache

Then in `supportJsModules.js`, the `effect` handler checks the cache first. If the module is already cached, it calls `module.run()` synchronously. If not (scenarios 2/3), it falls back to async import.

**Trade-offs:**
- (+) Clean and simple for initial page load
- (+) No changes to Alpine needed
- (-) Makes `start()` async, which changes the call site (but it's called from `DOMContentLoaded` so that's fine)
- (-) Delays Alpine start while modules load (could be noticeable with many components or slow networks)
- (-) Only solves scenario 1 on its own

### Idea B: Defer Alpine init for components with pending modules (solves all scenarios)

When a Livewire component has a pending script module import, prevent Alpine from processing directives on that component's element tree until the import completes. After the module loads, re-trigger Alpine initialisation for that subtree.

**Implementation approach:**

In `lifecycle.js`'s `interceptInit` callback, after creating the Component:

```js
if (assetIsPendingFor(component)) {
    el._x_ignore = true  // prevents directive handlers from executing
    skip()               // prevents walking into children

    runAfterAssetIsLoadedFor(component, () => {
        delete el._x_ignore
        delete el._x_marker  // allow re-init
        Alpine.initTree(el)  // re-process the element tree
    })
    return
}
```

When `initTree()` re-runs, the `interceptInit` callback won't re-create the Component (because `el.__livewire` already exists), so it just processes directives normally.

**Trade-offs:**
- (+) Works for all three scenarios without needing separate mechanisms
- (+) Only delays components that actually have script modules
- (+) Other non-Livewire Alpine components init immediately
- (-) Components with script modules will briefly exist in the DOM without their Alpine state (potential flash of unstyled/non-interactive content)
- (-) Need to be careful that re-running `initTree` doesn't cause double-init of wire directives (the `directive.init` hook fires again)
- (-) The `_x_marker` deletion + re-init is somewhat hacky

### Idea C: Combine A + B (pre-import for initial load, defer for dynamic)

Use Idea A for the initial page load (pre-import all modules before `Alpine.start()`) and Idea B for dynamically added components after the initial load.

This gives the best user experience:
- Initial page load: no flash of uninitialised content (modules are ready before Alpine starts)
- Dynamic components: brief delay while module loads, but the component doesn't flash broken state because we use `_x_ignore`

**Trade-offs:**
- (+) Best UX for initial load (no flicker)
- (+) Handles all scenarios
- (-) Two mechanisms to maintain
- (-) Slightly more complex

### Idea D: Pre-import at response time (solves scenarios 2 and 3)

For AJAX responses (lazy loads and parent re-renders), pre-import any script modules referenced in the response before processing it. This is done in the request pipeline.

In `supportJsModules.js`, add a `payload.intercept` handler:

```js
on('payload.intercept', async ({ components }) => {
    let modulePromises = []

    components.forEach(({ effects }) => {
        if (effects.scriptModule) {
            // Pre-fetch and cache the module
            modulePromises.push(fetchAndCacheModule(name, effects.scriptModule))
        }
    })

    await Promise.all(modulePromises)
})
```

Since `payload.intercept` is awaited before the response is processed (line 407 of `request/index.js`), the modules would be cached before the morph triggers `initTree()`.

**The challenge:** For scenario 3 (dynamically added child components), the child's `scriptModule` effect isn't in the parent's response payload. It's embedded in the child's `wire:effects` attribute within the parent's HTML. We'd need to parse the HTML to find new child components' modules, which is fragile.

**A possible way around this:** On the PHP side, include a list of all child component script modules in the parent's response. `SupportJsModules` could collect all descendant components' module URLs during the parent's dehydrate and add them to the response payload.

### Idea E: Change module execution to not need `$wire` at registration time

Restructure how script modules work so that `Alpine.data()` registrations happen at module import time (top-level), not inside `run()`. Code that needs `$wire` stays inside `run()` or uses lazy resolution.

Current module format:
```js
export function run($wire, $js) {
    Alpine.data('name', () => ({
        someValue: $wire.entangle('foo'),  // $wire used at data-creation time
        doThing() { $wire.call('method') } // $wire used at invocation time
    }))
}
```

New module format:
```js
// Top-level: runs when module is imported
Alpine.data('name', () => ({
    someValue: /* deferred $wire resolution */,
    doThing() { this.$wire.call('method') }  // uses Alpine magic
}))

// Deferred: runs after Component is created
export function run($wire, $js) {
    // Any code that needs $wire at setup time
}
```

**Trade-offs:**
- (+) Eliminates the timing problem entirely for `Alpine.data()` registrations
- (-) Very difficult to implement; the compiler would need to parse and split user JS
- (-) Changes how `$wire` works inside data components (but `$wire` is already available as an Alpine magic on Livewire component elements, so `this.$wire` works in Alpine methods)
- (-) Breaking change for existing code patterns

### Idea F: Inject `<link rel="modulepreload">` tags from PHP (helps scenario 1)

On the PHP side, when rendering the initial page, inject `<link rel="modulepreload" href="/livewire/js/{component}.js">` tags into `<head>` for all components on the page. This tells the browser to start fetching modules immediately, before any JS runs.

This doesn't solve the problem on its own (the module still needs to be *executed*), but it can be combined with Idea A to make the pre-import resolve faster since the browser has already started downloading.

**Trade-offs:**
- (+) Browser starts downloading modules sooner
- (+) Simple PHP change
- (-) Doesn't solve the timing problem alone
- (-) Only useful for initial page load

---

## Recommended Approach: Idea C (Pre-import + Deferred Init)

The combination of pre-importing for initial load (Idea A) and deferring Alpine init for dynamic components (Idea B) provides the best balance of UX and robustness.

### Implementation outline

#### 1. Pre-import for initial page load (`lifecycle.js`)

```js
export async function start() {
    // ... dispatch events, register plugins ...

    // Pre-load all script modules before Alpine starts
    await preloadInitialScriptModules()

    Alpine.start()

    setTimeout(() => window.Livewire.initialRenderIsFinished = true)
    dispatch(document, 'livewire:initialized')
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

#### 2. Use cache in effect handler (`supportJsModules.js`)

```js
let moduleCache = new Map()

on('effect', ({ component, effects }) => {
    let hash = effects.scriptModule
    if (!hash) return

    let cacheKey = `${component.name}:${hash}`
    let cached = moduleCache.get(cacheKey)

    if (cached) {
        // Module already loaded (initial page load) - run synchronously
        cached.run.call(component.$wire, component.$wire, component.$wire.js)
        return
    }

    // Module not cached (dynamic/lazy) - load async with deferred init
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

#### 3. Deferred init for dynamic components (`lifecycle.js`)

In the `interceptInit` callback, after creating the Component:

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
            delete el._x_marker
            Alpine.initTree(el)
        })
        return
    }
}
```

### Open questions

1. **Lazy component modules: preload or defer?** Option A: preload on page load for faster UX when they become visible. Option B: wait until lazy load fires (less upfront network). The placeholder snapshot does contain the component name, so we *could* preload. But if the user never scrolls to a lazy component, we'd have loaded a module for nothing.

2. **`wire:navigate` (SPA navigation):** When navigating to a new page, the new page's HTML replaces the current page. Do we need to pre-import the new page's script modules before Alpine processes the new DOM? The navigate plugin stops/starts Alpine, so there may be a natural hook point.

3. **Double-init of wire directives:** When using the deferred init approach (Idea B), `initTree()` re-runs after the module loads. The `interceptInit` callback won't re-create the Component (checked by `el.__livewire`), but it will re-trigger `directive.init` for all wire directives. Are there guards against this, or do we need to add them?

4. **Flash of content:** With the deferred init approach, a dynamically added component's HTML will be in the DOM but Alpine won't have processed it yet. The user might briefly see `x-show="false"` content visible, `x-text` expressions as raw text, etc. Should we add a `wire:cloak`-like mechanism to hide the component until its module loads? Or rely on Alpine's existing `x-cloak`?

5. **Error handling:** What if a module fails to load (network error, 404)? Currently the `import()` promise would reject silently. We should handle this gracefully, perhaps logging a warning and still allowing the component to init (without the module's functionality).
