# Alpine.data() Script Module Loading: Options

## The Problem

Livewire v4 SFC/MFC components can have co-located JavaScript files. In SFCs, this is a `<script>` block; in MFCs, it's a separate `.js` file alongside the component class. These are compiled into ES modules served from `/livewire/js/{component}.js` and loaded dynamically via `import()`.

Each script module typically contains `Alpine.data()` registrations:

```js
// /livewire/js/counter.js
export function run($wire) {
    Alpine.data('counter', () => ({
        count: 0,
        increment() { this.count++ }
    }))
}
```

The component's Blade template references this registration via `x-data`:

```blade
<div x-data="counter">
    <span x-text="count"></span>
    <button @click="increment">+</button>
</div>
```

**The core issue: `import()` is asynchronous, but Alpine processes `x-data` synchronously.** When Alpine encounters `x-data="counter"`, it looks up "counter" in its registered data components. If the module hasn't loaded yet, "counter" isn't registered, and JavaScript throws a ReferenceError: "Can't find variable: counter".

This is different from `@script`/`@endscript`, which stores script content inline in `wire:effects` and evaluates it synchronously during the same effect hook. Script modules use dynamic `import()` because they're separate files served from a URL.

### How it works today

On the PHP side, `SupportJsModules.php` adds a `scriptModule` effect (a hash of the file's modification time) during component dehydration. This hash is included in the component's `wire:effects` attribute in the HTML.

On the JS side, `supportJsModules.js` listens to the `effect` hook:

```js
on('effect', ({ component, effects }) => {
    let scriptModuleHash = effects.scriptModule

    if (scriptModuleHash) {
        let path = `${getModuleUrl()}/js/${encodedName}.js?v=${scriptModuleHash}`

        pendingComponentAssets.set(component, Alpine.reactive({
            loading: true,
            afterLoaded: [],
        }))

        import(path).then(module => {
            module.run.call(component.$wire, component.$wire, component.$wire.js)

            pendingComponentAssets.get(component).loading = false
            pendingComponentAssets.get(component).afterLoaded.forEach(callback => callback())
            pendingComponentAssets.delete(component)
        })
    }
})
```

The effect hook fires during `processEffects()` in the Component constructor. But `import()` returns a promise; by the time it resolves, Alpine has already tried to evaluate `x-data="counter"` and failed.

### The three scenarios

A complete solution must handle all three scenarios. In each case, the script module must be loaded and its `Alpine.data()` registration executed before Alpine evaluates `x-data` on that component.

**Scenario 1: Initial page load.** Components with script modules are present in the server-rendered HTML. Their modules must be loaded before `Alpine.start()` processes `x-data` on those components.

**Scenario 2: Lazy/deferred components.** A lazy component renders a placeholder first. The real component (with its script module) arrives via AJAX. The module must be loaded before the morph replaces the placeholder with the real component.

**Scenario 3: Dynamically added components.** A parent re-renders and its new HTML includes a child component that wasn't there before. The child's module must be loaded before the morph inserts the child into the DOM and Alpine initialises it. The parent's loading indicators (`wire:loading`) must remain visible until the child's module is ready.

### Why loading state matters

Solving the race condition isn't just about preventing crashes; modules need to be ready before the user sees the component.

For scenario 1 (initial page load), this is straightforward. The page is loading anyway; the user expects to wait for Livewire to initialise. Loading modules before calling `Alpine.start()` adds a small delay to that process, but the user is already waiting for the page to become interactive. No special loading state management is needed.

For scenarios 2 and 3, loading state is critical. In both cases, the user is looking at the page and waiting for something to appear. There's existing UI that communicates "something is happening": lazy placeholders for scenario 2, and `wire:loading` indicators on the parent for scenario 3. The morph is what removes this UI: it replaces the placeholder with the real component (scenario 2) or inserts the new child and clears loading indicators (scenario 3). If the morph applies before modules are ready, that UI disappears and the user sees broken, uninitialised content in its place.

The goal is to keep placeholders and loading indicators visible while modules load, then apply the morph once everything is ready. The user sees a continuous loading experience: placeholder or spinner, then the fully initialised component. No flash of broken content.

### Where module loading currently sits in the request pipeline

When a Livewire AJAX response arrives, it's processed through a series of hooks inside an `Alpine.transaction`:

```
onSuccess → onSync → processEffects → onEffect → onMorph → onFinish → onRender
```

Key hooks:

- **`processEffects`** is where module loading currently happens. The `effect` hook in `supportJsModules.js` fires and starts an async `import()`. The promise resolves sometime later.
- **`onEffect`** is where `wire:loading` clears its indicators (`wire-loading.js:106-112`). Once `onEffect` runs, loading spinners disappear.
- **`onMorph`** is where the DOM is updated. The morph replaces placeholders and inserts new children.

By the time the module's `import()` resolves, `onEffect` and `onMorph` have already run. Loading indicators are gone, the DOM has been updated, and the module arrives after the user has already seen broken content.

### What a complete solution needs

1. **Scenario 1 (initial load):** Modules must be loaded before Alpine processes `x-data` on page load.

2. **Scenario 2 (lazy components):** Modules must be loaded before the morph replaces the lazy placeholder with the real component.

3. **Scenario 3 (dynamic children):** Modules must be loaded before the parent's loading indicators are cleared and the morph inserts the new child.

4. **Error handling:** If a module fails to load (network error, 404), the component should still initialise without the module's functionality. A partially working component is better than one that never appears. Log a console warning and continue.

---

## Solution A: Livewire-Only (`_x_ignore` + `onPrepare`)

No Alpine changes. All three scenarios handled in Livewire.

**Scenario 1 (initial load):** Make `start()` in `lifecycle.js` async. Before calling `Alpine.start()`, scan all `[wire:id]` elements on the page, parse their `wire:effects` for `scriptModule` hashes, `import()` all modules in parallel, and store them in a shared module cache. When `Alpine.start()` runs and the `effect` hook fires for each component, the module is already cached and `module.run()` executes synchronously.

**Scenarios 2 and 3 (AJAX responses):** Add a new awaitable `onPrepare` hook to Livewire's message interceptor system, positioned between `onSync` and `processEffects`:

```
onSync → onPrepare (awaited) → processEffects → onEffect → onMorph
```

> A new hook is needed here because `onSync` is currently synchronous. Making `onSync` awaitable would be a breaking change for applications that expect it to execute synchronously. `onPrepare` is a new, dedicated async hook that doesn't change existing behaviour.

A handler in `supportJsModules.js` uses `onPrepare` to scan the response payload: the component's own `effects.scriptModule` and any child components found in `effects.html` (parsed via an inert `<template>` element, querying for `[wire:effects]` elements). All discovered modules are imported and cached before `processEffects` runs. Because `onPrepare` runs before `onEffect`, loading indicators and placeholders remain visible while modules load. By the time effects are processed, modules are cached and execute synchronously.

**Shared module cache:** Both mechanisms (initial pre-load and `onPrepare`) share a single `Map` in `supportJsModules.js`, keyed by component name + hash. The `effect` handler checks the cache first; if the module is cached, it runs synchronously and never creates a pending asset entry.

| Files changed | What changes |
|---------------|-------------|
| `supportJsModules.js` | Module cache, `preloadInitialModules()` export, `onPrepare` handler, updated `effect` handler with cache check and error handling |
| `lifecycle.js` | Async `start()` with `await preloadInitialModules()` |
| `interceptor.js` | Add `onPrepare` hook to `MessageInterceptor` |
| `message.js` | Add `invokeOnPrepare()`, expose `onPrepare` in `invokeOnSuccess` |
| `request/index.js` | `await message.invokeOnPrepare()` between `invokeOnSync` and `processEffects` |

**Alpine changes:** None.

**Trade-offs:**
- (+) No Alpine changes required
- (+) No breaking changes to existing hooks
- (+) `onPrepare` follows the existing `interceptMessage` pattern used by `supportMorphDom`, `wire-loading`, etc.
- (+) Correct `wire:loading` and placeholder timing
- (-) Adds a new lifecycle hook (`onPrepare`) to the message interceptor API

---

## Solution B: Minimal Fix (deferred init only)

The smallest possible change. No pre-loading, no new hooks, no module cache. Just defer Alpine's initialisation of any component whose module is still loading, and re-initialise when it arrives.

In `lifecycle.js`'s `interceptInit`, after creating a Component, check if its module is still loading and defer if so:

```js
let component = initComponent(el)

if (assetIsPendingFor(component)) {
    el._x_ignore = true
    skip()

    runAfterAssetIsLoadedFor(component, () => {
        if (!el.isConnected) return
        delete el._x_ignore
        Alpine.initTree(el)
    })

    return
}
```

No double-init occurs because `_x_marker` is never set when `_x_ignore` is truthy (Alpine's `initTree` only assigns `_x_marker` when `_x_ignore` is falsy), so re-init proceeds as if the element is new. The `interceptInit` callback skips Component creation on re-init because `el.__livewire` already exists.

This stops all crashes across all three scenarios. However, it doesn't solve the user experience:

- **Scenario 1:** Components flash as uninitialised HTML until their module loads, then re-init and become interactive.
- **Scenarios 2 and 3:** The morph applies immediately (removing placeholders and clearing loading indicators), then the child component sits in the DOM uninitialised until its module loads and re-init fires. The user sees a brief flash of inert content.

| Files changed | What changes |
|---------------|-------------|
| `lifecycle.js` | ~15 lines in `interceptInit`: check `assetIsPendingFor()`, set `_x_ignore`, register re-init callback |

**Alpine changes:** None.

**Trade-offs:**
- (+) Smallest possible change; single file, ~15 lines
- (+) No new hooks, no module cache, no pre-loading logic
- (+) Stops all crashes immediately
- (-) FOUC on all three scenarios
- (-) Loading indicators and placeholders are not preserved (scenarios 2 and 3)
- (-) Uses Alpine's internal `_x_ignore` flag (undocumented; mitigated by maintaining both projects)

---

## Solution C: Livewire-Only, No New Hooks (`_x_ignore` + awaitable `onSync`)

Same as Solution A, but instead of adding a new `onPrepare` hook, makes the existing `onSync` hook awaitable. `onSync` already runs between snapshot merging and `processEffects`, which is exactly where module pre-loading needs to happen. The module pre-loading handler registers on `onSync` instead of `onPrepare`.

The only internal user of `onSync` is `supportPreserveScroll.js`, which is synchronous and unaffected by the change.

| Files changed | What changes |
|---------------|-------------|
| `supportJsModules.js` | Same as Solution A (module cache, pre-load handler, etc.) but handler uses `onSync` |
| `lifecycle.js` | Same as Solution A (async `start()`, safety net) |
| `interceptor.js` | Change `onSync` default to `async () => {}` |
| `message.js` | Make `invokeOnSync()` async with `Promise.all` |
| `request/index.js` | `await message.invokeOnSync()` |

**Alpine changes:** None.

**Trade-offs:**
- (+) No Alpine changes
- (+) No new lifecycle hooks (smallest API surface change)
- (+) Correct `wire:loading` and placeholder timing
- (-) **Breaking change.** `onSync` is currently synchronous. User-land code using `onSync` may not expect it to be awaited. Making a sync hook async is a subtle contract change that could affect timing expectations.
- (-) Changes the documented contract of `onSync`

---

## Recommendation

**Solution A (Livewire-only, `_x_ignore` + `onPrepare`)** is the strongest option.

It solves all three scenarios with correct loading/placeholder timing, requires no Alpine changes, and introduces no breaking changes. The new `onPrepare` hook is a small, focused addition that follows the exact same pattern as every other hook in the interceptor system (`onSync`, `onEffect`, `onMorph`).

Solution B's `_x_defer` is elegant, but the placement subtlety (must run after `initInterceptors`) adds complexity that doesn't justify the benefit. The safety net is a fallback path that should rarely fire; making it one line shorter isn't worth an Alpine API change.

Solution C avoids a new hook but introduces a breaking change to `onSync`. Even though the only internal user is synchronous, changing a hook from sync to async is the kind of subtle contract change that bites user-land code.

**Build order for Solution A:**

1. **Safety net first** (~15 lines in `lifecycle.js`, ~5 in `supportJsModules.js`). This stops all crashes immediately. Components with pending modules defer their init and re-init when the module loads. FOUC on first load, but no errors.

2. **`onPrepare` hook + handler** (~30 lines across `interceptor.js`, `message.js`, `request/index.js`, `supportJsModules.js`). Scenarios 2 and 3 fully solved with correct loading timing.

3. **Pre-import for initial load** (~25 lines in `lifecycle.js` and `supportJsModules.js`). Scenario 1 fully solved. No FOUC on first load.

Each step is independently shippable and testable.
