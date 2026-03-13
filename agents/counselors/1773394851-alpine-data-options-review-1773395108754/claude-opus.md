Now I have a thorough understanding of all documents and source code. Here's my review.

---

# Review: Alpine.data() Script Module Loading Options

## 1. Technical Accuracy

### Verified correct

- **`_x_ignore` mechanism:** Confirmed. Alpine's `getDirectiveHandler` (directives.js:137) checks `el._x_ignore` at execution time and early-returns. `_x_marker` is only set when `!el._x_ignore` (lifecycle.js:108). `interceptInit` callbacks receive `(el, skip)` (lifecycle.js:101). The double-init analysis is sound.

- **`wire:loading` timing:** Confirmed. Loading clears in `onEffect` via `onSuccess → onEffect` callback chain (wire-loading.js:106-112). `invokeOnEffect` fires at request/index.js:435, before `await invokeOnMorph` at line 438.

- **`payload.intercept` being awaited:** Confirmed at request/index.js:407 via `triggerAsync` which sequentially awaits each callback (hooks.js:63-76).

- **`Alpine.transaction` awaits its callback:** Confirmed. It's `async` and does `await callback()` (reactivity.js:100-104). So async hooks inside the transaction (like `await invokeOnMorph`) will properly block.

- **Current `import()` has no `.catch()`:** Confirmed at supportJsModules.js:18-24. A failed module would leave the component permanently pending.

- **`processEffects` fires in the Component constructor:** Confirmed at component.js:54.

### Minor inaccuracies

**Hook ordering diagram.** The document shows:
```
onSuccess → onSync → processEffects → onEffect → onMorph → onFinish → onRender
```

This is misleading. `message.invokeOnSuccess()` fires at request/index.js:421, *before* the `Alpine.transaction` starts at line 426. It's where `onSync`/`onEffect`/`onMorph` callbacks are *registered*, not where they execute. `onFinish` and `onRender` fire *after* the transaction resolves (`.then` at line 439). A more accurate diagram:

```
invokeOnSuccess (registers hooks) →
  Alpine.transaction {
    mergeNewSnapshot → onSync → processEffects → onEffect → await onMorph
  } →
  onFinish → requestAnimationFrame → onRender
```

This matters for understanding where `onPrepare` would sit. The reader needs to know it's inside the transaction to understand the await semantics.

**Livewire's `interceptInit` callback signature.** The current code at lifecycle.js:59 uses `el => { ... }`. The document's Solution B assumes `skip()` is available, which it is (Alpine passes `(el, skip)`), but the callback ignores the second argument. The implementation would need to change the callback to `(el, skip) => { ... }`. Worth noting since Caleb would see this immediately.

## 2. Research Faithfulness

### Major omission: `payload.intercept` is not presented as an option

This is the most significant gap. The research documents (06-experts-plans.md, 07-payload-intercept-analysis.md) spent substantial effort analysing `payload.intercept` as an alternative to `onPrepare`. Two of four experts (Camp 1: Claude Opus, Codex) recommended it as the primary approach. The analysis in 07-payload-intercept-analysis.md concluded:

- Already exists, already awaited, no lifecycle changes needed
- Fires before all message processing, so `wire:loading` timing is naturally correct
- No new hooks needed
- `supportScriptsAndAssets.js` already uses this exact pattern for `@assets`
- Better aligned with Caleb's stated preference for "as few as possible changes to the initialization lifecycle"

The final document presents three solutions: `onPrepare` (A), deferred init (B), and awaitable `onSync` (C). `payload.intercept` doesn't appear at all. Given Caleb explicitly asked for "as few as possible changes to the initialization lifecycle" and `payload.intercept` requires zero new hooks and zero lifecycle changes, this omission materially weakens the document's persuasiveness.

If `payload.intercept` was intentionally excluded, the document should explain why. If it was unintentional, it should be added as a solution (arguably as the primary recommendation).

**Key difference:** `onPrepare` is per-message (runs inside the `Alpine.transaction` for each component). `payload.intercept` is global (runs once per request, before any messages are processed). Both achieve correct `wire:loading` timing, but `payload.intercept` does it with zero new hooks. The trade-off is that `payload.intercept` uses the older `on()`/`triggerAsync()` event system rather than the newer `interceptMessage` pattern.

