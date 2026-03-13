# Expert Review: Script Module Race Condition Analysis

**Agents consulted:** claude-opus, codex-5.3-high, gemini-2.5-pro

## Consensus

All three experts agree:
- The three scenarios are **complete**; edge cases (nested components, `@if` toggles, navigate, modals, multiple instances) are variations of the existing three
- **Idea C (A + B) with morph delay Option C (`onBeforeMorph` hook)** is the strongest approach
- The **double-init analysis is correct** (everything fires exactly once)
- Idea D is the weakest option
- **Error handling is critical** since the current `import()` has no `.catch()`, meaning a failed module blocks everything forever

## Key Issues Raised

1. **`wire:loading` timing (Codex, critical):** Codex flags that `wire:loading` is cleared in `onEffect`, which runs *before* `onMorph`. So delaying the morph alone may not actually preserve the loading state as we've documented. The loading indicators could disappear before the morph even starts. **This needs investigation.**

2. **Nested script-module components waterfall (all three):** If a parent has a script module and a child also has one, Idea B causes a waterfall: parent module loads, `initTree` re-runs, discovers child, child module loads, `initTree` re-runs again. This doubles latency for nested cases. Idea A (pre-import) avoids this for initial load since all modules load in parallel.

3. **Remove-before-reinit (Codex):** If a component is removed from the DOM (navigate away, modal close, `@if` toggle) while its module is still loading, the `runAfterAssetIsLoadedFor` callback fires on a detached element. Elements without `_x_marker` don't get Alpine's removal cleanup. Could leave stale components in the store.

4. **Module discovery for morph delay (Gemini 2.5):** The doc doesn't specify *how* `onBeforeMorph` discovers which modules to load from the incoming HTML. The implementation needs to traverse the `toEl` tree, find new `[wire:id]` elements, and parse their `wire:effects` for `scriptModule` hashes.

5. **Islands / append-prepend paths (Codex):** `supportIslands.js` has its own DOM insertion path that isn't covered by the morph delay hooks.

6. **`inscribeSnapshotAndEffectsOnElement` drops `scriptModule` (Claude Opus):** When navigate caches/restores pages, `scriptModule` isn't preserved in re-inscribed effects. Works for `Alpine.data()` (global registry persists) but `$js` actions bound to the component instance would be lost.

## Disagreements

- **Idea D:** Gemini 2.5 says it's impractical and shouldn't be pursued. Codex says it's **better than documented** because parsing `effects.html` for nested `[wire:id]` elements is workable and runs in the already-awaited `payload.intercept`. This is worth considering as an alternative to the `onBeforeMorph` hook approach.

- **Minor memory concern (Claude Opus):** Notes that deferred directive handlers register no-op cleanup callbacks on first pass that accumulate. All agree this is negligible in practice.

## Blind Spots

- None of the experts addressed **timeouts** for the morph delay. `Promise.allSettled()` or a timeout mechanism is needed to prevent a slow/broken module from hanging the UI indefinitely.

## Full Reports

Individual expert reports are saved in `agents/counselors/1773384440-script-module-race-review-1773384513456/`.
