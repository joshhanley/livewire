# Final Solution Recommendation

## Background

We've spent significant time analysing a race condition in Livewire v4 where SFC/MFC script modules load asynchronously via `import()` but Alpine processes `x-data` synchronously, causing "Can't find variable" errors. We maintain both Livewire and Alpine, so changes to either or both are on the table.

All the research is done. We need you to read everything and tell us: **what is the best solution?**

## All Research Documents

Read all of these thoroughly. They represent the full body of research:

- @plans/00-context.md — Context summary, key files, decisions made, discoveries
- @plans/01-initial-findings.md — Full race condition analysis, solution ideas A-D, morph delay options A-E, resolved questions
- @plans/02-experts-review.md — First expert review (scenario validation, technical concerns)
- @plans/03-experts-recommendation.md — Expert implementation recommendations and build order
- @plans/04-pr-comparison.md — Comparison with the existing PR #9861 and Caleb's feedback
- @plans/05-alpine-options.md — Alpine-side options including promise-based `_x_defer`

## Key Source Files

Ground your recommendation in the actual code:

- @js/features/supportJsModules.js — Current module loading
- @js/lifecycle.js — Alpine boot, `interceptInit`
- @js/request/index.js — Request lifecycle, hook ordering
- @js/request/message.js — Message hooks
- @js/request/interceptor.js — Hook defaults
- @js/features/supportMorphDom.js — Morph trigger pattern
- @js/directives/wire-loading.js — Loading state timing
- @js/$wire.js — `$js` pending asset handling
- @js/features/supportPreserveScroll.js — Only `onSync` user

Alpine source (local copy, on main):
- @packages/alpine/packages/alpinejs/src/lifecycle.js — `initTree`, `_x_marker`, `_x_ignore`
- @packages/alpine/packages/alpinejs/src/directives/x-data.js — `x-data` evaluation
- @packages/alpine/packages/alpinejs/src/datas.js — `Alpine.data()` registration
- @packages/alpine/packages/alpinejs/src/directives.js — Directive system, deferred handling
- @packages/alpine/packages/alpinejs/src/mutation.js — Mutation observer

## What We Need

Based on ALL the research, recommend **the ultimate solution**. Specifically:

1. **What exact combination of changes should we make?** Pick from the documented options or propose something better. Be specific about what changes go in Alpine and what goes in Livewire.

2. **For Idea B (deferred init / safety net):** Should we use `_x_ignore` directly (Approach 3), Alpine's `deferInit`/`resumeInit` (Approach 2), or Alpine's promise-based `_x_defer` (Approach 4)? Or something else entirely?

3. **For the morph delay:** Should we use `onPrepare` (Option E), awaitable `onSync` (Option D), or something else? Remember the `wire:loading` timing constraint.

4. **Is Idea A (pre-import before `Alpine.start()`) worth the complexity?** Or is the morph delay + safety net sufficient for all scenarios including initial load?

5. **Is there a simpler overall approach we've missed?** We've been deep in the weeds. Step back and look at this fresh. Is there an elegant solution that makes some of the complexity unnecessary?

## Constraints

- Caleb wants "as few as possible changes to the initialization lifecycle"
- We maintain both Alpine and Livewire
- The existing `$js` pending asset mechanism should not be removed (PR #9861 removed it, which is risky)
- Error handling must be robust (catch, warn, continue)
- `wire:loading` must persist while modules load (not clear before morph)
- No breaking changes to existing public APIs

## Instructions

- Read ALL the research documents and source files
- Be decisive. Pick ONE path and justify it.
- If you see a simpler approach that makes some of our documented complexity unnecessary, say so
- Don't rehash the analysis. We've done that. Just tell us what to build.
- Structure your response clearly: what changes in Alpine, what changes in Livewire, in what order
