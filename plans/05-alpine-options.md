# Alpine-Side Options

Caleb suggested exploring whether Alpine could be changed instead of (or alongside) Livewire. Since we maintain both projects, all options are on the table.

## How Alpine Resolves `x-data` Today

In `x-data.js`, the handler:
1. Creates a `dataProviderContext` object
2. Calls `injectDataProviders(dataProviderContext)` which adds all registered `Alpine.data()` components as properties
3. Evaluates the expression with this context in scope: `evaluate(el, expression, { scope: dataProviderContext })`
4. If the expression is a simple name like `"myComponent"`, it's resolved via JavaScript scope lookup
5. If the name isn't registered, JavaScript throws a ReferenceError (no error handling)

The registered data components live in a plain object (`datas`) in `datas.js`. There's no reactivity, no retry mechanism, and no concept of "pending" registrations.

## Approach 1: Alpine Auto-Retries Unresolved Data Names

When `x-data` can't resolve an expression, catch the error, queue the element, and re-init when `Alpine.data()` is later called with that name.

**Alpine changes:**

In `x-data.js`, wrap evaluation in try/catch:
```js
let data
try {
    data = evaluate(el, expression, { scope: dataProviderContext })
} catch (e) {
    if (e instanceof ReferenceError) {
        pendingDataElements.add(el, expression)
        el._x_ignore = true
        skip()
        return
    }
    throw e
}
```

In `datas.js`, when `Alpine.data(name, callback)` is called:
```js
export function data(name, callback) {
    datas[name] = callback
    flushPendingDataElements(name) // re-init elements waiting for this name
}
```

**Pros:**
- Zero cost for existing apps (try/catch only fires on error path)
- No Livewire changes needed for the basic case
- Works for any async loading mechanism, not just Livewire script modules
- Alpine "just works" with late data registrations

**Problems:**
- **Typos become silent failures.** `x-data="myCompnoent"` (typo) would silently defer forever instead of throwing immediately. This makes debugging harder. Could mitigate with a timeout + console warning, but that's more complexity.
- **Complex expressions are hard to handle.** `x-data="myComponent({ config: true })"` also throws a ReferenceError, but extracting the data name from the expression to match against later `Alpine.data()` calls is fragile. Would need expression parsing.
- **Children still need to be skipped.** No x-data scope means child directives (x-text, x-show, etc.) can't resolve. So we still need `_x_ignore` + `skip()` to prevent the subtree from initialising.
- **Adds complexity to Alpine's core** for a use case that's primarily about Livewire's async module loading.

**Verdict:** Too magical. The silent typo problem alone is a dealbreaker for developer experience.

## Approach 2: Alpine Provides `deferInit` / `resumeInit` API

Instead of Livewire using the internal `_x_ignore` flag directly, Alpine provides a clean, documented API for deferring element initialisation.

**Alpine changes (~15 lines):**

```js
// In lifecycle.js or a new defer.js

export function deferInit(el) {
    el._x_deferInit = true
}

export function resumeInit(el) {
    delete el._x_deferInit
    initTree(el)
}
```

In `initTree`'s walker, add a check alongside the existing `_x_ignore` check:

```js
walker(el, (el, skip) => {
    if (el._x_marker) return

    // Existing ignore check
    // ...

    // New defer check
    if (el._x_deferInit) {
        skip()
        return
    }

    initInterceptors.forEach(i => i(el, skip))
    directives(el, el.attributes).forEach(handle => handle())
    if (!el._x_ignore) el._x_marker = markerDispenser++
    el._x_ignore && skip()
})
```

**How Livewire would use it (Idea B):**

```js
// In lifecycle.js interceptInit:
if (assetIsPendingFor(component)) {
    Alpine.deferInit(el)
    skip()

    runAfterAssetIsLoadedFor(component, () => {
        if (!el.isConnected) return
        Alpine.resumeInit(el)
    })
    return
}
```

**Pros:**
- Livewire uses a supported, documented API instead of internal `_x_ignore`
- Small Alpine change, clean separation
- `resumeInit` handles cleanup (delete flag, call `initTree`) so consumers don't need to know internals
- Useful beyond Livewire (any plugin that needs to defer init for async reasons)
- No double-init: `_x_marker` is never set because the walker returns before reaching that line, same mechanism as `_x_ignore`

**Cons:**
- Mechanism is essentially the same as `_x_ignore` + manual `initTree(el)` with a nicer name
- Still requires all the Livewire-side work (Ideas A, B, `onPrepare`)
- Adds to Alpine's public API surface

## Approach 3: No Alpine Changes, Use `_x_ignore` As-Is

Our current Idea B works without any Alpine changes. The `_x_ignore` + `skip()` + re-init via `initTree(el)` mechanism is correct (verified through detailed code analysis in `01-initial-findings.md`).

**Pros:**
- No Alpine changes needed
- Works today
- `_x_ignore` is well-understood, well-tested, and stable

**Cons:**
- Relies on an undocumented internal API
- If Alpine's `_x_ignore` semantics change, Livewire breaks

**Mitigating factor:** Since we maintain both projects, `_x_ignore` is effectively stable. We control both sides.

## Approach 4: Promise-Based Defer (`_x_defer`)

Alpine supports a promise on any element. When `initTree` encounters an element with `_x_defer` set to a promise, it skips the element and its children, then automatically re-inits when the promise resolves. Livewire just sets the promise; Alpine handles everything else.

**Alpine changes (~10 lines in `lifecycle.js`):**

In `initTree`'s walker, add a check before `interceptInit` and directives:

```js
walker(el, (el, skip) => {
    if (el._x_marker) return

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

    initInterceptors.forEach(i => i(el, skip))
    directives(el, el.attributes).forEach(handle => handle())
    if (!el._x_ignore) el._x_marker = markerDispenser++
    el._x_ignore && skip()
})
```

**How Livewire would use it:**

`supportJsModules.js` would need to expose the module load promise per component. Then in `lifecycle.js`'s `interceptInit`:

```js
if (assetIsPendingFor(component)) {
    el._x_defer = getAssetPromiseFor(component)
    skip()
    return
}
```

**Why this is better than Approaches 2 and 3:**

- **No manual re-init.** Livewire doesn't call `initTree(el)` or `Alpine.resumeInit(el)`. Alpine handles re-init automatically when the promise resolves.
- **No `_x_ignore` usage.** Livewire doesn't touch internal Alpine flags. `_x_defer` is a clean, single-purpose mechanism.
- **No `runAfterAssetIsLoadedFor`.** The promise IS the callback mechanism. No need for Livewire's own pending callback system.
- **Error handling is built in.** Alpine catches failed promises, logs a warning, and inits the component anyway. Livewire doesn't need its own error handling for the deferred init path.
- **Self-documenting.** Setting a promise on an element clearly communicates intent: "this element is waiting for something async."

**No double-init:** The walker returns before reaching the `_x_marker` assignment line, so `_x_marker` is never set. When the promise resolves and `initTree(el)` is called, the element has no marker and proceeds normally. `interceptInit` fires again but skips Component creation because `el.__livewire` exists. Everything fires exactly once, same as Approach 3.

**Pros:**
- Minimal Alpine change (~10 lines)
- Simplifies Livewire's deferred init to a single line (set a promise)
- Error handling and re-init logic live in Alpine, not scattered across Livewire
- Clean API that any plugin could use
- Promise-based, which is the natural primitive for "wait for async thing"

**Cons:**
- Requires Alpine change (but small)
- Adds `_x_defer` to Alpine's element property conventions
