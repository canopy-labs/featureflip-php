# Featureflip PHP SDK

PHP SDK for [Featureflip](https://featureflip.io) - evaluate feature flags locally with near-zero latency.

## Installation

```bash
composer require featureflip/sdk
```

## Quick Start

```php
<?php

use Featureflip\Client;

$client = Client::create('your-sdk-key');

$enabled = $client->boolVariation('my-feature', ['user_id' => 'user-123'], false);

if ($enabled) {
    echo "Feature is enabled!";
}

$client->close();
```

## Configuration

```php
use Featureflip\Client;
use Featureflip\Config;

$config = new Config(
    baseUrl: 'https://eval.featureflip.io',  // Evaluation API URL (default)
    pollInterval: 30,                         // Polling interval in seconds
    flushInterval: 30,                        // Event flush interval in seconds
    flushBatchSize: 100,                      // Events per batch
    initTimeout: 10,                          // Max seconds to wait for initialization
    cache: $psrCache,                         // PSR-16 CacheInterface
    httpClient: $psrHttpClient,               // PSR-18 ClientInterface
    requestFactory: $psrRequestFactory,       // PSR-17 RequestFactoryInterface
    streamFactory: $psrStreamFactory,         // PSR-17 StreamFactoryInterface
);

$client = Client::create('your-sdk-key', $config);
```

The SDK key can also be set via the `FEATUREFLIP_SDK_KEY` environment variable.

### PSR Dependencies

The SDK uses PSR interfaces for HTTP and caching, so you can bring your preferred implementations:

```bash
# Example with Guzzle + Symfony Cache
composer require guzzlehttp/guzzle symfony/cache
```

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

// Identify users for segment building
$client->identify(['user_id' => '123', 'email' => 'user@example.com', 'plan' => 'pro']);

// Force flush pending events
$client->flush();
```

## Testing

Use `forTesting()` to create a client with predetermined flag values -- no network calls.

```php
$client = Client::forTesting([
    'my-feature' => true,
    'pricing-tier' => 'pro',
]);

$client->boolVariation('my-feature', [], false);      // true
$client->stringVariation('pricing-tier', [], 'free');  // "pro"
$client->boolVariation('unknown', [], false);          // false (default)
```

## Laravel Integration

```php
// In a service provider
$this->app->singleton(Client::class, function () {
    return Client::create(config('services.featureflip.sdk_key'));
});

// In a controller
public function index(Client $client)
{
    $enabled = $client->boolVariation('new-checkout', ['user_id' => auth()->id()], false);
}
```

## Features

- **Local evaluation** - Near-zero latency after initialization
- **Polling updates** - Automatic background flag refresh
- **Event tracking** - Automatic batching and flushing
- **Test support** - `forTesting()` factory for deterministic unit tests
- **PSR-compatible** - Uses PSR-16 (cache), PSR-17/18 (HTTP)

## Requirements

- PHP 8.2+

## License

MIT
