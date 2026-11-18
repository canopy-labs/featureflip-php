# Featureflip PHP SDK

PHP SDK for [Featureflip](https://featureflip.io) - evaluate feature flags locally with near-zero latency.

## Installation

```bash
composer require featureflip/featureflip-php
```

## Quick Start

```php
<?php

use Featureflip\FeatureflipClient;
use Featureflip\Config;

$client = FeatureflipClient::get('your-sdk-key', new Config(
    cache: $psrCache,
    httpClient: $psrHttpClient,
    requestFactory: $psrRequestFactory,
    streamFactory: $psrStreamFactory,
));

$enabled = $client->boolVariation('my-feature', ['user_id' => 'user-123'], false);

if ($enabled) {
    echo "Feature is enabled!";
}

$client->close();
```

## Configuration

```php
use Featureflip\FeatureflipClient;
use Featureflip\Config;

$config = new Config(
    baseUrl: 'https://eval.featureflip.io',  // Evaluation API URL (default)
    pollInterval: 30,                         // Polling interval in seconds
    flushInterval: 30,                        // Ship queued events at least this often
    flushBatchSize: 100,                      // Events per batch
    cache: $psrCache,                         // PSR-16 CacheInterface
    httpClient: $psrHttpClient,               // PSR-18 ClientInterface
    requestFactory: $psrRequestFactory,       // PSR-17 RequestFactoryInterface
    streamFactory: $psrStreamFactory,         // PSR-17 StreamFactoryInterface
    logger: $psrLogger,                       // PSR-3 LoggerInterface (optional)
);

$client = FeatureflipClient::get('your-sdk-key', $config);
```

The SDK key can also be supplied through the `FEATUREFLIP_SDK_KEY` environment variable — pass an empty string to `get()` and it is read from there. Passing a key explicitly always wins.

### Timeouts

The SDK has no timeout option, and cannot have one: it evaluates through the
PSR-18 client *you* supply, and PSR-18's `sendRequest()` exposes no timeout or
cancellation hook, so the SDK cannot bound a call it did not originate. Set the
timeout on the client you inject:

```php
new GuzzleHttp\Client(['timeout' => 5, 'connect_timeout' => 2])
```

This matters more than it looks: the configuration fetch runs synchronously on
the calling thread, so a client with no timeout (Guzzle's default is `0`, i.e.
none) can block a request indefinitely against an evaluation API that accepts
the connection and then stalls.

### Logging

The SDK reports anything that stops it loading a flag configuration — an
unreachable evaluation API, a rejected SDK key, a flag it had to skip. Pass your
application's PSR-3 logger to get those where you actually read them:

```php
$config = new Config(
    logger: $monolog,
    // ...
);
```

Omit it and the SDK writes to PHP's error log instead — never nowhere, because a
configuration the SDK cannot load is indistinguishable from an empty one, and
every flag then serves its caller default. A repeated failure is reported once
per poll interval rather than once per request, so an outage does not flood the
log.

### Long-running workers

Under PHP-FPM every request builds a fresh client, so the SDK refreshes its
configuration and ships its events naturally. Under a persistent worker —
Laravel Octane, RoadRunner, FrankenPHP, Swoole — the client lives for thousands
of requests instead, and the SDK adapts to that on its own: the configuration
refreshes on the evaluation path once the poll interval has elapsed, and queued
events drain when they reach `flushBatchSize` or when `flushInterval` passes,
whichever comes first.

A batch the events endpoint cannot take right now — a 5xx, a 429, a refused
connection — is kept and retried on the next flush rather than thrown away, at
most once per `flushInterval` so a struggling endpoint is not hammered. A batch
it rejects outright (a bad SDK key, a malformed body) is dropped, because
retrying it would never succeed. Either way the SDK says so through your logger.
The queue holds at most 10,000 events; past that the oldest are shed, so a long
outage costs bounded memory rather than an unbounded buffer.

Nothing is required of you. If you would rather the refresh happened *between*
requests than inside one, call `refresh()` from your worker's tick hook:

```php
// Laravel Octane
Octane::tick('featureflip', fn () => $client->refresh())->seconds(10);
```

`refresh()` honours the poll interval and the failure backoff, so a tick that
finds nothing due does no work. With `pollInterval: 0` every tick is due, so
pick an interval that matches how quickly you need changes to land.

### Readiness

`isInitialized()` tells you whether the SDK holds a flag configuration at all:

```php
if (!$client->isInitialized()) {
    // Nothing was ever loaded — every evaluation is returning the default you
    // pass. Usually a rejected SDK key, or an unreachable evaluation API with
    // no cached snapshot to fall back on.
}
```

It answers "was anything ever loaded", not "is it current". A configuration
retained through an outage still counts — the SDK is out of date, not
uninitialised. A closed handle always reports `false`.

### Resilience

Once a configuration has been fetched successfully it is cached and **kept**.
The poll interval controls when the SDK tries to refresh, not how long the
cached copy survives — so if the evaluation API is unreachable, flags keep
serving their last known good values rather than falling back to the defaults
passed at each call site.

Alongside that:

- A refresh that fails backs off for one poll interval instead of re-dialling on
  every request.
- A single malformed flag is skipped and logged rather than discarding the whole
  configuration.
- A response that arrives successfully but carries no usable flags is refused,
  so a degraded response cannot empty a working configuration.

Retention is best-effort, as caching always is: an eviction-based backend
(Redis, Memcached) may still drop the entry under memory pressure, and a cache
configured with its own default lifetime will expire it on that schedule. Either
case degrades to an ordinary cold start.

### PSR Dependencies

The SDK uses PSR interfaces for HTTP and caching, so you can bring your preferred implementations:

```bash
# Example with Guzzle + Symfony Cache
composer require guzzlehttp/guzzle symfony/cache
```

## Lifetime

`FeatureflipClient::get()` is a **singleton-by-construction factory**. Calling it multiple times with the same SDK key returns handles that share a single underlying connection and flag store. This means:

- **Safe with any DI lifetime** — registering as singleton, scoped, or transient all work correctly. Scoped/transient registration creates lightweight handles, not duplicate connections.
- **Refcounted disposal** — each `close()` decrements the refcount. Resources are only cleaned up when the last handle closes.
- **Different SDK keys** create independent instances (for multi-environment setups).

```php
$h1 = FeatureflipClient::get('sdk-key-123', $config);
$h2 = FeatureflipClient::get('sdk-key-123'); // Same core, no config needed
// Both $h1 and $h2 share the same flag store and connection

$h1->close(); // Refcount decremented, but core stays alive
$h2->close(); // Last handle — core shuts down
```

A closed handle is **inert**: variation calls return the default you pass,
`track()`/`identify()`/`flush()` do nothing, and no inspectors fire. Closing one
handle never affects a sibling still holding the same core.

## Evaluation

```php
$context = ['user_id' => '123', 'email' => 'user@example.com'];

// Boolean flag
$enabled = $client->boolVariation('feature-key', $context, false);

// String flag
$tier = $client->stringVariation('pricing-tier', $context, 'free');

// Number flag
$limit = $client->numberVariation('rate-limit', $context, 100);

// JSON flag
$config = $client->jsonVariation('ui-config', $context, ['theme' => 'light']);
```

### Detailed Evaluation

```php
$detail = $client->variationDetail('feature-key', ['user_id' => '123'], false);

echo $detail->value;        // The evaluated value
echo $detail->reason;       // "RULE_MATCH", "FALLTHROUGH", "FLAG_DISABLED", etc.
echo $detail->ruleId;       // Rule ID if reason is "RULE_MATCH"
echo $detail->variationKey; // Key of the matched variation
```

## Event Tracking

```php
// Track custom events
$client->track('checkout-completed', ['user_id' => '123'], ['total' => 99.99]);

// Record an identify event for analytics (does not affect flag evaluation)
$client->identify(['user_id' => '123', 'email' => 'user@example.com', 'plan' => 'pro']);

// Force flush pending events
$client->flush();
```

Featureflip counts each event name, per environment. The metadata you pass is transmitted with the event but is not stored, and event counts are not surfaced in the app or API.

## Evaluation Inspectors

Register callables on the config to observe every evaluation in-process — useful
for piping exposures into your own analytics tooling. Each inspector is invoked
synchronously, exactly once per variation call, on every exit path — including
`FLAG_NOT_FOUND` and the evaluator's own `ERROR` reason.

```php
use Featureflip\Config;
use Featureflip\EvaluationEvent;

$config = new Config(
    cache: $psrCache,
    httpClient: $psrHttpClient,
    requestFactory: $psrRequestFactory,
    streamFactory: $psrStreamFactory,
    inspectors: [
        function (EvaluationEvent $event) use ($analytics): void {
            $analytics->capture('feature_flag_called', [
                'flagKey' => $event->flagKey,
                'value' => $event->value,
                'variationKey' => $event->variationKey,
                'reason' => $event->reason,
            ]);
        },
    ],
);
```

`EvaluationEvent` carries `flagKey`, `context` (a copy of the full evaluation
context), `value` (what the caller receives, default applied), `variationKey`,
`reason`, `ruleId` (set only for `RULE_MATCH`), `prerequisiteKey` (set only for
`PREREQUISITE_FAILED`) and an ISO-8601 `timestamp`.

Inspectors are void observers and are isolated from evaluation: a throwing
inspector cannot change the returned value or stop the other inspectors — the
failure is written to the PHP error log. Non-callable entries are dropped when
the client is created. `inspectors` is honored on the first `FeatureflipClient::get()`
per SDK key, like every other config option.

## Testing

Use `forTesting()` to create a client with predetermined flag values -- no network calls.

```php
$client = FeatureflipClient::forTesting([
    'my-feature' => true,
    'pricing-tier' => 'pro',
]);

$client->boolVariation('my-feature', [], false);      // true
$client->stringVariation('pricing-tier', [], 'free');  // "pro"
$client->boolVariation('unknown', [], false);          // false (default)
```

## Laravel Integration

The package ships a service provider that Laravel discovers on its own. Install the SDK, plus Guzzle if the app doesn't already have it (Laravel's HTTP client depends on it), and set your key:

```bash
composer require featureflip/featureflip-php
```

```dotenv
FEATUREFLIP_SDK_KEY=your-server-sdk-key
```

Then type-hint the client anywhere the container resolves:

```php
use Featureflip\FeatureflipClient;

public function index(FeatureflipClient $client)
{
    $enabled = $client->boolVariation('new-checkout', ['user_id' => auth()->id()], false);
}
```

A `Featureflip` facade is registered too: `Featureflip::boolVariation('new-checkout', [], false)`.

To change the defaults, publish the config file and edit `config/featureflip.php`:

```bash
php artisan vendor:publish --tag=featureflip-config
```

| Key | Env var | Default |
|-----|---------|---------|
| `sdk_key` | `FEATUREFLIP_SDK_KEY` | none (required) |
| `base_url` | `FEATUREFLIP_BASE_URL` | `https://eval.featureflip.io` |
| `poll_interval` | `FEATUREFLIP_POLL_INTERVAL` | `30` seconds |
| `flush_interval` | `FEATUREFLIP_FLUSH_INTERVAL` | `30` seconds |
| `flush_batch_size` | `FEATUREFLIP_FLUSH_BATCH_SIZE` | `100` |
| `cache_store` | `FEATUREFLIP_CACHE_STORE` | your default cache store |
| `http_timeout`, `connect_timeout` | `FEATUREFLIP_HTTP_TIMEOUT`, `FEATUREFLIP_CONNECT_TIMEOUT` | `5`, `2` seconds |
| `log_channel` | `FEATUREFLIP_LOG_CHANNEL` | your default log channel |

