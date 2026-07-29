# Changelog

## 2.4.0 — 2026-07-29

### Added

- **Evaluation inspectors (`onEvaluation`).** `Config` accepts an `inspectors` array of callables, each invoked synchronously with a `Featureflip\EvaluationEvent` exactly once per variation call on every exit path, including `FLAG_NOT_FOUND` and the evaluator's own `ERROR` reason. The event carries `flagKey`, a copy of the full `context`, the `value` the caller receives, `variationKey`, the SDK-native SCREAMING_SNAKE_CASE `reason`, `ruleId` (only on `RULE_MATCH`), `prerequisiteKey` (only on `PREREQUISITE_FAILED`) and an ISO-8601 `timestamp`. Non-callable entries are dropped when the client is created, and a throwing inspector is isolated (logged to the PHP error log; it neither changes the returned value nor stops the remaining inspectors). New type: `Featureflip\EvaluationEvent` (#1914). Also threaded through `FeatureflipClient::forTesting(array $flags, array $inspectors = [])`.

### Fixed

- An unexpected evaluator exception now fails safe to the caller's default with reason `ERROR` rather than propagating into the host request path. PHP was the only SDK where the throw escaped (#1990).
- A served variation key the flag does not define now reports reason `ERROR` with the caller's default, instead of a misleading success reason (#1989).

### Changed

- Dropped deprecated `ReflectionMethod::setAccessible()` calls (#1995).

## 2.3.0 — 2026-07-13

### Changed

- Covered by the cross-SDK golden-vector parity suite (#1477).

## 2.2.0 — 2026-06-19

### Added

- **Semantic-version condition operators** (`SemverEquals`, `SemverGreaterThan`, `SemverGreaterThanOrEqual`, `SemverLessThan`, `SemverLessThanOrEqual`) for local rule evaluation, comparing per semver precedence rather than as decimals (#1451).

### Fixed

- Per-flag rollout salt aligns bucketing with the engine and every other SDK; the previous `$flag` fallback re-bucketed users (#1452).
- Relational operators match against **any** supplied condition value (#1443).
- `MatchesRegex` is case-sensitive — the pattern is no longer compiled with the `i` modifier (#1453).
- Numeric operators return no-match on non-numeric operands instead of coercing `"abc"` to `0.0` (#1456).
- `Before`/`After` date operators aligned with the engine (#1455).
- Type-aware numeric coercion for `Equals`/`In` (#1458).
- Keyless rollouts serve the control variation deterministically (#1457).
- A context key explicitly set to `null` is treated as absent rather than `""` — the previous `array_key_exists` check let it fall through (#1460).
- Environment-level percentage rollouts with no variations no longer throw (#1469).

## 2.1.0 — 2026-05-27

### Added

- **Prerequisite flag support.** Flags can declare other flags as prerequisites; the flag's rules and fallthrough only run when every prerequisite serves the expected variation, otherwise the off variation is served with `reason = 'PREREQUISITE_FAILED'` and `prerequisiteKey` set on the `EvaluationDetail`. Resolution is recursive (depth-capped at 10, exceeded chains return `reason = 'ERROR'`) with per-call memoisation. New types: `Featureflip\Model\Prerequisite`. New `EvaluationDetail` field: `prerequisiteKey`. New batch entry point: `Evaluator::evaluateWithSharedMemo()` (#1112).

## 2.0.0

### BREAKING

- **Renamed `Client` to `FeatureflipClient`** — the main SDK class is now `Featureflip\FeatureflipClient`
- **Renamed `Client::create()` to `FeatureflipClient::get()`** — the factory method now returns cached instances keyed by SDK key (singleton-by-construction)
- **Constructor is private** — use `FeatureflipClient::get($sdkKey, $config)` to obtain a client

### Added

- **Singleton-by-construction factory** — `FeatureflipClient::get()` deduplicates by SDK key. Same key returns handles sharing one underlying connection and flag store
- **Refcounted disposal** — `close()` decrements a refcount. Resources are only cleaned up when the last handle closes. Double-close is a safe no-op
- **`FeatureflipClient::forTesting()`** — test stub factory (unchanged behavior, renamed from `Client::forTesting()`)

### Migration

```php
// Before (1.x)
use Featureflip\Client;
$client = Client::create('sdk-key', $config);

// After (2.x)
use Featureflip\FeatureflipClient;
$client = FeatureflipClient::get('sdk-key', $config);
```

All evaluation methods (`boolVariation`, `stringVariation`, etc.) are unchanged.
