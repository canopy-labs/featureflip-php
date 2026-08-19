<?php

declare(strict_types=1);

namespace Featureflip\Tests\Logging;

use Featureflip\{Config, FeatureflipClient};
use Featureflip\Logging\RedactingLogger;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\{AbstractLogger, LogLevel};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * The SDK key must never reach the host application's log (#2266).
 *
 * Failure paths log the underlying exception verbatim, and that exception comes
 * from a PSR-18 client the caller supplied. The SDK sets the key as the
 * `Authorization` header on every request, so any client that quotes request
 * headers in its exception message hands the credential to whatever the host
 * logs to — commonly a third-party aggregator, where it becomes a rotation
 * event. Guzzle happens not to; the SDK cannot rely on which client it is given.
 */
final class RedactingLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    // --- The decorator itself ---------------------------------------------

    public function testTheKeyIsReplacedInTheMessage(): void
    {
        $inner = new RecordingLogger();
        $logger = new RedactingLogger($inner, self::SDK_KEY);

        $logger->warning('fetch failed: GET /v1/sdk/flags [Authorization: ' . self::SDK_KEY . ']');

        $this->assertStringNotContainsString(self::SDK_KEY, $inner->lines[0]);
        $this->assertStringContainsString('[redacted]', $inner->lines[0]);
    }

    public function testTheRestOfTheMessageSurvives(): void
    {
        $inner = new RecordingLogger();
        $logger = new RedactingLogger($inner, self::SDK_KEY);

        $logger->warning('fetch failed: GET /v1/sdk/flags [Authorization: ' . self::SDK_KEY . '] - serving cache');

        $this->assertSame(
            'fetch failed: GET /v1/sdk/flags [Authorization: [redacted]] - serving cache',
            $inner->lines[0],
            'Redaction must be surgical — the surrounding diagnostic is the whole point of logging',
        );
    }

    public function testEveryOccurrenceIsReplaced(): void
    {
        $inner = new RecordingLogger();
        $logger = new RedactingLogger($inner, self::SDK_KEY);

        $logger->warning(self::SDK_KEY . ' appeared, then ' . self::SDK_KEY . ' again');

        $this->assertSame('[redacted] appeared, then [redacted] again', $inner->lines[0]);
    }

    public function testTheLevelIsPassedThrough(): void
    {
        $inner = new RecordingLogger();
        $logger = new RedactingLogger($inner, self::SDK_KEY);

        $logger->error('boom');

        $this->assertSame(LogLevel::ERROR, $inner->levels[0]);
    }

    public function testTheContextArrayIsPassedThrough(): void
    {
        $inner = new RecordingLogger();
        $logger = new RedactingLogger($inner, self::SDK_KEY);

        $logger->warning('boom', ['flagKey' => 'checkout']);

        $this->assertSame(['flagKey' => 'checkout'], $inner->contexts[0]);
    }

    public function testAnEmptyKeyDoesNotMangleTheMessage(): void
    {
        $inner = new RecordingLogger();
        $logger = new RedactingLogger($inner, '');

        $logger->warning('nothing to hide here');

        $this->assertSame('nothing to hide here', $inner->lines[0]);
    }

    /**
     * A real key is 54 characters. A value far shorter than that is not a
     * credential, and redacting it corrupts every message it appears inside —
     * with the key `k`, "skipped a malformed flag" became "s[redacted]ipped a
     * malformed flag" in this SDK's own suite. Pinned so the trade-off is a
     * decision rather than an accident.
     */
    public function testAValueTooShortToBeAKeyIsLeftAlone(): void
    {
        $inner = new RecordingLogger();
        $logger = new RedactingLogger($inner, 'k');

        $logger->warning('skipped a malformed flag');

        $this->assertSame('skipped a malformed flag', $inner->lines[0]);
    }

    public function testAStringableMessageIsHandled(): void
    {
        $inner = new RecordingLogger();
        $logger = new RedactingLogger($inner, self::SDK_KEY);

        $logger->warning(new class () implements \Stringable {
            public function __toString(): string
            {
                return 'saw ' . RedactingLoggerTest::SDK_KEY;
            }
        });

        $this->assertSame('saw [redacted]', $inner->lines[0]);
    }

    public function testAThrowingInnerLoggerIsStillContained(): void
    {
        $logger = new RedactingLogger(
            new class () extends AbstractLogger {
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    throw new \RuntimeException('log sink unavailable');
                }
            },
            self::SDK_KEY,
        );

        $this->expectException(\RuntimeException::class);
        $logger->warning('boom');
    }

    // --- Wired up, through the paths that actually carry the key -----------

    public function testTheConfigFetchFailurePathDoesNotLeakTheKey(): void
    {
        $inner = new RecordingLogger();
        $this->clientAgainstAnEchoingHttpClient($inner);

        $this->assertNotSame([], $inner->lines, 'The fetch failure must still be reported');
        foreach ($inner->lines as $line) {
            $this->assertStringNotContainsString(self::SDK_KEY, $line);
        }
    }

    public function testTheEventDeliveryFailurePathDoesNotLeakTheKey(): void
    {
        $inner = new RecordingLogger();
        $client = $this->clientAgainstAnEchoingHttpClient($inner);

        $client->track('checkout', ['user_id' => 'u']);
        $client->flush();

        $this->assertNotSame([], $inner->lines);
        foreach ($inner->lines as $line) {
            $this->assertStringNotContainsString(self::SDK_KEY, $line);
        }
    }

    /**
     * The default logger writes to PHP's error log, which is just as much a
     * place a credential must not appear.
     */
    public function testTheDefaultErrorLogPathDoesNotLeakTheKey(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ff-');
        $previous = ini_set('error_log', $file);

        try {
            $this->clientAgainstAnEchoingHttpClient(null);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $contents = (string) file_get_contents($file);
        unlink($file);

        $this->assertStringContainsString('[featureflip]', $contents, 'The failure must still be reported');
        $this->assertStringNotContainsString(self::SDK_KEY, $contents);
    }

    // --- Fixtures ----------------------------------------------------------

    public const SDK_KEY = 'sdk_server_' . 'aGVsbG8td29ybGQtdGhpcy1pcy1hLXRlc3Qta2V5LTEyMzQ1Ng';

    /**
     * A PSR-18 client that quotes the request headers in its exception, as
     * debug-oriented clients and logging middleware do.
     */
    private function clientAgainstAnEchoingHttpClient(?RecordingLogger $logger): FeatureflipClient
    {
        $factory = new HttpFactory();
        $http = new class () implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class (
                    'Request failed: ' . $request->getMethod() . ' ' . $request->getUri()
                    . ' [Authorization: ' . $request->getHeaderLine('Authorization') . ']'
                ) extends \RuntimeException implements ClientExceptionInterface {};
            }
        };

        return FeatureflipClient::get(self::SDK_KEY, new Config(
            baseUrl: 'http://eval.test',
            pollInterval: 1,
            cache: new Psr16Cache(new ArrayAdapter()),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            logger: $logger,
        ));
    }
}

/** Captures what the host application's logger would actually receive. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<mixed> */
    public array $levels = [];

    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->levels[] = $level;
        $this->lines[] = (string) $message;
        $this->contexts[] = $context;
    }
}
