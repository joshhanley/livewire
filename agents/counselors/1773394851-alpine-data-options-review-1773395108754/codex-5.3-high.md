**Findings**

1. **High: The document omits a major researched option (`payload.intercept`) and overstates that a new hook is required.**  
   The final doc says `onPrepare` is needed because `onSync` is sync ([alpine-data-module-options.md#L117-L125](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/alpine-data-module-options.md#L117)), but it does not include the already-awaited `payload.intercept` path (`await triggerAsync(...)`) that runs before message processing ([request/index.js#L407](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/request/index.js#L407)).  
   This is a research-faithfulness gap vs [06-experts-plans.md#L7-L31](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/06-experts-plans.md#L7) and [07-payload-intercept-analysis.md#L25-L43](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/07-payload-intercept-analysis.md#L25).

2. **High: Hook/timing description is technically inaccurate in a key section.**  
   It says the listed hooks run “inside an `Alpine.transaction`” ([alpine-data-module-options.md#L85-L89](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/alpine-data-module-options.md#L85)). In code, `message.invokeOnSuccess()` runs **before** the transaction ([request/index.js#L421](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/request/index.js#L421)), and `onFinish`/`onRender` run **after** it ([request/index.js#L440-L453](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/request/index.js#L440)).  
   Also missing from that sequence: `payload.intercept` between request success and message processing ([request/index.js#L407](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/request/index.js#L407)).

3. **High: Recommendation that `_x_ignore` fallback is “not needed” is too strong given uncovered insertion paths.**  
   The proposed preload discovery only mentions `effects.html` ([alpine-data-module-options.md#L125](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/alpine-data-module-options.md#L125)). But `supportIslands` can insert content via `effects.islandFragments` and stream chunks, including append/prepend paths ([supportIslands.js#L47-L64](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/features/supportIslands.js#L47), [supportIslands.js#L94-L99](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/features/supportIslands.js#L94)).  
   So saying there is “no race condition left to catch” ([alpine-data-module-options.md#L237](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/alpine-data-module-options.md#L237)) is not defensible without explicitly covering those paths.

4. **Medium: Solution B section is misleadingly incomplete on failure behavior.**  
   It frames B as a single-file ~15-line change ([alpine-data-module-options.md#L150-L181](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/alpine-data-module-options.md#L150)), but current module imports have no `.catch()` ([supportJsModules.js#L18-L24](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/js/features/supportJsModules.js#L18)).  
   Without catch/cleanup, pending state can remain stuck forever. This was explicitly flagged in prior research ([03-experts-recommendation.md#L67-L68](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/03-experts-recommendation.md#L67)).

5. **Medium: Final recommendation drifts from prior agreed rollout without explaining why.**  
   Prior recommendation was phased: B first, then morph-delay hook, then A ([03-experts-recommendation.md#L7-L8](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/03-experts-recommendation.md#L7)). Final doc now recommends A directly and demotes B ([alpine-data-module-options.md#L227-L237](/Users/josh/tend/workspaces/livewire-alpine-data/packages/livewire/plans/alpine-data-module-options.md#L227)).  
   That may be fine, but the change in strategy should be justified explicitly for Caleb.

**Open Questions**

1. Should island fragment insertion (`effects.islandFragments` + streamed islands) be first-class in module discovery, or is it intentionally out of scope for v1?
2. Given Caleb’s “minimal lifecycle changes” preference, why is `onPrepare` preferred over an existing awaited `payload.intercept` path?

**Overall**

The document is clear and structured well, but it is not yet fully accurate/faithful to the research record. I would not send it as the single source of truth until the above issues are corrected.
