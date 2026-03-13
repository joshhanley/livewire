# `payload.intercept` Analysis

## What It Is

`payload.intercept` is a global hook on Livewire's internal event bus (`hooks.js`). It fires once per AJAX request at `request/index.js:407`:

```js
await triggerAsync('payload.intercept', responseJson)
```

It uses the old `on()` / `triggerAsync()` system from `hooks.js`, **not** the new `interceptMessage` / `MessageInterceptor` pattern. The new interceptor system is component-scoped and provides message lifecycle hooks (`onSend`, `onSuccess`, `onSync`, `onEffect`, `onMorph`, etc.). `payload.intercept` sits outside that system entirely.

Currently only used by `supportScriptsAndAssets.js` (line 9) to load `@assets` before components are processed.

## How It Works

`triggerAsync` (`hooks.js:63-76`) runs callbacks **sequentially** and **awaits each one**. So an async `payload.intercept` handler will complete before the response processing continues.

The callback receives `responseJson`, which contains:
- `responseJson.components` — array of `{ snapshot, effects }` payloads for each component in the response
- `responseJson.assets` — any `@assets` to load

Each component's `effects` object contains `scriptModule` (if the component has a script module) and `html` (the rendered HTML, which may contain child components with their own `wire:effects` and `wire:snapshot` attributes).

## Timing: Where It Sits in the Request Flow

```
1. Request sent → wire:loading shows (onSend)
2. Response arrives
3. request.invokeOnSuccess()
4. await triggerAsync('payload.intercept', responseJson)  ← HERE
5. request.messages.forEach → for each message:
   a. message.invokeOnSuccess() → wire:loading registers onEffect callback
   b. Alpine.transaction:
      - mergeNewSnapshot
      - invokeOnSync
      - processEffects → effect hook fires
      - invokeOnEffect → wire:loading CLEARS
      - await invokeOnMorph → morph applies
```

`wire:loading` is still visible during step 4. It doesn't clear until step 5b (`invokeOnEffect`). So pre-loading modules in `payload.intercept` naturally preserves loading state.

## How It Maps to the Three Scenarios

### Scenario 1: Initial page load

**`payload.intercept` does not help.** There's no AJAX request on initial load. This scenario is purely `Alpine.start()` → `initTree()`. Still needs Idea A (pre-import before `Alpine.start()`).

### Scenario 2: Lazy/deferred components

**Works.** The lazy component's real payload arrives via AJAX. `responseJson.components` contains the lazy component's `effects.scriptModule`. Pre-import it in `payload.intercept`, cache it. By the time `processEffects` runs, the module is cached and `module.run()` executes synchronously.

### Scenario 3: Dynamically added components

**Works.** The parent re-renders. `responseJson.components` contains the parent's payload. The parent's `effects.html` contains the child's rendered HTML, including the child's root element with `wire:effects` (containing `scriptModule` hash) and `wire:snapshot` (containing the component name) as attributes.

To discover child modules:
1. Parse `effects.html` into an inert template: `document.createElement('template').innerHTML = html`
2. Query for `[wire\\:effects]` elements inside the template content
3. Parse each element's `wire:effects` JSON for `scriptModule`
4. Parse each element's `wire:snapshot` JSON for the component name
5. Import those modules and cache them

Confirmed that `wire:effects` and `wire:snapshot` are inserted as attributes by `HandleComponents.php:91-93`:
```php
$html = Utils::insertAttributesIntoHtmlRoot($html, [
    'wire:snapshot' => $snapshot,
    'wire:effects' => $context->effects,
]);
```

## Old vs New Pattern

| | `payload.intercept` (old) | `interceptMessage` (new) |
|---|---|---|
| System | `on()` / `triggerAsync()` from `hooks.js` | `interceptMessage()` / `MessageInterceptor` |
| Scope | Global, once per request | Per component/message |
| Lifecycle hooks | None | `onSend`, `onSuccess`, `onSync`, `onEffect`, `onMorph`, etc. |
| Async support | Yes (`triggerAsync` awaits) | Varies (only `onMorph` is currently awaited) |
| Used by | `supportScriptsAndAssets.js` | `supportMorphDom.js`, `wire-loading.js`, `supportPreserveScroll.js`, etc. |

The new interceptor pattern is what all the recent features use. `payload.intercept` is the older global hook approach. It works, but using it means module loading follows a different pattern from the rest of the request lifecycle features.

## Trade-offs

**Pros:**
- Already exists, already awaited, no lifecycle changes needed
- Fires before all message processing, so `wire:loading` timing is naturally correct
- No new hooks (`onPrepare`) needed
- Access to full response JSON including all components' effects and HTML

**Cons:**
- Uses the older `on()` hook system, not the new `interceptMessage` pattern
- Global scope (fires once per request, not per message); need to iterate `responseJson.components` manually
- Module loading code would follow a different pattern from `supportMorphDom`, `wire-loading`, etc.
- Not component-scoped, so no access to `message.component` or message lifecycle hooks

## Comparison: `payload.intercept` vs `onPrepare`

| | `payload.intercept` | `onPrepare` (Option E) |
|---|---|---|
| New code in request pipeline | None | New hook in `message.js`, `interceptor.js`, `index.js` |
| Pattern consistency | Old pattern | Matches existing interceptor pattern |
| `wire:loading` timing | Correct (fires before everything) | Correct (fires before `processEffects`) |
| Scope | Global (all components at once) | Per message/component |
| Caleb's "minimal changes" | Better (no lifecycle changes) | Adds a hook |
