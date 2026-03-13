# Expert Final Recommendations

**Agents consulted:** claude-opus, codex-5.3-high, gemini-2.5-pro, gemini-3-pro

## Two Camps

### Camp 1: `payload.intercept` + No Alpine Changes (Claude Opus, Codex)

These two argue we've been overthinking it. `payload.intercept` at `request/index.js:407` is **already async and already awaited**, and it fires **before any message processing begins**. Pre-load modules there and the `wire:loading` timing is correct without any new hooks.

The flow:
```
payload.intercept (awaited) → modules load here
  invokeOnSuccess → wire:loading hooks registered
    Alpine.transaction:
      mergeNewSnapshot
      invokeOnSync
      processEffects → module already cached, run() sync
      invokeOnEffect → wire:loading clears
      await invokeOnMorph → morph applies
```

No `onPrepare` hook needed. No Alpine changes. Use `_x_ignore` directly for the safety net. Codex says Idea A (pre-import before `Alpine.start()`) isn't worth shipping in the core fix; keep it as optional polish later.

**Changes:**
- **Alpine:** None
- **Safety net:** `_x_ignore` directly (Approach 3 from `05-alpine-options.md`)
- **Morph delay:** `payload.intercept` handler in `supportJsModules.js` (existing hook, no lifecycle changes)
- **Initial load:** Idea A (Opus says yes, Codex says defer as polish)
- **New hooks:** None
- **Lifecycle changes:** None

### Camp 2: `_x_defer` + `onPrepare` (Gemini 2.5, Gemini 3)

These two recommend the `_x_defer` Alpine change plus `onPrepare` in Livewire, plus Idea A. They argue `_x_defer` simplifies Livewire's deferred init to a single line and that `onPrepare` is the cleaner Livewire-side hook.

**Changes:**
- **Alpine:** `_x_defer` (~10 lines in `lifecycle.js`)
- **Safety net:** `_x_defer` promise (Approach 4 from `05-alpine-options.md`)
- **Morph delay:** New `onPrepare` hook between `onSync` and `processEffects`
- **Initial load:** Idea A (yes)
- **New hooks:** `onPrepare`
- **Lifecycle changes:** `onPrepare` addition to `request/index.js`, `message.js`, `interceptor.js`

## The `payload.intercept` Insight

This is the fresh idea from Camp 1. Looking at `request/index.js:407`:

```js
await triggerAsync('payload.intercept', responseJson)
```

This fires **before** the `request.messages.forEach` loop that processes each component. It receives the full `responseJson` which contains all component payloads. It's already awaited. It's already async.

If we pre-load modules here:
- We have access to each component's `effects.scriptModule`
- We can parse `effects.html` for child component modules
- By the time `processEffects` runs, modules are cached and `module.run()` executes synchronously
- `wire:loading` clears in `onEffect` as normal, after modules are already loaded
- **No new hooks needed**

The research documents mention `payload.intercept` in Idea D's section (`01-initial-findings.md`) but dismissed it because "the child's scriptModule effect isn't in the parent's response payload." That's true for the response JSON directly, but the child's module info IS in `effects.html` as DOM attributes. Parsing that HTML (with an inert template element) gives us everything we need.

## Consensus

Both camps agree on:
- Shared module cache in `supportJsModules.js` keyed by name + hash
- `.catch()` on all `import()` calls with `console.warn`
- `Promise.allSettled` for parallel loading
- Keep `$js` pending logic in `$wire.js`
- `el.isConnected` guard on re-init
- Parse HTML with `document.createElement('template')` (inert, no resource loading)
- Error handling: catch per-module, warn, continue

## Comparison

| | Camp 1 | Camp 2 |
|---|---|---|
| Alpine changes | None | `_x_defer` (~10 lines) |
| New Livewire hooks | None | `onPrepare` |
| Existing hook changes | None | None |
| Lifecycle complexity | Lowest | Low |
| Safety net mechanism | `_x_ignore` (internal) | `_x_defer` (clean API) |
| Requires Alpine release | No | Yes |

Camp 1 directly addresses Caleb's "as few as possible changes to the initialization lifecycle" with zero new hooks and zero existing hook changes. Camp 2 is cleaner on the Livewire side (one-line `_x_defer` vs manual `_x_ignore` + `runAfterAssetIsLoadedFor`) but requires an Alpine change and a new Livewire hook.

## Full Reports

Individual expert reports are saved in `agents/counselors/1773389021-final-solution-1773389082176/`.
