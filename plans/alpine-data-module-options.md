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

No Alpine changes. Uses Alpine's existing internal `_x_ignore` flag for the safety net and adds a new `onPrepare` lifecycle hook to Livewire's message interceptor system for pre-loading modules during AJAX responses.

### Part 1: Initial page load (pre-import before `Alpine.start()`)

Make `start()` in `lifecycle.js` async. Before calling `Alpine.start()`, scan the DOM for all Livewire components with script modules, import them all, and cache them.

**Changes to `lifecycle.js`:**

```js
export async function start() {
    // ... existing plugin registration ...

    await preloadInitialModules()

    Alpine.start()

    // ...
}
```

**Changes to `supportJsModules.js`** (new export):

```js
let moduleCache = new Map()

export async function preloadInitialModules() {
    let els = document.querySelectorAll('[wire\\:id][wire\\:effects]')
    let imports = []

    els.forEach(el => {
        let effects = JSON.parse(el.getAttribute('wire:effects'))
        if (!effects.scriptModule) return

        let snapshot = JSON.parse(el.getAttribute('wire:snapshot'))
        let name = snapshot.memo.name
        let hash = effects.scriptModule
        let cacheKey = name + ':' + hash

        if (moduleCache.has(cacheKey)) return

        let encodedName = name.replace(/\./g, '--').replace(/::/g, '---').replace(/:/g, '----')
        let path = `${getModuleUrl()}/js/${encodedName}.js?v=${hash}`

        imports.push(
            import(path)
                .then(module => moduleCache.set(cacheKey, module))
                .catch(e => console.warn(`Livewire: failed to preload module for [${name}]`, e))
        )
    })

    await Promise.allSettled(imports)
}
```

The existing `effect` handler is updated to check the cache first:

```js
on('effect', ({ component, effects }) => {
    let scriptModuleHash = effects.scriptModule
    if (!scriptModuleHash) return

    let cacheKey = component.name + ':' + scriptModuleHash

    if (moduleCache.has(cacheKey)) {
        // Module was pre-loaded, run it synchronously
        let module = moduleCache.get(cacheKey)
        module.run.call(component.$wire, component.$wire, component.$wire.js)
        return
    }

    // Fallback: module wasn't pre-loaded, load async (safety net will catch this)
    let encodedName = component.name.replace(/\./g, '--').replace(/::/g, '---').replace(/:/g, '----')
    let path = `${getModuleUrl()}/js/${encodedName}.js?v=${scriptModuleHash}`

    pendingComponentAssets.set(component, Alpine.reactive({
        loading: true,
        afterLoaded: [],
    }))

    import(path)
        .then(module => {
            moduleCache.set(cacheKey, module)
            module.run.call(component.$wire, component.$wire, component.$wire.js)

            pendingComponentAssets.get(component).loading = false
            pendingComponentAssets.get(component).afterLoaded.forEach(callback => callback())
            pendingComponentAssets.delete(component)
        })
        .catch(e => {
            console.warn(`Livewire: failed to load module for [${component.name}]`, e)
            if (pendingComponentAssets.has(component)) {
                pendingComponentAssets.get(component).loading = false
                pendingComponentAssets.get(component).afterLoaded.forEach(callback => callback())
                pendingComponentAssets.delete(component)
            }
        })
})
```

When modules are pre-loaded, the effect handler finds them in the cache and runs them synchronously. `Alpine.data()` is registered before `x-data` is processed. No race condition.

### Part 2: AJAX responses (new `onPrepare` hook)

Add a new awaitable `onPrepare` hook to Livewire's message interceptor system. This hook runs between `onSync` and `processEffects`:

```
onSync → onPrepare (awaited) → processEffects → onEffect → onMorph
```

**Changes to `interceptor.js`:**

Add `onPrepare` to the `MessageInterceptor` class:

```js
export class MessageInterceptor {
    // ... existing hooks ...
    onPrepare = async () => {}
    // ...
}
```

Expose it in the constructor callback:

```js
this.callback({
    // ... existing hooks ...
    onPrepare: (callback) => this.onPrepare = callback,
    // ...
})
```

**Changes to `message.js`:**

Add the invocation method:

```js
async invokeOnPrepare() {
    await Promise.all(
        this.interceptors.map(interceptor => interceptor.onPrepare())
    )
}
```

Expose it in `invokeOnSuccess`:

