# Script Module Loading Fix - Context

## What we're doing

Fixing a race condition where Livewire v4 SFC/MFC script modules (containing `Alpine.data()` registrations) load asynchronously but Alpine processes `x-data` synchronously, causing "Can't find variable" errors.

Discussions: #9591, #9737, #9830

## Key files

- `js/features/supportJsModules.js` - JS-side module loading (effect hook, async import, pending asset tracking)
- `js/lifecycle.js` - Livewire/Alpine boot process, `interceptInit` callback, `Alpine.start()`
- `js/component.js` - Component constructor, `processEffects()`
- `js/store.js` - `initComponent()`, component registry
- `js/$wire.js` - Existing `$js` pending asset handling (lines 165-193)
- `js/features/supportMorphDom.js` - Morph trigger via `interceptMessage` + `onMorph`
- `js/features/supportScriptsAndAssets.js` - How `@script`/`@endscript` works (synchronous, no race condition)
- `js/morph.js` - DOM morphing implementation
- `js/request/index.js` - Request lifecycle (lines 404-458: success handler, hook ordering)
- `js/request/message.js` - Message lifecycle hooks (`invokeOnMorph` uses `Promise.all`)
- `js/plugins/navigate/index.js` - Navigate plugin, `nowInitializeAlpineOnTheNewPage` calls `initTree`
- `js/plugins/navigate/page.js` - Head merging during navigate (`mergeNewHead`, `isAsset()`)
- `js/features/index.js` - Feature import order (matters for hook registration)
- `js/index.js` - Entry point, `Livewire.start()` call site, manual bundling path
- `src/Features/SupportJsModules/SupportJsModules.php` - PHP-side module effect injection, lazy load guards
- `src/Features/SupportLazyLoading/SupportLazyLoading.php` - Lazy loading, `isLazyLoadMounting` flag
- `src/Compiler/Parser/Parser.php` - Injects `scriptModuleSrc()` method (line 146)
- Alpine source: `node_modules/alpinejs/src/lifecycle.js` - `initTree`, `interceptInit`, `_x_marker`, `_x_ignore`
- Alpine source: `node_modules/alpinejs/src/directives/x-data.js` - `x-data` directive evaluation
- `js/directives/wire-cloak.js` - Removes `wire:cloak` immediately in its own `interceptInit` (won't help with FOUC)
- `js/directives/wire-loading.js` - Loading state timing (clears in `onEffect`, lines 106-112)
- `js/request/interceptor.js` - `MessageInterceptor` class, hook defaults
- `js/features/supportPreserveScroll.js` - Only current `onSync` user (synchronous)
- `js/features/supportIslands.js` - Islands DOM insertion path (may bypass morph delay hooks)

## The three scenarios

1. **Initial page load** - Components in server-rendered HTML need modules loaded before Alpine processes `x-data`
2. **Lazy/deferred components** - Placeholder rendered first, real component loaded via AJAX
3. **Dynamically added components** - Parent re-renders, morph adds new child with a script module

## Documents

- `01-initial-findings.md` - Full race condition analysis, solution ideas A-D, morph delay options A-E, modulepreload enhancement, resolved questions
- `02-experts-review.md` - First expert review (scenario completeness, technical validation)
- `03-experts-recommendation.md` - Expert implementation recommendations and build order
- `04-pr-comparison.md` - Comparison of PR #9861 approach vs our plan
- `05-alpine-options.md` - Alpine-side options (auto-retry, deferInit/resumeInit, `_x_ignore` as-is, promise-based `_x_defer`)
- `06-experts-plans.md` - Final expert recommendations: two camps (`payload.intercept` vs `_x_defer` + `onPrepare`)

Alpine source is available locally at `packages/alpine` (APFS clone from `/Users/josh/tend/packages/alpine`, on `main`).

## Decisions made

- **Lazy modules:** Defer, don't preload. Lazy components are lazy for a reason.
- **`wire:navigate`:** No special handling needed. `initTree(document.body)` follows the same code path. Needs testing after implementation.
- **Double-init:** Not a problem. `_x_ignore` prevents `_x_marker` from being set, `skip()` prevents children from being visited, early `return` prevents directive hooks. Everything fires exactly once on re-init.
- **Flash of content:** Morph must wait for child modules. Don't rely on user adding `x-cloak`. Delaying the morph means the parent retains its loading state (`wire:loading` indicators for scenario 3, lazy placeholders for scenario 2) until children's modules are ready, then morph applies and everything appears fully initialised at once.
- **Error handling:** Catch per-module, log console warning, continue. Don't block other components or the morph.

## Key technical insight: why `_x_ignore` + `skip()` + `return` works

On first pass (module pending):
- Component is created, pending asset detected
- `el._x_ignore = true` prevents `_x_marker` from being set
- `skip()` prevents walker visiting children
- Early `return` prevents `directive.global.init` and `directive.init` from firing
- Directive handlers early-return when flushed (check `_x_ignore` at execution time)

On re-init (module loaded, `_x_ignore` deleted):
- No `_x_marker` so `initTree` proceeds
- `el.__livewire` exists so Component creation is skipped
- All directive hooks fire for the first time
- Children visited for the first time

## Key discovery: `wire:loading` timing

`wire:loading` clears its state in `onEffect` (`wire-loading.js:106-112`), which runs *before* `onMorph`. Morph delay options A-C all place module loading after `processEffects`, so loading indicators disappear before modules finish loading. Options D and E solve this by placing module loading *before* `processEffects`:

- **Option D:** Make `onSync` awaitable (BC risk: changes existing hook from sync to async)
- **Option E:** Add a new `onPrepare` hook between `onSync` and `processEffects` (recommended by 3 of 4 experts)

## Expert-recommended approach

**Build order:** Idea B first (stops crashes), then Option E morph delay (correct loading timing), then Idea A (initial load polish). Defer `<link rel="modulepreload">` as enhancement.

**Minimum viable fix:** Idea B alone stops all "Can't find variable" errors.

## Important edge case noted

Idea B (deferred init) alone is not sufficient for scenario 3. The parent's morph applies immediately (removing loading states), then the child's module loads. This causes mid-interaction FOUC. The morph delay mechanism is essential for scenario 3, with Idea B serving as a safety net.