The flag configuration is stored in the cache store, so point `cache_store` at one every worker shares (Redis, database, Memcached or file). The `array` store lives for one process, so under PHP-FPM every request would refetch.

The provider builds a Guzzle client with the timeouts above. To use your own PSR-18 client, bind `Psr\Http\Client\ClientInterface`, `Psr\Http\Message\RequestFactoryInterface` and `Psr\Http\Message\StreamFactoryInterface` in the container and the provider uses those instead. It sets the SDK's wrapper name so requests carry a `featureflip-laravel/<Laravel version>` User-Agent token.

A missing SDK key throws an `InvalidArgumentException` the first time the client is resolved, and the message never contains the key.

### PHP-FPM and Octane

The client is a container singleton, and `FeatureflipClient::get()` shares one flag store per SDK key underneath it. Under PHP-FPM the process ends with each request, so the singleton lives for one request: it reads the configuration from the shared cache and flushes queued events when the process shuts down. Under Octane, RoadRunner or FrankenPHP the worker keeps the client across requests, and the SDK refreshes the configuration and drains events on its own schedule. Nothing in the provider closes the client per request, because a closed client stops evaluating.

## Migrating from 2.x

Two breaking changes.

**`initTimeout` is gone.** It never did anything. Set the timeout on the PSR-18
client you inject instead — that is the only place it can live:

```php
// Before (2.x) — had no effect
new Config(initTimeout: 5, httpClient: new GuzzleHttp\Client(), ...);

// After (3.x)
new Config(httpClient: new GuzzleHttp\Client(['timeout' => 5]), ...);
```

If you were passing `initTimeout`, you had no timeout at all; Guzzle's default
is `0`, meaning none. Setting one is the actual fix.

**A closed handle is now inert.** Variation calls return the default you pass,
`variationDetail()` reports reason `ERROR`, and `track()`/`identify()`/`flush()`
do nothing. Previously they carried on as if the handle were open. If you relied
on evaluating after `close()`, keep the handle open — or take a fresh one with
`FeatureflipClient::get($sdkKey)`, which is free while any handle is alive.

## Migrating from 1.x

```php
// Before (1.x)
$client = Client::create('sdk-key', $config);

// After (2.x)
$client = FeatureflipClient::get('sdk-key', $config);
```

The class was renamed from `Client` to `FeatureflipClient` and the factory from `create()` to `get()`. All evaluation methods are unchanged.

## Features

- **Local evaluation** - Near-zero latency after initialization
- **Singleton-by-construction** - Safe with any DI lifetime
- **Refreshes on demand** - Re-fetches when the poll interval lapses; no background thread, because PHP has none
- **Event tracking** - Automatic batching and flushing
- **Evaluation inspectors** - In-process `onEvaluation` hook for analytics/debugging
- **Test support** - `forTesting()` factory for deterministic unit tests
- **Resilient** - Serves last-known-good configuration through an evaluation API outage
- **Laravel-ready** - Auto-discovered service provider, publishable config and facade
- **Worker-aware** - Refreshes flags and drains events under Octane/RoadRunner/FrankenPHP/Swoole
- **Introspectable** - `isInitialized()` reports whether a configuration was ever loaded
- **PSR-compatible** - Uses PSR-16 (cache), PSR-17/18 (HTTP), PSR-3 (logging)

## Requirements

- PHP 8.2+

## License

Apache-2.0
