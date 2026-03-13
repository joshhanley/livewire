# Review Request

## Question

Review the document at `@plans/initial-findings.md` thoroughly. This document analyses a race condition in Livewire v4 where SFC/MFC script modules (containing `Alpine.data()` registrations) load asynchronously via `import()` but Alpine processes `x-data` synchronously, causing "Can't find variable" errors. The document proposes several solution ideas and analyses trade-offs.

Please think carefully about:

1. **Scenario completeness**: The document presents three scenarios (initial page load, lazy/deferred components, dynamically added components). Are there any other scenarios we've missed? Think about edge cases like:
   - Nested components (a component with a script module inside another component with a script module)
   - Components added via JavaScript (not through Livewire's morph)
   - `wire:navigate` between pages with different script module components
   - Components inside modals/dialogs that are shown/hidden
   - Components conditionally rendered with `@if` that toggle on re-render
   - Multiple instances of the same component type on one page
   - Components that are removed and re-added
   - Hot module replacement / dev tooling interactions

2. **Solution soundness**: Are the solution ideas (A through D) sound? Do the trade-offs accurately capture the risks? Are there approaches not considered?

3. **Morph delay mechanism**: The "Delaying the Morph Until Modules Are Ready" section presents three hook timing options (A: make `onEffect` awaitable, B: sequential `onMorph`, C: new `onBeforeMorph` hook). Are these options complete? Is there another approach we haven't considered?

4. **Double-init analysis**: For Idea B (deferred init), the document claims that `_x_ignore` + `skip()` + early `return` means everything fires exactly once on re-init. Is this reasoning correct? Could there be edge cases where double-init occurs?

5. **Any other technical concerns or gaps** in the analysis?

## Context

### Primary Document
@plans/initial-findings.md

### Context/Summary Document
@plans/context.md

### Key Source Files

These are the key source files referenced in the analysis. Read them to verify the document's claims:

- @js/features/supportJsModules.js — Module loading via async `import()`, pending asset tracking
- @js/lifecycle.js — Livewire/Alpine boot process, `interceptInit` callback, `Alpine.start()`
- @js/component.js — Component constructor, `processEffects()`
- @js/store.js — `initComponent()`, component registry
- @js/$wire.js — Existing `$js` pending asset handling
- @js/features/supportMorphDom.js — Morph trigger via `interceptMessage` + `onMorph`
- @js/features/supportScriptsAndAssets.js — How `@script`/`@endscript` works (synchronous, no race condition)
- @js/morph.js — DOM morphing implementation
- @js/request/index.js — Request lifecycle (success handler, hook ordering)
- @js/request/message.js — Message lifecycle hooks (`invokeOnMorph` uses `Promise.all`)
- @js/plugins/navigate/index.js — Navigate plugin, `initTree` call
- @js/plugins/navigate/page.js — Head merging during navigate (`mergeNewHead`, `isAsset()`)
- @js/features/index.js — Feature import order
- @js/index.js — Entry point, `Livewire.start()` call site
- @src/Features/SupportJsModules/SupportJsModules.php — PHP-side module effect injection
- @src/Features/SupportLazyLoading/SupportLazyLoading.php — Lazy loading, `isLazyLoadMounting` flag

### Alpine Source (for verifying double-init claims)
- @node_modules/alpinejs/src/lifecycle.js — `initTree`, `interceptInit`, `_x_marker`, `_x_ignore`
- @node_modules/alpinejs/src/directives/x-data.js — `x-data` directive evaluation

## Instructions

You are providing an independent review of this race condition analysis. Be critical and thorough.

- Read the referenced files to verify the document's technical claims against the actual source code
- Pay special attention to the double-init analysis for Idea B — trace through the Alpine source to confirm the claims about `_x_marker`, `_x_ignore`, and `skip()`
- Identify any scenarios the analysis has missed
- Evaluate whether the morph delay options are complete
- Flag any risks, incorrect assumptions, or blind spots
- Suggest alternatives if you see better approaches
- Be direct and opinionated — don't hedge
- Structure your response with clear headings
