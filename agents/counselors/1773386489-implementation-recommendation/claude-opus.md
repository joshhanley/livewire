Now I have full context. Here's my recommendation.

---

# Implementation Recommendation

## The Build: A + D + B

Build three things, in a specific order:

1. **Idea B** (deferred init safety net)
2. **Option D** (make `onSync` awaitable, pre-load modules there)
3. **Idea A** (pre-import before `Alpine.start()`)

This combination covers all three scenarios with correct `wire:loading` timing and no flash of uninitialised content. Here's why this specific combination, and why in this order.

## Why Option D over Option E (or A/B/C)

The `wire:loading` timing issue that Codex flagged is the decisive constraint. Loading state clears in `onEffect`, which runs after `processEffects` but before `onMorph`. Options A, B, and C for the morph delay all place module loading *after* `processEffects`, meaning loading indicators disappear before the morph, creating a visible gap.

Only Options D and E place module loading *before* `processEffects`, which preserves `wire:loading` correctly:

```
onSync (awaited) → processEffects → onEffect (loading clears) → onMorph
```

Between D and E: Option D wins because it's less code and doesn't expand the public API. The only existing `onSync` user is `supportPreserveScroll.js`, which is synchronous. Making `onSync` awaitable is a no-op for it. Adding a whole new `onPrepare` hook for a single internal consumer is over-engineering.

## Build Order

### Step 1: Idea B (Deferred Init Safety Net)

**What:** In `lifecycle.js`'s `interceptInit`, after creating the Component, check `assetIsPendingFor(component)`. If true, set `el._x_ignore = true`, call `skip()`, and schedule re-init after the module loads.

**Why first:** This is the smallest change (roughly 10 lines in `lifecycle.js`) and it immediately stops the "Can't find variable" crashes for all three scenarios. It doesn't fix the UX (components flash as uninitialised), but it prevents errors. Ship this and the bug reports stop.

**Where to change:**
- `js/lifecycle.js` lines 58-92: Inside the `interceptInit` callback, after `initComponent(el)` on line 65, add the pending asset check before processing directives.

**Key detail:** The re-init call (`Alpine.initTree(el)`) must happen *after* deleting `_x_ignore`. The `interceptInit` callback won't re-create the Component because `el.__livewire` already exists. Everything fires exactly once; the analysis's double-init reasoning is correct.

**Error handling:** Wrap the `import()` in `supportJsModules.js` with `.catch()`. On failure, delete `_x_ignore` and call `Alpine.initTree(el)` anyway so the component initialises without its script module. Log a `console.warn`.

