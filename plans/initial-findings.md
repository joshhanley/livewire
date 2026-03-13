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
        Alpine.initTree(el)  // re-process the element tree
    })
    return
}
```

When `initTree()` re-runs, the `interceptInit` callback won't re-create the Component (because `el.__livewire` already exists), so it just processes directives normally.

**Why there's no double-init problem:**

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

**Trade-offs:**
- (+) Works for all three scenarios without needing separate mechanisms
- (+) Only delays components that actually have script modules
- (+) Other non-Livewire Alpine components init immediately
- (+) No double-init problem (verified through code analysis)
- (-) Components with script modules will briefly exist in the DOM without their Alpine state (potential flash of unstyled/non-interactive content)
- (-) For scenario 3 (dynamically added components), the parent's morph has already applied (loading states removed, new HTML visible) while the child's module is still loading. The user sees broken/inert child content mid-interaction. This means Idea B alone is not sufficient for dynamic components; it works as a safety net but needs to be paired with a morph delay mechanism.

### Idea C: Combine A + B (pre-import for initial load, defer for dynamic)

Use Idea A for the initial page load (pre-import all modules before `Alpine.start()`) and Idea B for dynamically added components after the initial load.

This gives better UX for the initial page load:
- Initial page load: no flash of uninitialised content (modules are ready before Alpine starts)
- Dynamic components: Idea B defers the child's Alpine init until its module loads

**Trade-offs:**
- (+) Best UX for initial load (no flicker)
- (+) Handles all scenarios
- (-) Two mechanisms to maintain
- (-) Slightly more complex
- (-) Still has Idea B's scenario 3 problem: the parent's morph applies before the child's module loads, so the user sees broken/inert child content mid-interaction. Would need to be paired with the morph delay mechanism (see "Delaying the Morph Until Modules Are Ready") to fully solve this.

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

---

## Possible Enhancement: `<link rel="modulepreload">` Tags

Separately from the fix itself, injecting `<link rel="modulepreload" href="/livewire/js/{component}.js">` tags into `<head>` from PHP would tell the browser to start fetching modules immediately, before any JS runs. This makes other solutions (especially Idea A) resolve faster since the browser has already started (or finished) downloading the modules by the time `import()` is called.

`rel="modulepreload"` does work when dynamically added to the DOM (not just in the initial HTML). This has been verified.

This also works well with `wire:navigate`. The navigate plugin's `mergeNewHead` function swaps in the new page's `<head>` elements. `<link rel="modulepreload">` tags don't match navigate's `isAsset()` check (which only looks for stylesheets, styles, and scripts), so they're treated as non-asset elements: old ones are removed, new ones are appended. The browser starts preloading as soon as the link is appended to `<head>`, so modules for the new page begin downloading immediately.

This is an enhancement, not a fix on its own (the module still needs to be *executed*). It could be implemented alongside any of the solution ideas above.

---

## Delaying the Morph Until Modules Are Ready

Regardless of which solution idea(s) are chosen for the core fix, scenarios 2 and 3 need the morph to wait until modules are loaded. Critically, delaying the morph means the parent retains its **loading state** (e.g. `wire:loading` indicators remain visible for scenario 3, or the lazy placeholder stays visible for scenario 2) until all child component modules are ready. Without the delay, the morph applies immediately, loading states/placeholders are removed, and the user sees broken/inert child content. With the delay, the user sees a continuous loading experience: loading indicators or placeholders stay visible, modules load, then the morph applies and everything appears fully initialised at once.

**Why not `x-cloak` or `wire:cloak`?** Neither is a substitute for delaying the morph. `wire:cloak` removes itself immediately in its own `interceptInit` callback when the element is first seen, so it would be gone before the module even starts loading. `x-cloak` is removed by the `x-data` directive handler, so with deferred init (Idea B) it would stay until re-init, but this only hides the child element's content; it doesn't prevent the parent's loading state from being removed by the morph. More fundamentally, both require the user to add an attribute to every component with a script module, which shouldn't be necessary. The morph delay solves this at the framework level.

The module loading concern should stay in `supportJsModules.js`, not bleed into `morph.js`. The `interceptMessage` pattern (same one `supportMorphDom.js` uses) is the right way to hook into the request lifecycle.

The challenge is ordering: we need module loading to complete **before** the morph runs, but both would use the message lifecycle hooks. The available hooks in order are:

```
onSuccess → onSync → onEffect → onMorph → onFinish → onRender
```

The morph runs in `onMorph`. Currently only `onMorph` is awaited; `onSync` and `onEffect` are not. Several options exist:

### Option A: Make `onEffect` awaitable

Currently `invokeOnEffect()` is not awaited (line 435 in `request/index.js`). If we make it awaitable, `supportJsModules.js` could use `onEffect` to wait for pending modules. The `processEffects` call happens before `invokeOnEffect` (line 433), so the async `import()` is already in flight by the time `onEffect` fires. The handler just waits for it to resolve.

**Trade-offs:**
- (+) Uses existing hook, semantically correct ("after effects processed, wait for them to settle")
- (+) Keeps module logic in `supportJsModules.js`
- (-) Making `onEffect` async could have unintended consequences for other `onEffect` listeners
- (-) Changes the documented hook contract (though the docs describe `onEffect` as "After effects processed" which is vague)

### Option B: Make `invokeOnMorph` run callbacks sequentially

Change `invokeOnMorph` from `Promise.all` to sequential execution (in registration order). Then ensure `supportJsModules.js` registers its `onMorph` callback before `supportMorphDom.js` by importing it first in `features/index.js`.

**Trade-offs:**
- (+) No new hooks needed
- (-) Relies on import order in `features/index.js`, which is fragile
- (-) Sequential execution of all `onMorph` callbacks could be slower than parallel
- (-) Semantically confusing: the module loading step isn't really "morphing"

### Option C: Add a dedicated `onBeforeMorph` hook

Add a new awaited hook that runs between `onEffect` and `onMorph`:

```
onSuccess → onSync → onEffect → onBeforeMorph → onMorph → onFinish → onRender
```

**Trade-offs:**
- (+) Explicit, self-documenting, no ordering ambiguity
- (+) Clean separation of concerns
- (-) Adds a new hook to the public API / lifecycle
- (-) More code to maintain

### Important: `wire:loading` timing

Options A, B, and C above all place module loading *after* `processEffects`. However, `wire:loading` clears its loading state in `onEffect` (see `wire-loading.js:106-112`), which runs after `processEffects` but before `onMorph`. This means with any of those options, the loading indicator disappears before the morph, leaving a visible gap where neither loading indicators nor the new content are shown.

To preserve loading state while modules load, the async work needs to happen *before* `processEffects` runs. The following two options achieve this by placing module loading between `onSync` and `processEffects`:

### Option D: Make `onSync` awaitable

Make `invokeOnSync` async (currently synchronous). `supportJsModules.js` uses `onSync` to read the response payload's `effects.scriptModule` and `effects.html`, pre-load all modules (including child components found in the HTML), and await them. By the time `processEffects` runs, modules are cached and execute synchronously. `wire:loading` clears in `onEffect` as normal, right before the morph.

The only existing `onSync` user is `supportPreserveScroll`, which is synchronous, so awaiting it is a no-op.

```
onSync (awaited) → processEffects → onEffect (loading clears) → onMorph
```

**Trade-offs:**
- (+) No new hooks, smallest API surface change
- (+) Correct loading timing: loading persists while modules load
- (+) Docs describe `onSync` as "After state merged/synced" which fits (state is merged, now prepare async dependencies)
- (-) Breaking change: user-land `onSync` callbacks currently execute synchronously; making the hook awaitable could change timing expectations for existing code

### Option E: Add a dedicated `onPrepare` hook

Add a new awaited hook between `onSync` and `processEffects`:

```
onSync → onPrepare (awaited) → processEffects → onEffect (loading clears) → onMorph
```

**Trade-offs:**
- (+) Explicit, self-documenting
- (+) Correct loading timing
- (+) Doesn't change existing hook behaviour
- (-) Adds a new hook to the public API / lifecycle
- (-) More code to maintain

---

## Resolved Questions

### Lazy component modules: defer, don't preload

Lazy components are lazy for a reason; the user has explicitly said "don't load this until needed." Loading their JS module eagerly contradicts that intent and wastes network if the user never scrolls to the component. When the lazy load AJAX response arrives, the module is handled by the morph delay mechanism and the deferred init safety net.

### `wire:navigate`: no special handling needed

Navigate calls `Alpine.initTree(document.body)` on the new page, which is the same code path as `Alpine.start()`. The deferred init safety net (Idea B) handles any components with pending modules automatically. If `<link rel="modulepreload">` tags are implemented (Idea F), navigate's head merge swaps them in and the browser starts preloading immediately.

**Important:** Navigate needs to be tested after implementation to verify it all works correctly.

### Flash of content: morph waits, loading state persists

The morph should delay until all child component modules are loaded. This means the parent's loading state naturally persists: `wire:loading` indicators stay visible (scenario 3) and lazy placeholders remain in the DOM (scenario 2) until modules are ready. The morph then applies and everything appears fully initialised at once. See "Delaying the Morph Until Modules Are Ready" section above for the options on how to achieve this.

### Error handling: catch, warn, continue

If a module fails to load (network error, 404), the component should still initialise without its script module's functionality. A partially working component is better than one that never appears.

- **Pre-import (Idea A):** Catch per-module errors. One failed module should not block other components from initialising. Log a console warning.
- **Deferred init (Idea B):** If the module fails, remove `_x_ignore` and let the component init without the module. Log a console warning.
- **Morph delay:** Don't block the morph forever. Log a console warning and proceed.
