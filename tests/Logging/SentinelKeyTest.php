<?php

declare(strict_types=1);

namespace Featureflip\Tests\Logging;

use Featureflip\{Config, FeatureflipClient};
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\{ClientExceptionInterface, ClientInterface};
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Behavioural guard for #5166: drive every noisy path with a sentinel key and
 * prove it appears in no log line, no PHP error-log line and no exception
 * message the SDK raises. The stub's transport error quotes the Authorization
 * header, so this exercises the redactor rather than just clean call sites.
 */
final class SentinelKeyTest extends TestCase
{
    private const KEY = 'sdk_server_SentinelKeyMustNeverAppearInLogs-0123456789';

    private SentinelLogger $log;
    private string $errorLogFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        FeatureflipClient::resetForTesting();
        $this->log = new SentinelLogger();
        $this->errorLogFile = (string) tempnam(sys_get_temp_dir(), 'ff-sentinel-');
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errorLogFile);
    }

    /** @return array<string, array{string, int|null}> */
    public static function initOutcomes(): array
    {
        return [
            'ok' => ['ok', 200],
            '401 response' => ['status', 401],
            '500 response' => ['status', 500],
            'transport error quoting the Authorization header' => ['transport', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('initOutcomes')]
    public function testInitAndEveryFollowUpPathNeverLeakTheKey(string $mode, ?int $status): void
    {
        $stub = new SentinelHttp($mode, $status ?? 200);
        $caught = [];

        try {
            $client = $this->client($stub);

            $this->guard($caught, fn () => $client->boolVariation('flag-bool', ['user_id' => 'u'], false));
            // Type mismatch: a bool flag read as a string.
            $this->guard($caught, fn () => $client->stringVariation('flag-bool', ['user_id' => 'u'], 'd'));
            // Unknown flag.
            $this->guard($caught, fn () => $client->boolVariation('no-such-flag', ['user_id' => 'u'], false));
            $this->guard($caught, fn () => $client->variationDetail('flag-bool', ['user_id' => 'u'], false));

            // Events endpoint failing, as transport error or 500.
            $stub->failEvents = true;
            $this->guard($caught, fn () => $client->track('purchase', ['user_id' => 'u'], ['k' => 'v']));
            $this->guard($caught, fn () => $client->identify(['user_id' => 'u']));
            $this->guard($caught, fn () => $client->flush());
            $this->guard($caught, fn () => $client->refresh());

            // Same key, different options: the existing core is reused.
            $this->guard($caught, fn () => FeatureflipClient::get(self::KEY, new Config(
                baseUrl: 'http://other.test',
                pollInterval: 5,
                cache: new Psr16Cache(new ArrayAdapter()),
                httpClient: $stub,
                requestFactory: new HttpFactory(),
                streamFactory: new HttpFactory(),
                logger: $this->log,
            )));

            $this->guard($caught, fn () => $client->close());
        } catch (\Throwable $e) {
            $caught[] = $e;
        }

        $this->assertNoKey($caught);
    }

    public function testAnExceptionPassedAsPsr3ContextIsRedacted(): void
    {
        $inner = new SentinelLogger();
        $logger = new \Featureflip\Logging\RedactingLogger($inner, self::KEY);

        $logger->warning('boom', [
            'exception' => new \RuntimeException('quoted [Authorization: ' . self::KEY . ']'),
            'header' => 'Authorization: ' . self::KEY,
            'nested' => ['k' => self::KEY],
            'safe' => 'checkout',
        ]);

        $this->assertStringNotContainsString(self::KEY, $inner->dump());
        $this->assertStringContainsString('[redacted]', $inner->dump());
        $this->assertSame('checkout', $inner->contexts[0]['safe']);
    }

    public function testAnExceptionWithoutTheKeyKeepsItsIdentityInContext(): void
    {
        $inner = new SentinelLogger();
        $logger = new \Featureflip\Logging\RedactingLogger($inner, self::KEY);
        $e = new \RuntimeException('unrelated');

        $logger->warning('boom', ['exception' => $e]);

        $this->assertSame($e, $inner->contexts[0]['exception']);
    }

    public function testAShortKeyIsNotRedactedInContext(): void
    {
        $inner = new SentinelLogger();
        $logger = new \Featureflip\Logging\RedactingLogger($inner, 'k');

        $logger->warning('boom', ['v' => 'skipped']);

        $this->assertSame('skipped', $inner->contexts[0]['v']);
    }

    public function testTheDefaultErrorLogSinkRedactsATransportError(): void
    {
        $caught = [];
        $this->guard($caught, function (): void {
            $client = $this->client(new SentinelHttp('transport', 200), true);
            $client->track('purchase', ['user_id' => 'u']);
            $client->flush();
            $client->close();
        });

        $contents = (string) file_get_contents($this->errorLogFile);
        $this->assertStringContainsString('[redacted]', $contents);
        $this->assertNoKey($caught);
    }

    /** @return array<string, array{bool}> */
    public static function sinks(): array
    {
        return ['capturing logger' => [false], 'default error-log sink' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sinks')]
    public function testAKeyQuotingInspectorExceptionIsRedacted(bool $defaultSink): void
    {
        $client = $this->client(new SentinelHttp('ok', 200), $defaultSink, [
            static function (): void {
                throw new \RuntimeException('inspector failed [Authorization: ' . self::KEY . ']');
            },
        ]);

        $this->assertTrue($client->boolVariation('flag-bool', ['user_id' => 'u'], false));

        $this->assertStringContainsString('evaluation inspector threw', $this->sinkText($defaultSink));
        $this->assertNoKey([]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sinks')]
    public function testAKeyQuotingEvaluatorExceptionIsRedacted(bool $defaultSink): void
    {
        $client = $this->client(new SentinelHttp('ok', 200), $defaultSink);
        $throwing = new class (self::KEY) implements \Stringable {
            public function __construct(private string $key) {}

            public function __toString(): string
            {
                throw new \RuntimeException('bucketing failed [Authorization: ' . $this->key . ']');
            }
        };

        $this->assertTrue($client->boolVariation('flag-rollout', ['user_id' => 'u', 'plan' => $throwing], true));

        $this->assertStringContainsString('flag evaluation threw', $this->sinkText($defaultSink));
        $this->assertNoKey([]);
    }

    public function testAThrowingHostLoggerNeverEscapesTheGuards(): void
    {
        $thrower = new class () extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \UnexpectedValueException('stream cannot be opened');
            }
        };
        $after = 0;
        $client = $this->client(new SentinelHttp('ok', 200), false, [
            static function (): void {
                throw new \RuntimeException('first inspector fails');
            },
            static function () use (&$after): void {
                $after++;
            },
        ], $thrower);
        $throwing = new class () implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('bucketing failed');
            }
        };

        $this->assertTrue($client->boolVariation('flag-rollout', ['user_id' => 'u', 'plan' => $throwing], true));
        $this->assertTrue($client->boolVariation('flag-bool', ['user_id' => 'u'], false));
        $this->assertGreaterThan(0, $after, 'later inspectors still run');
    }

    public function testADirtyThrowableIsSubstitutedWithAThrowableAcrossThePreviousChain(): void
    {
        $inner = new SentinelLogger();
        $logger = new \Featureflip\Logging\RedactingLogger($inner, self::KEY);
        $dirty = new \LogicException('outer ok', 3, new \DomainException('deep [' . self::KEY . ']'));

        $logger->warning('boom', ['exception' => $dirty]);

        $sub = $inner->contexts[0]['exception'];
        $this->assertInstanceOf(\RuntimeException::class, $sub);
        $this->assertSame('LogicException: outer ok', $sub->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $sub->getPrevious());
        $this->assertSame('DomainException: deep [[redacted]]', $sub->getPrevious()->getMessage());
        $this->assertStringNotContainsString(self::KEY, $sub->getTraceAsString());
    }

    public function testAKeyOnlyInTheTraceMakesAThrowableDirty(): void
    {
        $inner = new SentinelLogger();
        $logger = new \Featureflip\Logging\RedactingLogger($inner, self::KEY);
        $args = ini_set('zend.exception_ignore_args', '0');
        try {
            $e = (static fn (string $k): \RuntimeException => new \RuntimeException('clean message'))(self::KEY);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $args);
        }

        $logger->warning('boom', ['exception' => $e]);

        $this->assertNotSame($e, $inner->contexts[0]['exception']);
    }

    private function sinkText(bool $defaultSink): string
    {
        return $defaultSink ? (string) file_get_contents($this->errorLogFile) : $this->log->dump();
    }

    /** @param array<mixed> $inspectors */
    private function client(SentinelHttp $stub, bool $defaultSink = false, array $inspectors = [], ?\Psr\Log\LoggerInterface $logger = null): FeatureflipClient
    {
        $factory = new HttpFactory();

        return FeatureflipClient::get(self::KEY, new Config(
            baseUrl: 'http://eval.test',
            pollInterval: 1,
            flushBatchSize: 1,
            cache: new Psr16Cache(new ArrayAdapter()),
            httpClient: $stub,
            requestFactory: $factory,
            streamFactory: $factory,
            logger: $defaultSink ? null : ($logger ?? $this->log),
            inspectors: $inspectors,
        ));
    }

    /** @param list<\Throwable> $caught */
    private function guard(array &$caught, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $caught[] = $e;
        }
    }

    /** @param list<\Throwable> $caught */
    private function assertNoKey(array $caught): void
    {
        $this->assertStringNotContainsString(self::KEY, $this->log->dump(), 'key in captured log output');
        $this->assertStringNotContainsString(
            self::KEY,
            (string) file_get_contents($this->errorLogFile),
            'key in the PHP error log',
        );
        foreach ($caught as $e) {
            for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                $this->assertStringNotContainsString(self::KEY, $x->getMessage(), 'key in a raised exception');
            }
        }
    }
}