### Missing: `inscribeSnapshotAndEffectsOnElement` doesn't preserve `scriptModule`

The expert review (02-experts-review.md) flagged that `inscribeSnapshotAndEffectsOnElement` in component.js:283-306 only preserves `listeners`, `url`, and `scripts` effects, not `scriptModule`. This means when `wire:navigate` caches/restores a page, the component's `scriptModule` effect is lost. Alpine.data() registrations persist (they're in Alpine's global registry), but `$js` actions bound to the component instance would be lost.

This is noted in the context doc (00-context.md) under "Defer as Enhancements" but should be mentioned in the final document's caveats, since it's a known limitation of any solution that relies on `wire:effects` for module discovery.

## 3. Gaps

### No timeout/fallback for `onPrepare`

The experts flagged (02-experts-review.md) that no timeout mechanism exists for the morph delay. If a module hangs (slow CDN, broken URL), `onPrepare` blocks the entire response processing indefinitely. The document mentions error handling for failed imports (`.catch()`) but not for *slow* imports. A `Promise.race` with a reasonable timeout (e.g., 5 seconds) would prevent the UI from hanging forever on a slow network.

### Islands path

`supportIslands.js` has its own DOM insertion path that may bypass the morph delay hooks. This was flagged by experts (02-experts-review.md, 03-experts-recommendation.md) and noted in 00-context.md as needing testing. Not mentioned in the final document.

### `wire:navigate` needs testing

Noted in 00-context.md but not mentioned as a caveat in the final document.

## 4. Recommendation Strength

The recommendation for Solution A is well-argued for what it covers. The per-scenario walkthroughs are clear and the loading-state reasoning is compelling. The dismissal of Solution B as insufficient for UX and Solution C as a breaking change are both correct.

However, the recommendation is weakened by not addressing `payload.intercept`. A reader familiar with the codebase (Caleb) would likely ask: "Why not use `payload.intercept` like `supportScriptsAndAssets` does?" The document has no answer to this, which undermines its completeness.

If `onPrepare` is genuinely better than `payload.intercept`, the document needs to explain why. My reading of the research suggests the main argument for `onPrepare` over `payload.intercept` is pattern consistency (new interceptor pattern vs old event bus pattern). But Caleb's stated preference for "minimal changes" points the other way.

## 5. Misleading or Oversimplified

### "No Alpine changes" framing

The document correctly notes no Alpine changes are needed. But Solution B uses `_x_ignore` directly, which is an undocumented Alpine internal. The document acknowledges this in Solution B's trade-offs but not in Solution A's. Solution A *also* relies on `_x_ignore` as the safety net mechanism (the `interceptInit` check from Idea B is implied as part of the approach). If Idea B is incorporated into Solution A as a fallback, the `_x_ignore` dependency applies to A as well.

### "No breaking changes to existing hooks"

This is true for Solution A. But the document doesn't mention that `onPrepare` extends the `MessageInterceptor` class's API surface (new default property, new setter in `invokeOnSuccess`). It's a new hook, not a change to existing ones, but it does expand the interceptor contract. Users of `interceptMessage` who destructure all available hooks would see a new `onPrepare` callback. Low risk, but worth noting.

## 6. Clarity and Structure

The document is well-structured overall. The three-scenario framework is clear and consistent throughout. The "Files changed" tables are useful.

**One structural suggestion:** The "How it works today" section is good but could benefit from explicitly showing *where* in the request pipeline each step occurs (with line references to request/index.js). The later sections reference hook positions but the reader has to piece together the pipeline from multiple sections.

## Summary of Recommended Changes

1. **Add `payload.intercept` as a solution** (or explain why it was excluded). Given the research, it should probably be the primary recommendation for scenarios 2/3, with `onPrepare` as the alternative.
2. **Fix the hook ordering diagram** to show the transaction boundary and where `onSuccess` actually fires.
3. **Add `inscribeSnapshotAndEffectsOnElement` caveat** re: `wire:navigate` cache.
4. **Add timeout mention** for module loading in the AJAX path.
5. **Note the `interceptInit` callback signature change** needed in lifecycle.js.
6. **Mention Islands path** as a known area needing verification.
