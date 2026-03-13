# Expert Recommendations: Implementation Plan

**Agents consulted:** claude-opus, codex-5.3-high, gemini-2.5-pro, gemini-3-pro

## Consensus

All four agree on the core approach: **Idea B (deferred init) as the safety net + Idea A (pre-import) for initial load + a morph delay mechanism for scenarios 2 and 3.** The build order is also unanimous: **B first** (stops crashes immediately), then the morph delay, then A (polish).

All agree `<link rel="modulepreload">` should be deferred as an enhancement.

## The Split: Morph Delay Hook

**Team `onPrepare` (Option E) — claude-opus, codex-5.3-high, gemini-2.5-pro:**
- Add a new `onPrepare` hook between `onSync` and `processEffects`
- Preserves `wire:loading` correctly (loading clears in `onEffect`, which runs *after* modules have already loaded in `onPrepare`)
- Codex specifically calls out Option D (awaitable `onSync`) as "changing existing hook semantics unnecessarily"

**Team `onBeforeMorph` (Option C) — gemini-3-pro:**
- Adds hook between `onEffect` and `onMorph`
- Acknowledges the `wire:loading` gap but says "accept it for V1, it's better than broken content"

**Team `onSync` (Option D) — claude-opus (as alternative):**
- Claude Opus also argues Option D (awaitable `onSync`) could work, since adding a new hook for a single internal consumer is over-engineering
- But acknowledges the breaking change concern

## Recommended Build Order

### Step 1: Idea B (Deferred Init Safety Net)

~10 lines in `lifecycle.js`. Stops the "Can't find variable" crashes for all three scenarios immediately. UX isn't perfect (FOUC), but errors are gone.

- In `interceptInit`, after creating the Component, check `assetIsPendingFor(component)`
- If pending: set `el._x_ignore = true`, call `skip()`, return early
- Schedule re-init via `runAfterAssetIsLoadedFor(component, () => { delete el._x_ignore; Alpine.initTree(el) })`
- Add `.catch()` to `import()` in `supportJsModules.js` (currently has none; a failed import leaves the component permanently pending)

### Step 2: Morph Delay (Option E — `onPrepare` hook)

Add a new awaited hook between `onSync` and `processEffects`. In `supportJsModules.js`, use `onPrepare` to parse incoming `effects.html` for child component modules and await their loading. By the time `processEffects` runs, modules are cached. `wire:loading` clears in `onEffect` as normal, right before the morph.

Changes:
- `js/request/message.js` — add `invokeOnPrepare` (async, like `invokeOnMorph`)
- `js/request/index.js` — `await message.invokeOnPrepare()` between `invokeOnSync` and `processEffects`
- `js/request/interceptor.js` — add `onPrepare` default
- `js/features/supportJsModules.js` — `interceptMessage` using `onPrepare` to parse HTML and pre-load modules

### Step 3: Idea A (Pre-import Before `Alpine.start()`)

Eliminates FOUC on initial page load. Make `start()` async, scan all `[wire:id][wire:effects]` elements, import all modules in parallel, cache them, then call `Alpine.start()`.

Changes:
- `js/lifecycle.js` — make `start()` async, call `await preloadInitialModules()` before `Alpine.start()`
- `js/features/supportJsModules.js` — export `preloadInitialModules()`, share module cache with effect handler

## Minimum Viable Fix

**Idea B alone** stops the crashes for all three scenarios. It's the smallest change that makes the bug reports go away. Add the morph delay and pre-import as fast follow-ups for UX polish.

## Defer as Enhancements

- `<link rel="modulepreload">` tag injection (performance optimisation)
- Navigate cache: preserve `scriptModule` in `inscribeSnapshotAndEffectsOnElement` for `$js` action parity after restore
- Islands path testing (`supportIslands.js` has its own DOM insertion)

## Implementation Gotchas

1. **`.catch()` on `import()` is mandatory.** Current code has none; a failed import leaves the component permanently pending. On failure: log `console.warn`, delete `_x_ignore`, call `initTree` anyway.
2. **Guard `el.isConnected` in re-init callback.** If the element is removed while the module loads (navigate away, modal close, `@if` toggle), don't call `initTree` on a detached element.
3. **Module cache keyed by name + hash.** Not just name, so stale modules from previous deploys aren't reused.
4. **Parse HTML with `document.createElement('template')`.** Creates an inert `DocumentFragment` that won't trigger resource loading or script execution.
5. **Verify `Alpine.transaction` actually awaits its callback.** If it doesn't, async hooks inside the transaction won't block the morph. Check Alpine's `reactivity.js`.
6. **`skip()` in `interceptInit`.** Verify how Alpine exposes `skip` to `interceptInit` callbacks (it's the second argument to the walker callback inside `initTree`, but `interceptInit` may expose it differently).
7. **Islands path.** `supportIslands.js` has its own DOM insertion path that may bypass the morph delay hooks. Needs testing after implementation.

## Full Reports

Individual expert reports are saved in `agents/counselors/1773386489-implementation-recommendation/`.
