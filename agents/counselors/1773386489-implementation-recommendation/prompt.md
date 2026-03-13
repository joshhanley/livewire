# Implementation Recommendation Request

## Background

We have thoroughly analysed a race condition in Livewire v4 where SFC/MFC script modules (containing `Alpine.data()` registrations) load asynchronously via `import()` but Alpine processes `x-data` synchronously, causing "Can't find variable" errors.

The analysis document has been reviewed and validated. We are confident in the technical findings. **We do not need further review of the options or scenarios.** That work is done.

## What We Need From You

Based on the full analysis, **recommend a concrete implementation plan**. Specifically:

1. **Which combination of solution ideas should we implement?** (Ideas A through D are documented, along with morph delay Options A through E)
2. **In what order should the pieces be implemented?** What's the right sequence to build and test incrementally?
3. **What is the minimum viable fix?** If we wanted the smallest change that solves all three scenarios correctly, what would it be?
4. **What should be deferred as enhancements?** (e.g., `<link rel="modulepreload">`)
5. **Are there any implementation gotchas** we should watch out for when building this?

Be opinionated. Pick a path and justify it. Don't hedge or list all options again; we've already done that. Tell us what you'd actually build and why.

## Context

### Full Analysis Document
@plans/initial-findings.md

### Expert Review (first round)
@plans/experts-review.md

### Context Summary
@plans/context.md

### Key Source Files
Read these to ground your recommendations in the actual code:

- @js/features/supportJsModules.js — Current module loading implementation
- @js/lifecycle.js — Alpine boot, `interceptInit`
- @js/request/index.js — Request lifecycle, hook ordering
- @js/request/message.js — Message hooks, `invokeOnMorph`
- @js/features/supportMorphDom.js — Morph trigger pattern
- @js/features/supportPreserveScroll.js — Only current `onSync` user
- @js/directives/wire-loading.js — Loading state timing (clears in `onEffect`)
- @js/$wire.js — Existing `$js` pending asset handling

## Instructions

- Do NOT re-review the options or re-validate the technical analysis. That's done.
- DO recommend a specific implementation path with justification.
- DO suggest an implementation order (what to build first, second, third).
- DO flag any implementation gotchas or things that are easy to get wrong.
- Be direct and decisive. We want a recommendation, not a menu.
- Structure your response with clear headings.
