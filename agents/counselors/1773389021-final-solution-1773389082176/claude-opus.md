I've read all six research documents and all the key source files. Here's my recommendation.

---

## The Recommendation

### Fresh insight: use `payload.intercept` instead of a new hook

The research focused on Options D and E for the morph delay, but there's a simpler path that everyone missed. `payload.intercept` (`request/index.js:407`) is **already async and already awaited**, and it fires **before any message processing begins**. It gives you the full response JSON including `effects.html` and `effects.scriptModule` for every component. Pre-import modules there and you get correct `wire:loading` timing with zero lifecycle changes.

Trace through the timing:

```
1. payload.intercept fires → pre-import all modules (await)     ← modules load here
2. invokeOnSuccess() → wire:loading hooks registered
3. Alpine.transaction(async () => {
4.   mergeNewSnapshot()
5.   invokeOnSync()
6.   processEffects() → effect hook fires, module already cached → run() sync
7.   invokeOnEffect() → wire:loading clears                     ← loading clears here
8.   await invokeOnMorph() → morph applies                      ← DOM updates here
9. })
```

Loading state persists while modules load (steps 1-7). Loading clears and morph applies within the same `Alpine.transaction()`, so the user sees them atomically. No gap.

This eliminates the need for `onPrepare` (Option E), awaitable `onSync` (Option D), or any other lifecycle modification for scenarios 2 and 3.

### Alpine changes: none

Use `_x_ignore` directly for the safety net. You maintain both projects; it's stable. The `_x_defer` approach (05-alpine-options.md, Approach 4) is elegant, but it adds API surface to Alpine for what is fundamentally a Livewire concern. Not worth it.

### The three pieces

**1. Safety net: Idea B (~10 lines in `lifecycle.js`)** — Stops all crashes immediately.

In `interceptInit`, after `initComponent(el)`:
- Check `assetIsPendingFor(component)`
- If pending: `el._x_ignore = true`, `skip()`, return early
- Schedule: `runAfterAssetIsLoadedFor(component, () => { if (!el.isConnected) return; delete el._x_ignore; Alpine.initTree(el) })`

This handles every scenario, including navigate and any path we haven't anticipated. UX isn't perfect (brief FOUC), but components never crash.

**2. AJAX pre-loading: `payload.intercept` handler in `supportJsModules.js`** — Solves scenarios 2 and 3 with correct loading timing.

Listen to `payload.intercept`. For each component in the response:
- If `effects.scriptModule` exists, pre-import and cache the module
- If `effects.html` exists, parse it with `document.createElement('template')`, query for child `[wire:id][wire\\:effects]` elements, parse their `wire:effects` and `wire:snapshot` to get module URLs, pre-import and cache

By the time `processEffects` runs, the existing `effect` hook in `supportJsModules.js` finds the module already cached and calls `module.run()` synchronously. The pending asset is never even created, so the safety net never fires.

**3. Initial load pre-loading: Idea A in `lifecycle.js`** — Eliminates FOUC on first page load.

Make `start()` async. Before `Alpine.start()`, scan `document.querySelectorAll('[wire\\:id][wire\\:effects]')`, parse `wire:effects` for `scriptModule` hashes, parse `wire:snapshot` for component names, import all modules in parallel, cache them. Then call `Alpine.start()`.

### Shared module cache

All three mechanisms share one module cache in `supportJsModules.js`, keyed by component name + hash. The existing `effect` hook checks the cache first; if hit, it calls `module.run()` synchronously and never creates a pending asset entry.

### Error handling

Add `.catch()` to every `import()`. On failure:
- Console warn with component name and URL
- Remove `_x_ignore` if set (safety net path)
- Continue — a partially working component is better than one that never appears
- Use `Promise.allSettled()` in the `payload.intercept` handler so one failed module doesn't block others

### Build order

| Step | What | Lines changed | Effect |
|------|------|---------------|--------|
| 1 | Idea B (safety net) | ~10 in `lifecycle.js`, ~5 in `supportJsModules.js` (add `.catch()`) | All crashes stop. FOUC on first load but no errors. |
| 2 | `payload.intercept` pre-loading | ~30 in `supportJsModules.js` + module cache refactor | Scenarios 2 & 3 fully solved. Correct loading timing. |
| 3 | Idea A (initial load) | ~15 in `lifecycle.js`, ~10 in `supportJsModules.js` | Scenario 1 fully solved. No FOUC on first load. |

### What this does NOT change

- No Alpine changes
- No new lifecycle hooks
- No existing hook semantics changed
- `$js` pending logic preserved
- `wire:loading` timing correct
- No PHP changes needed

### Deferred enhancements

- `<link rel="modulepreload">` injection from PHP (makes all pre-imports resolve faster)
- Navigate: pre-import modules from new page HTML before `initTree` (low priority; safety net handles it)
- Islands path testing (`supportIslands.js` has its own DOM insertion)

### Why this is simpler than the research concluded

The research converged on `onPrepare` (Option E) because it's the earliest awaitable hook before `processEffects`. But `payload.intercept` is even earlier — it runs before `invokeOnSuccess`, before the transaction, before everything. And it's already awaitable. The research documents mention it in Idea D's section but dismiss it because "the child's scriptModule effect isn't in the parent's response payload." That's true for the response JSON directly, but the child's module info IS in `effects.html` as DOM attributes. Parsing that HTML (with an inert template element) gives us everything we need.
