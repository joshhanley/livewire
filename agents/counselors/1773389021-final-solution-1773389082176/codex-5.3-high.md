**Ultimate Path**
Build this in Livewire only, with no Alpine API changes:

1. In [supportJsModules.js](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/features/supportJsModules.js), create one shared module loader/cache keyed by `componentName + hash`, with per-module `catch` + `console.warn` + continue.
2. In that same file, add an async `payload.intercept` listener that parses incoming `effects.html` (and `effects.islandFragments`) via `<template>`, discovers nested `[wire:id]` components, preloads all referenced modules, and `await`s them (`Promise.allSettled`).
3. Keep existing component-level pending tracking for `$js` (`assetIsPendingFor` / `runAfterAssetIsLoadedFor`) and wire it to the same loader.
4. In [lifecycle.js](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/lifecycle.js), add Idea B safety net using `_x_ignore + skip() + re-init` when a component asset is pending, plus `el.isConnected` guard and `destroyComponent(component.id)` if detached before resume.

**Direct Answers**
1. Exact combination: Livewire changes above only; Alpine unchanged.
2. Idea B mechanism: use `_x_ignore` directly (Approach 3).
3. Morph delay: use neither `onPrepare` nor awaitable `onSync`; use existing awaited `payload.intercept` phase to preload before `processEffects`.
4. Idea A (`pre-import before Alpine.start()`): not worth shipping in the core fix. Keep as optional perf polish later if you want to remove initial-load FOUC.
5. Simpler approach missed: yes, the existing awaited `payload.intercept` hook is the cleanest place to do morph-time module preloading without adding/changing lifecycle hooks.

**Build Order**
1. `supportJsModules` loader/cache + error handling + `payload.intercept` preloading.
2. `lifecycle` deferred-init safety net.
3. Tests: initial load, lazy load, dynamic child insert, navigate restore, island append/prepend, import failure, detached-before-resume.