```js
// Sketch of the change in lifecycle.js interceptInit:
if (el.hasAttribute('wire:id') && !el.__livewire && !hasComponent(el.getAttribute('wire:id'))) {
    let component = initComponent(el)
    // ... existing cleanup registration ...

    // NEW: defer Alpine init if script module is still loading
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

**Gotcha:** The `skip()` call and `return` must happen *inside* the `el.hasAttribute('wire:id')` block, before the directive processing loop at lines 72-92. If you put it after, directives will have already been collected. Also, `skip` isn't currently destructured in the `interceptInit` callback; you'll need to check how Alpine passes it (it's the second argument to the walker callback inside `initTree`, but `interceptInit` exposes it differently; verify in Alpine's source).

**Test:** Browser test with a component that uses `Alpine.data()` in its `<script>` block. Verify no console error and the component initialises correctly.

### Step 2: Option D (Make `onSync` Awaitable + Pre-load Modules)

**What:** Two sub-changes:
1. Make `invokeOnSync()` in `message.js` async and await it in `request/index.js`
2. Add an `onSync` handler in `supportJsModules.js` that reads the response HTML, finds child `[wire:id]` elements with `scriptModule` effects, pre-imports them, and caches them

**Why second:** This is the proper fix for scenarios 2 (lazy) and 3 (dynamic). After step 1, components don't crash but they flash as uninitialised. After step 2, the morph waits until modules are loaded, so loading states persist and everything appears fully initialised at once.

**Where to change:**

1. `js/request/message.js` line 200-202: Make `invokeOnSync` async, mirroring `invokeOnMorph`:

```js
async invokeOnSync() {
    await Promise.all(
        this.interceptors.map(interceptor => interceptor.onSync())
    )
}
```

2. `js/request/index.js` line 429: Await `invokeOnSync()`:

```js
await message.invokeOnSync()
```

3. `js/features/supportJsModules.js`: Add an `interceptMessage` handler that uses `onSync` to pre-load modules from the response HTML:

```js
interceptMessage(({ message, onSuccess }) => {
    onSuccess(({ payload, onSync }) => {
        onSync(async () => {
            let html = payload.effects.html
            if (!html) return

            // Parse the HTML for child components with script modules
            let template = document.createElement('template')
            template.innerHTML = html

            let promises = []

            template.content.querySelectorAll('[wire\\:effects]').forEach(el => {
                let effects = JSON.parse(el.getAttribute('wire:effects'))
                if (!effects.scriptModule) return

                let snapshot = JSON.parse(el.getAttribute('wire:snapshot'))
                let name = snapshot.memo.name
                let hash = effects.scriptModule

                // Check cache, skip if already loaded
                if (moduleCache.has(cacheKey(name, hash))) return

                promises.push(
                    fetchAndCacheModule(name, hash).catch(e => {
                        console.warn(`Livewire: Failed to load script module for ${name}`, e)
                    })
                )
            })

            // Also handle the component's own scriptModule effect
            if (payload.effects.scriptModule) {
                let name = message.component.name
                let hash = payload.effects.scriptModule
                if (!moduleCache.has(cacheKey(name, hash))) {
                    promises.push(
                        fetchAndCacheModule(name, hash).catch(e => {
                            console.warn(`Livewire: Failed to load script module for ${name}`, e)
                        })
                    )
                }
            }

            if (promises.length > 0) {
                await Promise.all(promises)
            }
        })
    })
})
```

4. Refactor `supportJsModules.js` to use a module cache. The `effect` hook should check the cache before calling `import()`. If cached, call `module.run()` synchronously:

```js
let moduleCache = new Map()

function cacheKey(name, hash) { return `${name}:${hash}` }

