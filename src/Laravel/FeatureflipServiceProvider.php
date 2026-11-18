<?php

declare(strict_types=1);

namespace Featureflip\Laravel;

use Featureflip\Config;
use Featureflip\FeatureflipClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Registers {@see FeatureflipClient} in the Laravel container.
 *
 * A thin wrapper: the client comes from `FeatureflipClient::get()`, which is
 * already one shared core per SDK key, so there is no lifetime logic here.
 * Laravel is not a dependency of this package; this class only loads in an app
 * that has `illuminate/support`.
 */
final class FeatureflipServiceProvider extends ServiceProvider
{
    public const WRAPPER_NAME = 'featureflip-laravel';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/featureflip.php', 'featureflip');

        $this->app->singleton(FeatureflipClient::class, fn (Application $app): FeatureflipClient => $this->makeClient($app));
        $this->app->alias(FeatureflipClient::class, 'featureflip');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/featureflip.php' => $this->app->configPath('featureflip.php'),
            ], 'featureflip-config');
        }
    }

    private function makeClient(Application $app): FeatureflipClient
    {
        /** @var array<string, mixed> $settings */
        $settings = $app->make('config')->get('featureflip', []);

        $sdkKey = $settings['sdk_key'] ?? null;
        if (!is_string($sdkKey) || $sdkKey === '') {
            throw new \InvalidArgumentException(
                'Featureflip SDK key is not set: set FEATUREFLIP_SDK_KEY in your environment or featureflip.sdk_key in config/featureflip.php',
            );
        }

        $requestFactory = $this->bound($app, RequestFactoryInterface::class);
        $streamFactory = $this->bound($app, StreamFactoryInterface::class);
        $httpClient = $this->bound($app, ClientInterface::class);

        if ($httpClient === null || $requestFactory === null || $streamFactory === null) {
            if (!class_exists(\GuzzleHttp\Client::class) || !class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
                throw new \RuntimeException(
                    'Featureflip needs a PSR-18 client and PSR-17 factories: run "composer require guzzlehttp/guzzle", or bind Psr\Http\Client\ClientInterface, Psr\Http\Message\RequestFactoryInterface and Psr\Http\Message\StreamFactoryInterface in the container',
                );
            }
            $psr17 = new \GuzzleHttp\Psr7\HttpFactory();
            $requestFactory ??= $psr17;
            $streamFactory ??= $psr17;
            $httpClient ??= new \GuzzleHttp\Client([
                // The SDK fetches configuration on the calling thread, so the
                // timeouts bound a request that finds the cache stale.
                'timeout' => (float) ($settings['http_timeout'] ?? 5),
                'connect_timeout' => (float) ($settings['connect_timeout'] ?? 2),
            ]);
        }

        $cacheStore = $settings['cache_store'] ?? null;
        $logChannel = $settings['log_channel'] ?? null;

        return FeatureflipClient::get($sdkKey, new Config(
            baseUrl: (string) ($settings['base_url'] ?? 'https://eval.featureflip.io'),
            pollInterval: (int) ($settings['poll_interval'] ?? 30),
            flushInterval: (int) ($settings['flush_interval'] ?? 30),
            flushBatchSize: (int) ($settings['flush_batch_size'] ?? 100),
            // Illuminate's cache repository implements PSR-16.
            cache: $app->make('cache')->store(is_string($cacheStore) && $cacheStore !== '' ? $cacheStore : null),
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            logger: $app->make('log')->channel(is_string($logChannel) && $logChannel !== '' ? $logChannel : null),
            wrapperName: self::WRAPPER_NAME,
            wrapperVersion: $app->version(),
        ));
    }

    /**
     * @template T of object
     * @param class-string<T> $abstract
     * @return T|null
     */
    private function bound(Application $app, string $abstract): ?object
    {
        return $app->bound($abstract) ? $app->make($abstract) : null;
    }
}