```js
invokeOnSuccess() {
    this.interceptors.forEach(interceptor => {
        interceptor.onSuccess({
            payload: this.responsePayload,
            onSync: callback => interceptor.onSync = callback,
            onPrepare: callback => interceptor.onPrepare = callback,  // NEW
            onEffect: callback => interceptor.onEffect = callback,
            onMorph: callback => interceptor.onMorph = callback,
            onRender: callback => interceptor.onRender = callback
        })
    })
    // ...
}
```

**Changes to `request/index.js`:**

Insert the awaited call between `invokeOnSync` and `processEffects`:

```js
Alpine.transaction(async () => {
    message.component.mergeNewSnapshot(snapshotEncoded, effects, message.updates)

    message.invokeOnSync()
    if (message.isCancelled()) return

    await message.invokeOnPrepare()  // NEW
    if (message.isCancelled()) return

    message.component.processEffects(effects, request)

    message.invokeOnEffect()
    // ...
})
```

**Changes to `supportJsModules.js`** (using `onPrepare`):

```js
interceptMessage(({ message, onSuccess }) => {
    onSuccess(({ payload, onPrepare }) => {
        onPrepare(async () => {
            let imports = []

            // Pre-load the component's own module
            let hash = payload.effects.scriptModule
            if (hash) {
                imports.push(preloadModule(message.component.name, hash))
            }

            // Pre-load child component modules found in the response HTML
            let html = payload.effects.html
            if (html) {
                let template = document.createElement('template')
                template.innerHTML = html

                template.content.querySelectorAll('[wire\\:effects]').forEach(el => {
                    let childEffects = JSON.parse(el.getAttribute('wire:effects'))
                    if (!childEffects.scriptModule) return

                    let childSnapshot = JSON.parse(el.getAttribute('wire:snapshot'))
                    let childName = childSnapshot.memo.name

                    imports.push(preloadModule(childName, childEffects.scriptModule))
                })
            }

            await Promise.allSettled(imports)
        })
    })
})
```

The `preloadModule` helper imports the module and stores it in the shared `moduleCache`:

```js
function preloadModule(name, hash) {
    let cacheKey = name + ':' + hash
    if (moduleCache.has(cacheKey)) return Promise.resolve()

    let encodedName = name.replace(/\./g, '--').replace(/::/g, '---').replace(/:/g, '----')
    let path = `${getModuleUrl()}/js/${encodedName}.js?v=${hash}`

    return import(path)
        .then(module => moduleCache.set(cacheKey, module))
        .catch(e => console.warn(`Livewire: failed to preload module for [${name}]`, e))
}
```

