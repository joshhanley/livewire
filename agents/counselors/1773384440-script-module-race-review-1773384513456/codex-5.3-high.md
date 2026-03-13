**Top Findings**
1. The document’s “morph delay keeps `wire:loading` visible” claim is incorrect today. `wire:loading` is cleared in `onEffect`, which runs before morph.
[wire-loading.js:105](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/directives/wire-loading.js:105)  
[request/index.js:435](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/request/index.js:435)  
[request/index.js:438](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/request/index.js:438)

2. Idea B’s `_x_ignore + skip + re-init` analysis misses a teardown leak if the element is removed before re-init. Ignored elements do not get `_x_marker`; Alpine removal cleanup only runs for marked nodes. That can leave stale Livewire components in the store.
[alpine lifecycle.js:109](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/node_modules/alpinejs/src/lifecycle.js:109)  
[alpine mutation.js:133](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/node_modules/alpinejs/src/mutation.js:133)  
[lifecycle.js:67](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/lifecycle.js:67)

3. Current module loader has no failure path. If `import()` fails, pending state never clears and deferred callbacks never run.
[supportJsModules.js:18](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/features/supportJsModules.js:18)

4. Morph Option B (sequential `onMorph` with import-order reliance) is weaker than documented. Global feature order is only part of ordering; component-scoped interceptors run before globals.
[features/index.js:7](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/features/index.js:7)  
[features/index.js:29](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/features/index.js:29)  
[interceptor.js:115](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/request/interceptor.js:115)

**Scenario Gaps**
1. Nested script-module components under Idea B can waterfall (parent module must load before child `initComponent()` even runs). This is a real UX/perf gap.
[component.js:54](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/component.js:54)  
[alpine walk.js:10](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/node_modules/alpinejs/src/utils/walk.js:10)

2. Remove-before-load cases are under-modeled: quick `@if` toggles, modal close, navigate-away, or parent morph replacing subtree before module resolves.

3. `wire:navigate` correctness is probably fine, but UX still suffers without preload because it calls `initTree(document.body)` directly on the new page.
[navigate/index.js:252](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/plugins/navigate/index.js:252)

4. JS-added components outside Livewire request/morph flow are not helped by morph-delay hooks.

5. Island append/prepend paths are another dynamic insertion path worth explicit coverage.
[supportIslands.js:96](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/features/supportIslands.js:96)

**Solutions A-D**
1. Idea A is sound for initial load, but also needs a navigate equivalent (preload before `initTree` on navigated pages) or it leaves a noticeable gap.

2. Idea B is useful as a safety net, not as primary mechanism. It needs stronger lifecycle safety (`catch/finally`, disconnection guard, teardown strategy) before it is production-safe.

3. Idea C is directionally best if you want best initial UX plus runtime fallback, but it still needs robust pre-morph preload for scenario 3 and the safety fixes above.

4. Idea D is better than the doc gives it credit for. Parsing `effects.html` for nested `[wire:id]` + `wire:effects` + `wire:snapshot` is workable and can run in awaited `payload.intercept` without new message hook ordering risk.
[request/index.js:407](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/request/index.js:407)

**Morph Delay Options Completeness**
1. A/B/C are not complete. Missing practical option: do async module preload in awaited `payload.intercept` (including nested modules parsed from HTML), then keep morph callbacks as-is.

2. If you still want a dedicated hook, add an explicit awaited pre-morph phase and keep it separate from `onMorph` semantics. Do not rely on callback registration/import order.

**Double-init Verdict (Idea B)**
1. The “no double-init for Alpine directives” claim is mostly correct.
[alpine lifecycle.js:97](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/node_modules/alpinejs/src/lifecycle.js:97)  
[alpine directives.js:137](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/node_modules/alpinejs/src/directives.js:137)

2. But it is incomplete: it does not account for remove-before-reinit teardown, import-failure deadlock, and first-pass interceptor side effects (for example `wire:cloak` removal still happens immediately).
[wire-cloak.js:3](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/directives/wire-cloak.js:3)

**Recommended Path**
1. Primary: preload modules pre-morph via awaited `payload.intercept`, including nested modules extracted from HTML.
2. Initial + navigate: preload module set before `Alpine.start()` and before navigate `initTree`.
3. Safety net: keep deferred-init fallback, but harden it for failure/removal paths.
4. Fix the `wire:loading` lifecycle expectation explicitly if “loading persists until morph” is a requirement.