/** Captures every record, context included, at every level. */
final class SentinelLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = (string) $message;
        $this->contexts[] = $context;
    }

    public function dump(): string
    {
        $out = implode("\n", $this->lines);
        foreach ($this->contexts as $context) {
            array_walk_recursive($context, static function (mixed $v) use (&$out): void {
                $out .= "\n" . ($v instanceof \Throwable ? $v::class . ': ' . $v->getMessage() : (is_scalar($v) ? (string) $v : ''));
            });
        }

        return $out;
    }
}

/** PSR-18 stub: serves one flag, or fails the way the mode says. */
final class SentinelHttp implements ClientInterface
{
    public bool $failEvents = false;
    private HttpFactory $factory;

    public function __construct(private string $mode, private int $status)
    {
        $this->factory = new HttpFactory();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $isEvents = $request->getMethod() === 'POST';

        if ($this->mode === 'transport' || ($isEvents && $this->failEvents)) {
            throw new class (
                'Request failed: ' . $request->getMethod() . ' ' . $request->getUri()
                . ' [Authorization: ' . $request->getHeaderLine('Authorization') . ']'
            ) extends \RuntimeException implements ClientExceptionInterface {};
        }

        if ($this->mode === 'status') {
            return $this->factory->createResponse($this->status);
        }

        if ($isEvents) {
            return $this->factory->createResponse(200)->withBody($this->factory->createStream('{}'));
        }

        $body = json_encode([
            'environment' => 'test',
            'version' => 1,
            'flags' => [[
                'key' => 'flag-bool', 'version' => 1, 'type' => 'Boolean', 'enabled' => true,
                'variations' => [['key' => 'on', 'value' => true], ['key' => 'off', 'value' => false]],
                'rules' => [],
                'fallthrough' => ['type' => 'Fixed', 'variation' => 'on'],
                'offVariation' => 'off',
                'prerequisites' => [],
            ], [
                'key' => 'flag-rollout', 'version' => 1, 'type' => 'Boolean', 'enabled' => true,
                'variations' => [['key' => 'on', 'value' => true], ['key' => 'off', 'value' => false]],
                'rules' => [],
                'fallthrough' => ['type' => 'Rollout', 'salt' => 's', 'bucketBy' => 'plan', 'variations' => [
                    ['key' => 'on', 'weight' => 50], ['key' => 'off', 'weight' => 50],
                ]],
                'offVariation' => 'off',
                'prerequisites' => [],
            ]],
            'segments' => [],
        ], JSON_THROW_ON_ERROR);

        return $this->factory->createResponse(200)->withBody($this->factory->createStream($body));
    }
}