Because `onPrepare` runs before `processEffects`, the effect handler finds modules in the cache and runs them synchronously. `wire:loading` stays visible during the `onPrepare` phase (it doesn't clear until `onEffect`), so the user sees a continuous loading experience.

### Part 3: Safety net (`_x_ignore`)

If a module is somehow not pre-loaded (an edge case we haven't anticipated), defer the component's Alpine initialisation until the module loads.

**Changes to `lifecycle.js`:**

In the `interceptInit` callback, after creating the Component. Note: the callback signature changes from `el =>` to `(el, skip) =>` to access the walker's skip function (Alpine's `skipDuringClone` passes all arguments through via `...args`):

```js
if (el.hasAttribute('wire:id') && !el.__livewire && !hasComponent(el.getAttribute('wire:id'))) {
    let component = initComponent(el)

    // Safety net: defer Alpine init if module is still loading
    if (assetIsPendingFor(component)) {
        el._x_ignore = true
        skip()

        runAfterAssetIsLoadedFor(component, () => {
            if (!el.isConnected) {
                destroyComponent(component.id)
                return
            }

            delete el._x_ignore
            Alpine.initTree(el)
        })

        Alpine.onAttributeRemoved(el, 'wire:id', () => {
            destroyComponent(component.id)
        })

        return
    }

    Alpine.onAttributeRemoved(el, 'wire:id', () => {
        destroyComponent(component.id)
    })
}
```

**How `_x_ignore` prevents double-init:**

On the first pass (module pending):
- `interceptInit` creates the Component, detects the pending asset, sets `el._x_ignore = true`
- `skip()` prevents the walker from visiting child elements
- The early `return` prevents directive hooks from firing
- Alpine's `initTree` only assigns `_x_marker` when `_x_ignore` is falsy, so no marker is set
- Directive handlers that were collected for this element check `_x_ignore` at execution time and early-return

On re-init (module loaded, `_x_ignore` deleted):
- `initTree(el)` is called; no `_x_marker` exists, so it proceeds
- `interceptInit` fires but skips Component creation because `el.__livewire` already exists
- Directive hooks (`directive.global.init`, `directive.init`) fire for the first time
- Children are visited for the first time
- `x-data` evaluates and finds the registered data component

Everything fires exactly once.

### Summary

| Piece | What it does | Files changed |
|-------|-------------|---------------|
| Pre-import (initial load) | `await import()` all modules before `Alpine.start()` | `lifecycle.js`, `supportJsModules.js` |
| `onPrepare` hook | New awaitable hook between `onSync` and `processEffects` | `interceptor.js`, `message.js`, `request/index.js` |
| `onPrepare` handler | Pre-loads modules from response before processing | `supportJsModules.js` |
| Safety net | Defers Alpine init via `_x_ignore` when module is pending | `lifecycle.js` |
| Module cache | Shared cache keyed by component name + hash | `supportJsModules.js` |

**Alpine changes:** None.

**Trade-offs:**
- (+) No Alpine changes required
- (+) `_x_ignore` is well-understood and stable; we maintain both projects
- (+) `onPrepare` follows the existing `interceptMessage` pattern used by `supportMorphDom`, `wire-loading`, etc.
- (+) Correct `wire:loading` timing (loading persists while modules load)
- (-) Adds a new lifecycle hook (`onPrepare`) to the message interceptor API
- (-) Uses Alpine's internal `_x_ignore` flag (undocumented), which could break if Alpine changes its semantics (mitigated by maintaining both projects)
- (-) Safety net re-init logic lives in Livewire (`runAfterAssetIsLoadedFor` callback with `isConnected` guard, manual `delete _x_ignore`, manual `initTree(el)` call)

---

## Solution B: Alpine + Livewire (`_x_defer` + `onPrepare`)

Small Alpine change: add native promise-based deferred initialisation (`_x_defer`). Same Livewire changes as Solution A for initial page load and AJAX pre-loading, but a simpler safety net.

### Part 1: Initial page load

Identical to Solution A, Part 1. `preloadInitialModules()` runs before `Alpine.start()`.

### Part 2: AJAX responses

Identical to Solution A, Part 2. New `onPrepare` hook, same interceptor handler, same module cache.

### Part 3: Safety net (`_x_defer`)

Instead of manually managing `_x_ignore`, `skip()`, `runAfterAssetIsLoadedFor`, and `initTree(el)`, Livewire sets a promise on the element and Alpine handles everything else.

**Changes to Alpine's `lifecycle.js`:**

The `_x_defer` check must be placed *after* `initInterceptors` in the walker. This is important: Livewire's `interceptInit` callback is what creates the Component (which triggers `processEffects()` and starts the async `import()`). If the `_x_defer` check ran before interceptors, the Component would never be created and the import would never start. The correct placement:

```js
walker(el, (el, skip) => {
    if (el._x_marker) return

    intercept(el, skip)

    initInterceptors.forEach(i => i(el, skip))

    // Check for deferred init AFTER interceptors have run
    // (interceptors like Livewire's interceptInit set _x_defer during their execution)
    if (el._x_defer) {
        el._x_defer.then(() => {
            delete el._x_defer
            if (!el.isConnected) return
            initTree(el)
        }).catch(() => {
            delete el._x_defer
            if (!el.isConnected) return
            console.warn('Alpine: deferred init failed, initialising without waiting')
            initTree(el)
        })
        skip()
        return
    }

    directives(el, el.attributes).forEach(handle => handle())

    if (!el._x_ignore) el._x_marker = markerDispenser++
    el._x_ignore && skip()
})
```

The flow for a Livewire component with a pending module:

1. `initInterceptors.forEach(...)` runs Livewire's `interceptInit`
2. `interceptInit` creates the Component, which calls `processEffects()`, which starts the async `import()`
3. `interceptInit` detects the pending asset, sets `el._x_defer` to the module's promise, calls `skip()`, and returns early (before Livewire's directive processing section)
4. Back in the walker, `_x_defer` is now set
5. Alpine sees the promise, queues re-init for when it resolves, calls `skip()`, and returns
6. Directives are never collected (the `return` on step 5 exits the walker callback before line 103)
7. `_x_marker` is never set (same reason)

