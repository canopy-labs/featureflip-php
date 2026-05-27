# Changelog

## Unreleased

### Added

- **Prerequisite flag support.** Flags can declare other flags as prerequisites; the flag's rules and fallthrough only run when every prerequisite serves the expected variation, otherwise the off variation is served with `reason = 'PREREQUISITE_FAILED'` and `prerequisiteKey` set on the `EvaluationDetail`. Resolution is recursive (depth-capped at 10, exceeded chains return `reason = 'ERROR'`) with per-call memoisation. New types: `Featureflip\Model\Prerequisite`. New `EvaluationDetail` field: `prerequisiteKey`. New batch entry point: `Evaluator::evaluateWithSharedMemo()`.

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
