# PR #9861 Comparison

## Caleb's Feedback

> "we need to think more about this one."
>
> "things we want: as few as possible changes to the initialization lifecycle of livewire/alpine, so ideally fetching everything ahead of time and holding everything else up during that fetch"
>
> "I bet there's some out of the box things we're missing here. idk..."

Key takeaway: Caleb wants **minimal lifecycle changes** and suspects there's a simpler approach.

## Claude Auto-Review (posted by Caleb)

The auto-review suggested two alternative approaches:

1. **Make Alpine tolerant of late `Alpine.data()` registrations** (have `x-data` retry/defer when it encounters an unregistered name). Alpine-side change, zero cost for pages without SFC scripts.
2. **MutationObserver / Alpine interceptor** to re-init subtrees when modules load.

Approach 2 is essentially our Idea B (deferred init via `_x_ignore` + `skip()` + re-init).

Approach 1 would require Alpine changes. Our Idea B achieves the same outcome from the Livewire side without touching Alpine.

## Key Concerns Raised About the PR

1. **`Livewire.start()` is now async** — every page pays the cost of `preloadExistingModules()` even if no components have script modules
2. **DOM parsing duplicates component init logic** — manually queries `[wire:id]` elements, parses `wire:effects` and `wire:snapshot` JSON (same work the component constructor already does)
3. **`onEffect` is now async** — changes timing semantics of the entire request lifecycle; too broad for a narrow use case
4. **Ancestor-walking PHP logic** — O(depth) per mounted child, stores `mountParent` references that could cause memory issues with deep nesting
5. **Removed `$js` pending logic** — if pre-loading fails or races, `$js` calls silently return `undefined` instead of waiting
6. **No error handling** — `import()` has no `.catch()`; a failed module blocks everything

## PR Approach vs Our Approach

| Area | PR #9861 | Our plan |
|---|---|---|
| Initial load | Pre-import before `Alpine.start()` (Idea A) | Same |
| Morph delay hook | Makes `onEffect` async | New `onPrepare` hook before `processEffects` |
| Child module discovery | PHP-side ancestor walking, `childScriptModules` effect | JS-side HTML parsing in `onPrepare` (no PHP changes needed) |
| Deferred init (Idea B) | Not implemented | Core safety net (~10 lines in `lifecycle.js`) |
| `$js` pending logic | Removed entirely | Keep it |
| Error handling | None | Catch per-module, warn, continue |
| `wire:loading` timing | Not addressed (clears before modules finish) | Solved by placing module loading before `processEffects` |

## What the PR Gets Right

- **The three test fixtures are good.** Cover initial load, dynamic toggle, and lazy-loaded scenarios. We should reuse or adapt these.
- **Module cache (`preloadedModules` Map)** — same concept as our recommended module cache.
- **`buildModulePath` helper** — useful extraction of the URL construction logic.
- **The `interceptMessage` pattern** for handling AJAX responses is the right hook point.

## What Our Plan Improves

1. **Idea B as safety net.** The PR has no fallback if pre-loading fails. Our deferred init catches any case where a module isn't ready, regardless of how the component was added.

2. **`onPrepare` instead of async `onEffect`.** The PR makes `onEffect` async, which is a broad lifecycle change. Our `onPrepare` hook is a targeted addition that doesn't change existing hook semantics. It also solves the `wire:loading` timing issue (loading clears in `onEffect` *after* modules are already loaded in `onPrepare`).

3. **JS-side HTML parsing instead of PHP ancestor walking.** The PR adds a `mount` hook that walks up the ancestor chain pushing `childScriptModules` to every ancestor. Our approach parses `effects.html` in the JS `onPrepare` handler, finding child `[wire:id]` elements and their `scriptModule` effects directly. No PHP changes needed, no ancestor traversal, no `mountParent` storage.

4. **Error handling.** The PR has no `.catch()` on any `import()`. A failed module leaves the component permanently broken. Our plan catches per-module, logs a warning, and continues.

5. **Keeps `$js` pending logic.** The PR removes `assetIsPendingFor`/`runAfterAssetIsLoadedFor` from `$wire.js`. Our plan keeps it as a safety mechanism for `$js` calls during async module loading.

## Alignment With Caleb's Feedback

Caleb's request for "as few as possible changes to the initialization lifecycle" aligns well with our plan:

- **Idea B** is ~10 lines in `lifecycle.js`, no lifecycle changes, just an `interceptInit` check
- **`onPrepare`** is a small, targeted hook addition (not changing existing hooks)
- **Idea A** (pre-import) does make `start()` async, same as the PR, but this is the least invasive way to solve scenario 1

His comment about "fetching everything ahead of time and holding everything else up during that fetch" describes exactly what Idea A + `onPrepare` does: fetch modules, hold up processing until they're ready.