This is slightly cleaner than Solution A's `_x_ignore` approach. With `_x_ignore`, directives ARE collected by the walker but early-return when flushed (they check `_x_ignore` at execution time). With `_x_defer`, directives are never collected at all because the walker returns before reaching that line.

**Changes to Livewire's `lifecycle.js`:**

The `interceptInit` callback needs to return early before the directive processing section (lines 72-91) when a module is pending. Note: the callback signature changes from `el =>` to `(el, skip) =>` to access the walker's skip function (Alpine's `skipDuringClone` passes all arguments through via `...args`):

```js
Alpine.interceptInit(
    Alpine.skipDuringClone((el, skip) => {
        if (!Array.from(el.attributes).some(attribute => matchesForLivewireDirective(attribute.name))) return

        if (el.hasAttribute('wire:id') && !el.__livewire && !hasComponent(el.getAttribute('wire:id'))) {
            let component = initComponent(el)

            Alpine.onAttributeRemoved(el, 'wire:id', () => {
                destroyComponent(component.id)
            })

            // Safety net: defer Alpine init if module is still loading
            if (assetIsPendingFor(component)) {
                el._x_defer = getAssetPromiseFor(component)
                skip()
                return  // Skip directive processing; Alpine will re-init when promise resolves
            }
        }

        // ... directive processing (only runs if not deferred) ...
    })
)
```

`supportJsModules.js` needs to expose the module promise:

```js
export function getAssetPromiseFor(component) {
    if (!pendingComponentAssets.has(component)) return Promise.resolve()

    let asset = pendingComponentAssets.get(component)
    if (!asset.loading) return Promise.resolve()

    return new Promise(resolve => {
        asset.afterLoaded.push(resolve)
    })
}
```

On re-init (promise resolved):
- Alpine calls `initTree(el)`, no `_x_marker` exists, so it proceeds
- `interceptInit` fires but `el.__livewire` exists, so Component creation is skipped
- `assetIsPendingFor(component)` is false, so `_x_defer` is not set
- Livewire's directive processing (lines 72-91) runs for the first time
- Back in the walker, `_x_defer` is not set, so directives are processed normally
- `x-data` evaluates and finds the registered data component

Everything fires exactly once. Same result as Solution A's `_x_ignore` approach, but Alpine manages the waiting and re-init.

### Summary

| Piece | What it does | Files changed |
|-------|-------------|---------------|
| Pre-import (initial load) | Same as Solution A | `lifecycle.js`, `supportJsModules.js` |
| `onPrepare` hook | Same as Solution A | `interceptor.js`, `message.js`, `request/index.js` |
| `onPrepare` handler | Same as Solution A | `supportJsModules.js` |
| Safety net | `_x_defer` promise on element; Alpine handles re-init | Alpine's `lifecycle.js`, Livewire's `lifecycle.js` |
| Module cache | Same as Solution A | `supportJsModules.js` |

**Alpine changes:** ~10 lines in `lifecycle.js` (add `_x_defer` check in walker).

**Trade-offs:**
- (+) Livewire's safety net is a single line (`el._x_defer = getAssetPromiseFor(component)`)
- (+) Alpine handles re-init, error recovery, and `isConnected` guard automatically
- (+) No manual `_x_ignore` management, no `runAfterAssetIsLoadedFor` callback
- (+) `_x_defer` is a clean, general-purpose API that any Alpine plugin could use
- (+) Error handling is built into Alpine (catches failed promises, warns, inits anyway)
- (+) Correct `wire:loading` timing (same as Solution A)
- (-) Requires an Alpine change (even if small)
- (-) Adds `_x_defer` to Alpine's element property conventions
- (-) `_x_defer` check placement in the walker is subtle (must be after `initInterceptors` so Livewire can create the Component first)

---

## Solution C: Livewire-Only, No New Hooks (`_x_ignore` + awaitable `onSync`)

No Alpine changes, no new lifecycle hooks. Instead of adding `onPrepare`, make the existing `onSync` hook awaitable. Module pre-loading happens in `onSync`, which already runs between snapshot merging and `processEffects`.

### Part 1: Initial page load

Identical to Solution A, Part 1.

### Part 2: AJAX responses (awaitable `onSync`)

Instead of adding a new `onPrepare` hook, make `invokeOnSync` async.

