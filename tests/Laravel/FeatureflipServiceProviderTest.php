<?php

declare(strict_types=1);

namespace Featureflip\Tests\Laravel;

use Featureflip\FeatureflipClient;
use Featureflip\Laravel\Facades\Featureflip;
use Featureflip\Laravel\FeatureflipServiceProvider;
use GuzzleHttp\Psr7\HttpFactory;
use Orchestra\Testbench\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class FeatureflipServiceProviderTest extends TestCase
{
    private const KEY = 'sdk_server_' . 'bGFyYXZlbC1wcm92aWRlci10ZXN0LWtleS0xMjM0NTY3OA';

    private RecordingApi $api;

    protected function getPackageProviders($app): array
    {
        return [FeatureflipServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Featureflip' => Featureflip::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('featureflip.sdk_key', self::KEY);
        $app['config']->set('featureflip.base_url', 'http://eval.test');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]);

        $this->api = new RecordingApi();
        $factory = new HttpFactory();
        $this->api->factory = $factory;
        $app->instance(ClientInterface::class, $this->api);
        $app->instance(RequestFactoryInterface::class, $factory);
        $app->instance(StreamFactoryInterface::class, $factory);
    }

    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
        parent::tearDown();
    }

    private function laravel(): \Illuminate\Foundation\Application
    {
        return $this->app ?? throw new \LogicException('Application not booted');
    }

    public function testTheClientResolvesAsOneSingleton(): void
    {
        $first = $this->laravel()->make(FeatureflipClient::class);
        $second = $this->laravel()->make(FeatureflipClient::class);

        $this->assertInstanceOf(FeatureflipClient::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->api->fetches, 'One configuration fetch for the whole container');
    }

    public function testTheShortAliasResolvesTheSameClient(): void
    {
        $this->assertSame(
            $this->laravel()->make(FeatureflipClient::class),
            $this->laravel()->make('featureflip'),
        );
    }

    public function testTheFacadeProxiesTheClient(): void
    {
        $this->assertSame('fallback', Featureflip::stringVariation('missing', [], 'fallback'));
        $this->assertSame(1, $this->api->fetches);
    }

    public function testConfigValuesReachTheClient(): void
    {
        $this->laravel()['config']->set('featureflip.base_url', 'http://other.test/');

        $this->laravel()->make(FeatureflipClient::class);

        $this->assertSame('http://other.test/v1/sdk/flags', $this->api->lastUrl);
        $this->assertSame(self::KEY, $this->api->lastAuthorization);
    }

    public function testTheUserAgentNamesTheIntegration(): void
    {
        $this->laravel()->make(FeatureflipClient::class);

        $this->assertSame(
            'featureflip-laravel/' . $this->laravel()->version(),
            explode(' ', (string) $this->api->lastUserAgent, 2)[1] ?? null,
        );
        $this->assertMatchesRegularExpression('#^featureflip-php/\S+ #', (string) $this->api->lastUserAgent);
    }

    public function testPollIntervalComesFromConfig(): void
    {
        $this->laravel()['config']->set('featureflip.poll_interval', 3600);
        $client = $this->laravel()->make(FeatureflipClient::class);

        $client->boolVariation('x', [], false);
        $client->refresh();

        $this->assertSame(1, $this->api->fetches, 'A long poll interval keeps the cached configuration fresh');
    }

    public function testAMissingSdkKeyFailsWithAClearMessage(): void
    {
        $this->laravel()['config']->set('featureflip.sdk_key', null);

        try {
            $this->laravel()->make(FeatureflipClient::class);
            $this->fail('Expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('FEATUREFLIP_SDK_KEY', $e->getMessage());
            $this->assertStringContainsString('featureflip.sdk_key', $e->getMessage());
        }
    }

    public function testTheConfigFileIsPublishable(): void
    {
        $paths = FeatureflipServiceProvider::pathsToPublish(FeatureflipServiceProvider::class, 'featureflip-config');

        $this->assertCount(1, $paths);
        $source = (string) array_key_first($paths);
        $this->assertFileExists($source);
        $this->assertStringEndsWith('config/featureflip.php', $source);
        $this->assertStringEndsWith('config/featureflip.php', (string) reset($paths));
    }

    public function testTheDefaultHttpClientNeedsNoContainerBinding(): void
    {
        $this->laravel()->forgetInstance(ClientInterface::class);
        $this->laravel()['config']->set('featureflip.base_url', 'http://127.0.0.1:1');

        // The fetch is refused straight away; the SDK logs it and serves defaults.
        $client = $this->laravel()->make(FeatureflipClient::class);

        $this->assertFalse($client->boolVariation('x', [], false));
    }
}