async function fetchAndCacheModule(name, hash) {
    let key = cacheKey(name, hash)
    if (moduleCache.has(key)) return moduleCache.get(key)

    let encodedName = name.replace(/\./g, '--').replace(/::/g, '---').replace(/:/g, '----')
    let path = `${getModuleUrl()}/js/${encodedName}.js?v=${hash}`

    let module = await import(/* @vite-ignore */ path)
    moduleCache.set(key, module)
    return module
}
```

**Gotcha 1:** The `Alpine.transaction` block in `request/index.js` (line 426) wraps `invokeOnSync` through `invokeOnMorph`. Since `Alpine.transaction` already handles async (it awaits the callback), making `invokeOnSync` async should work. But verify that `Alpine.transaction` actually awaits its callback; if it doesn't, the morph will run before `onSync` resolves, defeating the purpose. Check `node_modules/alpinejs/src/reactivity.js` for the `transaction` implementation.

**Gotcha 2:** When parsing the response HTML for child components, use `document.createElement('template')` not `DOMParser`. `template.content` gives you an inert `DocumentFragment` that won't trigger resource loading or script execution.

**Gotcha 3:** Add a timeout. If a module takes longer than 5 seconds, resolve anyway and let Idea B's safety net handle it. Use `Promise.race` with a timeout promise. Without this, a broken CDN or slow network hangs the entire UI.

**Test:** Browser test where a parent component conditionally renders a child that has an `Alpine.data()` script module. Click a button on the parent to show the child. Verify:
- Parent's `wire:loading` stays visible until the child's module loads
- Child renders fully initialised (no flash)
- No console errors

### Step 3: Idea A (Pre-import Before `Alpine.start()`)

**What:** Before calling `Alpine.start()` in `lifecycle.js`, scan all `[wire:id][wire:effects]` elements, extract `scriptModule` hashes, import all modules in parallel, cache them. Then `Alpine.start()`.

**Why third:** After steps 1 and 2, everything works correctly. Step 3 eliminates the brief uninitialised flash on initial page load. Without step 3, Idea B's deferred init handles initial load (no errors) but there's a visual delay while modules load. Step 3 makes initial load seamless.

**Where to change:**
- `js/lifecycle.js`: Make `start()` async. Before `Alpine.start()` (line 112), call `await preloadInitialModules()`.
- `js/features/supportJsModules.js`: Export `preloadInitialModules()` and `fetchAndCacheModule()`.

```js
// In supportJsModules.js:
export async function preloadInitialModules() {
    let promises = []

    document.querySelectorAll('[wire\\:id][wire\\:effects]').forEach(el => {
        try {
            let effects = JSON.parse(el.getAttribute('wire:effects'))
            if (!effects.scriptModule) return

            let snapshot = JSON.parse(el.getAttribute('wire:snapshot'))
            let name = snapshot.memo.name
            let hash = effects.scriptModule

            promises.push(
                fetchAndCacheModule(name, hash).catch(e => {
                    console.warn(`Livewire: Failed to preload script module for ${name}`, e)
                })
            )
        } catch (e) { /* malformed JSON, skip */ }
    })

    await Promise.all(promises)
}
```

**Gotcha:** Making `start()` async changes its signature, but the only call site is `DOMContentLoaded` in `js/index.js`, which doesn't await it. The `dispatch(document, 'livewire:initialized')` event on line 116 will fire before modules are loaded unless you restructure. Move everything after `Alpine.start()` into the async flow:

```js
export async function start() {
    // ... plugin registration ...

    await preloadInitialModules()

    Alpine.start()

    setTimeout(() => window.Livewire.initialRenderIsFinished = true)
    dispatch(document, 'livewire:initialized')
}
```

The `livewire:initialized` event now fires after modules are loaded, which is arguably more correct (everything is truly initialised).

**Test:** Browser test with multiple components on initial page load, each with `Alpine.data()` in their script modules. Verify no flash and no errors.

## What to Defer

**`<link rel="modulepreload">` tags:** Nice enhancement but not necessary. The pre-import in Steps 2 and 3 already starts `import()` at the earliest useful moment. `modulepreload` would shave off the time between HTML parse and JS execution, but it's marginal. Add it later as a performance optimisation if profiling shows module fetch latency is a problem.

## Implementation Gotchas Summary

1. **Verify `Alpine.transaction` awaits its callback.** If it doesn't, Step 2 breaks. This is the single highest-risk assumption.

2. **`skip()` in `interceptInit`.** Alpine's `interceptInit` doesn't directly expose `skip`. Look at how the callback is invoked in Alpine's `initTree` / `deferHandlingDirectives`. You may need to use `el._x_ignore` alone (which prevents directive handlers from executing) without `skip()` (which prevents walking children). If `_x_ignore` propagates to children via Alpine's walker, you might not need `skip()` at all.

3. **Detached element cleanup (Codex's concern).** In Idea B, if the element is removed from the DOM while the module is loading, the `runAfterAssetIsLoadedFor` callback fires on a detached element. Guard against this:
   ```js
   runAfterAssetIsLoadedFor(component, () => {
       if (!el.isConnected) return  // Element was removed, bail
       delete el._x_ignore
       Alpine.initTree(el)
   })
   ```

4. **No `.catch()` on the current `import()`.** The existing code at `supportJsModules.js:18` has no error handling. A failed import silently leaves the component in `pendingComponentAssets` forever. Add `.catch()` in Step 1 as part of the error handling work.

5. **Module cache keying.** Key by component name + hash, not just name. The hash changes when the file is modified, so a stale cached module from a previous deploy won't be used.

6. **Islands path.** Codex noted that `supportIslands.js` has its own DOM insertion path. After implementing Steps 1-3, test islands with script modules to verify they go through the same `initTree` path. If they don't, the Idea B safety net won't catch them and you'll need a separate hook there.