**Changes to `message.js`:**

```js
async invokeOnSync() {
    await Promise.all(
        this.interceptors.map(interceptor => interceptor.onSync())
    )
}
```

Update `onSync` default in `interceptor.js`:

```js
onSync = async () => {}
```

**Changes to `request/index.js`:**

```js
Alpine.transaction(async () => {
    message.component.mergeNewSnapshot(snapshotEncoded, effects, message.updates)

    await message.invokeOnSync()  // NOW AWAITED
    if (message.isCancelled()) return

    message.component.processEffects(effects, request)
    // ...
})
```

**Changes to `supportJsModules.js`:**

Same module pre-loading logic as Solution A's `onPrepare` handler, but registered on `onSync` instead:

```js
interceptMessage(({ message, onSuccess }) => {
    onSuccess(({ payload, onSync }) => {
        onSync(async () => {
            let imports = []

            let hash = payload.effects.scriptModule
            if (hash) {
                imports.push(preloadModule(message.component.name, hash))
            }

            let html = payload.effects.html
            if (html) {
                let template = document.createElement('template')
                template.innerHTML = html

                template.content.querySelectorAll('[wire\\:effects]').forEach(el => {
                    let childEffects = JSON.parse(el.getAttribute('wire:effects'))
                    if (!childEffects.scriptModule) return

                    let childSnapshot = JSON.parse(el.getAttribute('wire:snapshot'))
                    let childName = childSnapshot.memo.name

                    imports.push(preloadModule(childName, childEffects.scriptModule))
                })
            }

            await Promise.allSettled(imports)
        })
    })
})
```

### Part 3: Safety net

Identical to Solution A, Part 3 (`_x_ignore`).

### Summary

| Piece | What it does | Files changed |
|-------|-------------|---------------|
| Pre-import (initial load) | Same as Solution A | `lifecycle.js`, `supportJsModules.js` |
| Awaitable `onSync` | Make `invokeOnSync` async | `interceptor.js`, `message.js`, `request/index.js` |
| `onSync` handler | Pre-loads modules from response before processing | `supportJsModules.js` |
| Safety net | Same as Solution A (`_x_ignore`) | `lifecycle.js` |
| Module cache | Same as Solution A | `supportJsModules.js` |

**Alpine changes:** None.

**Trade-offs:**
- (+) No Alpine changes
- (+) No new lifecycle hooks (smallest API surface change)
- (+) Correct `wire:loading` timing
- (+) `onSync` runs at exactly the right point in the lifecycle
- (-) **Breaking change.** `onSync` is currently synchronous. Existing user-land code using `onSync` may not expect it to be awaited. If someone has `onSync(() => { /* sync code that assumes immediate continuation */ })`, making the hook awaitable could change timing. The only internal user is `supportPreserveScroll.js`, which is synchronous and unaffected.
- (-) Changes the documented contract of `onSync` (described as "After state merged/synced")

---

## Recommendation

**Solution A (Livewire-only, `_x_ignore` + `onPrepare`)** is the strongest option.

It solves all three scenarios with correct `wire:loading` timing, requires no Alpine changes, and introduces no breaking changes. The new `onPrepare` hook is a small, focused addition that follows the exact same pattern as every other hook in the interceptor system (`onSync`, `onEffect`, `onMorph`).

Solution B's `_x_defer` is elegant, but the placement subtlety (must run after `initInterceptors`) adds complexity that doesn't justify the benefit. The safety net is a fallback path that should rarely fire; making it one line shorter isn't worth an Alpine API change.

Solution C avoids a new hook but introduces a breaking change to `onSync`. Even though the only internal user is synchronous, changing a hook from sync to async is the kind of subtle contract change that bites user-land code.

**Build order for Solution A:**

1. **Safety net first** (~15 lines in `lifecycle.js`, ~5 in `supportJsModules.js`). This stops all crashes immediately. Components with pending modules defer their init and re-init when the module loads. FOUC on first load, but no errors.

2. **`onPrepare` hook + handler** (~30 lines across `interceptor.js`, `message.js`, `request/index.js`, `supportJsModules.js`). Scenarios 2 and 3 fully solved with correct loading timing.

3. **Pre-import for initial load** (~25 lines in `lifecycle.js` and `supportJsModules.js`). Scenario 1 fully solved. No FOUC on first load.

Each step is independently shippable and testable.
