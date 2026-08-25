<?php

declare(strict_types=1);

namespace Featureflip\DataSource;

use Featureflip\Http\HttpClient;
use Featureflip\Logging\ErrorLogLogger;
use Featureflip\Model\{Flag, Segment, UnevaluableEntityException};
use Featureflip\Store\FlagStore;
use Psr\Log\LoggerInterface;

final class Poller
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly FlagStore $store,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new ErrorLogLogger();
    }

    public function fetch(): void
    {
        $data = $this->httpClient->get('/v1/sdk/flags');

        $flags = $this->parseEach($data['flags'] ?? [], Flag::fromArray(...), 'flag');
        $segments = $this->parseEach($data['segments'] ?? [], Segment::fromArray(...), 'segment');

        // A 200 carrying nothing usable — an empty list, a missing `flags` key,
        // a payload where every entry was skipped — must not overwrite a
        // working configuration. Publishing it would reach the exact outcome
        // this guard exists to prevent (every flag serving its caller default),
        // just through a successful response rather than a failed one. The
        // deliberate trade-off is that genuinely deleting every flag in an
        // environment won't propagate until the SDK is restarted; that is
        // logged, and is much the smaller harm.
        if ($flags === [] && !$this->store->isEmpty()) {
            $this->logger->warning(
                'the evaluation API returned no usable flags; keeping the last known good configuration rather than emptying it',
            );

            return;
        }

        $this->store->putAll($flags, $segments);
    }

    /**
     * Parse each entry independently, skipping the ones that don't parse.
     *
     * A single `array_map` over the whole payload meant one unparseable entry
     * threw, aborted the fetch, and left the store with NOTHING — every flag in
     * the environment then served its caller default with reason
     * FLAG_NOT_FOUND, and because nothing was ever written to the cache the
     * failure repeated on every subsequent request rather than self-healing
     * (#2258). Losing one malformed flag is a far smaller blast radius than
     * losing all of them.
     *
     * This is stricter than the sibling server SDKs, which abort the whole
     * parse — they get away with it because they are long-lived processes
     * holding an in-memory snapshot that survives a failed poll. A
     * request-scoped PHP process has no such fallback, so the equivalent
     * outcome has to be reached by skipping rather than by retrying.
     *
     * @template T
     * @param  mixed                            $entries
     * @param  callable(array<string, mixed>): T $parse
     * @return list<T>
     */
    private function parseEach(mixed $entries, callable $parse, string $kind): array
    {
        if (!is_iterable($entries)) {
            $this->logger->warning(sprintf(
                'the flag configuration\'s %s list was %s, not a list; ignoring it',
                $kind,
                get_debug_type($entries),
            ));

            return [];
        }

        $parsed = [];
        foreach ($entries as $entry) {
            try {
                $parsed[] = $parse($entry);
            } catch (UnevaluableEntityException $e) {
                // Well-formed, just not evaluable by this build — a newer server
                // describing behaviour this SDK version does not implement (#2402).
                // Called out separately from the malformed case below because calling a
                // valid payload malformed sends whoever reads this log looking for a
                // server bug that isn't there; the fix is to upgrade the SDK.
                $this->logger->warning(sprintf(
                    'dropped a %s this SDK version cannot evaluate (%s): %s; the rest of the configuration was applied',
                    $kind,
                    is_array($entry) && isset($entry['key']) && is_scalar($entry['key'])
                        ? 'key ' . $entry['key']
                        : 'no usable key',
                    $e->getMessage(),
                ));
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'skipped a malformed %s in the flag configuration (%s): %s',
                    $kind,
                    is_array($entry) && isset($entry['key']) && is_scalar($entry['key'])
                        ? 'key ' . $entry['key']
                        : 'no usable key',
                    $e->getMessage(),
                ));
            }
        }

        return $parsed;
    }
}
