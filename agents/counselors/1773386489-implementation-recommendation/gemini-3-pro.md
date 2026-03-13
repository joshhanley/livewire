This is the implementation recommendation for the Script Module Race Condition fix.

### 1. Recommended Solution
**Implement "Idea C" (Pre-import + Deferred Init) with Morph Delay "Option C" (`onBeforeMorph`).**

This combination provides a robust, layer-by-layer solution:
*   **Idea A (Pre-import):** Optimizes the initial page load, ensuring zero latency or FOUC for the most common scenario.
*   **Idea B (Deferred Init):** Acts as the critical safety net. It guarantees that *any* component (lazy, dynamic, or navigated) never initializes before its module is ready, preventing the "Can't find variable" crash.
*   **Morph Delay (Option C - `onBeforeMorph`):** Polishes the dynamic update experience by pausing the commit of new HTML until modules are ready.

### 2. Implementation Order

Build it in this sequence to secure stability first, then optimize performance and UX.

#### Step 1: The Safety Net (Idea B - Deferred Init)
**Goal:** Stop the application from crashing immediately.
**Action:** Modify `js/lifecycle.js`'s `interceptInit`.
*   Check `assetIsPendingFor(component)`.
*   If pending: set `el._x_ignore = true`, call `skip()`, and return.
*   Use `runAfterAssetIsLoadedFor(component)` to delete `_x_ignore` and call `Alpine.initTree(el)`.
*   **Why first?** This single change prevents the crash across all three scenarios (initial load, lazy, dynamic). It fixes the "bug" immediately, even if the UX (FOUC) isn't perfect yet.

#### Step 2: The Optimization (Idea A - Pre-import)
**Goal:** Eliminate FOUC and double-initialization on page load.
**Action:** Update `js/lifecycle.js`.
*   Create a `preloadScriptModules()` function.
*   Call it before `Alpine.start()`.
*   It should scan `[wire:effects]`, parse for `scriptModule` hashes, and trigger `import()` calls in parallel.
*   **Why second?** It drastically improves the "fresh load" experience, which is the user's first impression.

#### Step 3: The Polish (Morph Delay via `onBeforeMorph`)
**Goal:** Prevent "pop-in" of broken content during dynamic updates.
**Action:**
*   **Core:** Add a new `await message.invokeOnBeforeMorph()` hook in `js/request/index.js` (between `onEffect` and `onMorph`).
*   **Feature:** In `js/features/supportJsModules.js`, register a listener for `onBeforeMorph`.
*   **Logic:** Check if the component has a pending asset (set during the preceding `onEffect` phase). If so, return a Promise that resolves when the asset loads.

### 3. Minimum Viable Fix
**Idea B (Deferred Init) only.**
If you only implemented `interceptInit` skipping, the "Can't find variable" error would disappear completely. The application would be stable. Scenarios 1 and 3 would just have a brief "flash of unstyled content" (or inert content) while the module loads, but it would work reliably.

### 4. Defer / Enhancements
*   **`<link rel="modulepreload">`:** Defer. This is a performance enhancement that can be added to the PHP side later. It is not required to fix the race condition.
*   **HTML Scanning for Nested Modules (Idea D):** Defer. Do not attempt to parse `effects.html` to pre-load child modules. It is brittle. Rely on the "Waterfall" behavior of Idea B for nested dynamic components for now; it is safe and correct, just slightly slower.
*   **`wire:loading` Timing Adjustment:** Defer. Moving the `wire:loading` cleanup from `onEffect` to `onMorph` to fix the "loading indicator gap" is a separate task with its own regression risks.

### 5. Implementation Gotchas

*   **Error Handling is Mandatory:** The `import()` statement currently has no error handling. If a module 404s or fails to parse, the promise never resolves.
    *   **Fix:** You **must** add a `.catch()` to the import in `supportJsModules.js`. On error, log a warning (`console.warn`) and resolve the promise anyway so `Alpine.initTree` (Step 1) and the Morph (Step 3) can proceed. A component without its module is better than a frozen page.
*   **`wire:navigate` Bypass:** Step 2 (Pre-import) runs in `Alpine.start()`, which `wire:navigate` does *not* re-run.
    *   **Impact:** Navigating to a new page will rely entirely on Step 1 (Deferred Init). This is acceptable and safe, but be aware that `preloadScriptModules` won't run on navigation.
*   **The "Loading Gap":** With Step 3 (Morph Delay), `wire:loading` will still clear *before* the delay (because it clears in `onEffect`).
    *   **Result:** The user will see: `Loading Spinner` -> `[Gap: Old Content]` -> `New Content`.
    *   **Advice:** Accept this for V1. It is better than showing broken new content.
*   **Variable Scope:** Ensure `runAfterAssetIsLoadedFor` handles the case where the component might have been destroyed (e.g., user navigated away) while the module was loading. Check if `el` is still connected before calling `initTree`.
