<?php

declare(strict_types=1);

namespace Featureflip\Store;

use Featureflip\Model\{Flag, Segment, UnevaluableEntityException};
use Featureflip\Logging\ErrorLogLogger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

final class FlagStore
{
    private const FLAGS_KEY_PREFIX = 'featureflip_flags_';

    /**
     * How long a snapshot is retained — effectively forever.
     *
     * PSR-16 defines a null `$ttl` as "the driver MAY set a default", NOT "never
     * expires", and Symfony's adapters honour their `default_lifetime` for it —
     * so passing null would silently reinstate #2258 for anyone who has
     * configured one. A decade is the portable way to say "only a successful
     * fetch replaces this". Note that an LRU-evicting backend (Redis,
     * Memcached) can still drop the entry under memory pressure; retention is
     * best-effort by nature, and losing it degrades to a normal cold start.
     */
    private const RETENTION_TTL = 10 * 365 * 24 * 60 * 60;

    /** @var array<string, Flag> */
    private array $flags = [];

    /** @var array<string, Segment> */
    private array $segments = [];

    private bool $loaded = false;

    private LoggerInterface $logger;

    /** Unix time the currently-held snapshot was fetched, or null if none. */
    private ?int $fetchedAt = null;

    /**
     * In-process stand-in for the shared backoff marker, set only when that
     * marker could not be written. See recordFailedRefresh().
     */
    private ?int $localBackoffUntil = null;

    /**
     * @param int $ttl How long a snapshot stays *fresh* — the poll interval.
     *                 Deliberately NOT the cache entry's lifetime: entries are
     *                 written with no expiry so a snapshot outlives the moment
     *                 it becomes refetchable. Previously the two were the same
     *                 value, so the cache emptied itself exactly when the SDK
     *                 wanted to refresh it, and an evaluation-API outage longer
     *                 than the poll interval silently reverted every flag to
     *                 its caller default (#2258).
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $sdkKeyHash,
        private readonly int $ttl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new ErrorLogLogger();
        $this->loadFromCache();
    }

    public function getFlag(string $key): ?Flag
    {
        return $this->flags[$key] ?? null;
    }

    /**
     * @return array<string, Flag>
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * @return array<string, Segment>
     */
    public function getSegments(): array
    {
        return $this->segments;
    }

    /**
     * @param Flag[] $flags
     * @param Segment[] $segments
     */
    public function putAll(array $flags, array $segments): void
    {
        $this->flags = [];
        foreach ($flags as $flag) {
            $this->flags[$flag->key] = $flag;
        }

        $this->segments = [];
        foreach ($segments as $segment) {
            $this->segments[$segment->key] = $segment;
        }

        $this->loaded = true;
        $this->fetchedAt = time();
        $this->localBackoffUntil = null;
        $this->saveToCache();
    }

    /**
     * True once a snapshot has been taken — from a successful fetch or from
     * the cache — regardless of how many flags it contained.
     *
     * Distinct from {@see isEmpty()}, and the distinction is the point: an
     * environment with no flags yet loads successfully and holds an empty
     * snapshot, so `isEmpty()` cannot tell "loaded, and empty" from "never
     * loaded at all". Only the latter means every flag is serving its caller
     * default.
     */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * True when nothing is held — no cached snapshot and no successful fetch.
     * The difference between "serving slightly stale config" and "serving every
     * caller default" is the whole point, so the two are reported differently.
     */
    public function isEmpty(): bool
    {
        return $this->flags === [];
    }

    public function isExpired(): bool
    {
        if (!$this->loaded || $this->fetchedAt === null) {
            return true;
        }

        return (time() - $this->fetchedAt) >= $this->ttl;
    }

    /**
     * True while a recently-failed refresh is still backing off.
     *
     * Without this every request re-dials a down evaluation API inline, on the
     * caller's request thread, because a failed fetch never marks the snapshot
     * fresh. The marker lives in the shared cache, so the backoff is honoured
     * across all worker processes rather than per-process.
     *
     * When the cache itself is failing, the marker is held in-process instead
     * (see recordFailedRefresh()), and an unreadable marker counts as "not
     * backing off": the caller's one-second in-process throttle still bounds
     * the attempts, and a failed attempt then backs off here for the full
     * poll interval.
     */
    public function isRefreshBackedOff(): bool
    {
        if ($this->localBackoffUntil !== null) {
            if (time() < $this->localBackoffUntil) {
                return true;
            }
            $this->localBackoffUntil = null;
        }

        try {
            return $this->cache->get($this->cacheKey('refresh_failed')) !== null;
        } catch (\Throwable $e) {
            $this->cacheFailed('read the refresh backoff marker', $e);

            return false;
        }
    }

    /**
     * Suppress refresh attempts for one poll interval after a failure.
     */
    public function recordFailedRefresh(): void
    {
        // At least a second, even when the poll interval is zero: a caller
        // asking to always refresh is not asking to re-dial an unreachable API
        // on every single evaluation.
        $backoff = max(1, $this->ttl);

        try {
            $written = $this->cache->set($this->cacheKey('refresh_failed'), time(), $backoff);
        } catch (\Throwable $e) {
            $this->cacheFailed('write the refresh backoff marker', $e);
            $written = false;
        }

        // A marker the cache would not take must not mean "no backoff": with
        // the evaluation API down too, this process would then re-dial it
        // every second. Hold it here instead, for this process only. Only in
        // that case, so a healthy cache keeps the shared marker authoritative
        // and a successful fetch in another worker still ends the backoff.
        $this->localBackoffUntil = $written === false ? time() + $backoff : null;
    }

    /**
     * Read the snapshot back as ONE cache entry.
     *
     * Flags, segments and the fetch time were three separate keys, read one
     * after another — so a worker could pick up flags from generation N and
     * segments from generation N-1, leaving a rule pointing at a segment key
     * that no longer existed and silently failing to match (#2258). A single
     * key makes the read atomic with respect to any concurrent writer.
     *
     * A snapshot written by an older SDK version lives under the previous keys
     * and is simply not found here; those entries carried a TTL and age out on
     * their own, and the first successful fetch republishes in the new shape.
     */
    private function loadFromCache(): void
    {
        // The cache is the host's, and a host cache can be down: Laravel's
        // database store before `migrate` has run, a Redis store with Redis
        // unreachable. Their exceptions are not PSR-16's, so anything thrown
        // here means "no snapshot", never a failure of the caller (#5777).
        try {
            $snapshot = $this->cache->get($this->cacheKey('snapshot'));
        } catch (\Throwable $e) {
            $this->cacheFailed('read the cached flag configuration; starting without it', $e);

            return;
        }

        if (!is_array($snapshot) || !isset($snapshot['flags'], $snapshot['segments'])) {
            return;
        }

        // Parsing a *cached* snapshot can fail for the same reasons parsing a
        // fresh payload can, and this runs in the constructor — under
        // SharedFeatureflipCore::create(), which nothing catches — so an
        // unparseable entry would throw straight into the caller. Worse, now
        // that entries are retained rather than expiring with the poll
        // interval, nothing would ever age the bad entry out: a single corrupt
        // write would break every request indefinitely. Discard it and start
        // cold instead.
        try {
            // An entity this build cannot EVALUATE is skipped individually rather than
            // taking the whole cached snapshot with it (#2402) — same blast-radius
            // reasoning as Poller::parseEach. Only reachable after an SDK DOWNGRADE,
            // since the cache is written from entities that parsed cleanly, but
            // discarding every cached flag over one of them is the outcome the
            // entity-drop exists to avoid.
            $flags = [];
            foreach ($snapshot['flags'] as $data) {
                try {
                    $flag = Flag::fromArray($data);
                } catch (UnevaluableEntityException $e) {
                    continue;
                }
                $flags[$flag->key] = $flag;
            }

            $segments = [];
            foreach ($snapshot['segments'] as $data) {
                try {
                    $segment = Segment::fromArray($data);
                } catch (UnevaluableEntityException $e) {
                    continue;
                }
                $segments[$segment->key] = $segment;
            }
        } catch (\Throwable $e) {
            try {
                $this->cache->delete($this->cacheKey('snapshot'));
            } catch (\Throwable $deleteError) {
                $this->cacheFailed('delete the unreadable cached flag configuration', $deleteError);
            }
            $this->warn('discarded an unreadable cached flag configuration: ' . $e->getMessage());

            return;
        }

        $this->flags = $flags;
        $this->segments = $segments;
        $this->fetchedAt = isset($snapshot['fetchedAt']) ? (int) $snapshot['fetchedAt'] : null;
        $this->loaded = true;
    }

    /**
     * Publish the snapshot as one entry with NO expiry.
     *
     * Retention and freshness are separate concerns: `isExpired()` decides when
     * to refresh, and only a *successful* fetch replaces what is stored. So a
     * configuration stays servable for as long as the evaluation API is
     * unreachable, instead of evaporating one poll interval into an outage.
     */
    private function saveToCache(): void
    {
        // A cache that throws leaves the in-memory store just as usable as one
        // that returns false, so both are reported the same way below.
        try {
            $written = $this->cache->set($this->cacheKey('snapshot'), [
                'flags' => array_map(fn(Flag $f) => $this->flagToArray($f), array_values($this->flags)),
                'segments' => array_map(fn(Segment $s) => $this->segmentToArray($s), array_values($this->segments)),
                'fetchedAt' => $this->fetchedAt,
            ], self::RETENTION_TTL);
        } catch (\Throwable $e) {
            $this->cacheFailed('write the flag configuration', $e);
            $written = false;
        }

        try {
            $this->cache->delete($this->cacheKey('refresh_failed'));
        } catch (\Throwable $e) {
            $this->cacheFailed('clear the refresh backoff marker', $e);
        }

        // A cache that rejects the write (an over-quota Memcached item, a Redis
        // OOM, a read-only filesystem) leaves every process to refetch the whole
        // configuration inline on every request — the exact cost the backoff
        // exists to avoid, and invisible unless the return value is checked.
        if ($written === false) {
            $this->warn(
                'could not cache the flag configuration; every request will refetch it until the cache accepts writes',
            );
        }
    }

    private function cacheFailed(string $action, \Throwable $e): void
    {
        $this->warn(sprintf('the cache failed to %s: %s: %s', $action, $e::class, $e->getMessage()));
    }

    /**
     * Report without ever failing the caller: this runs on the evaluation path,
     * and the PSR-3 logger is the host's code (Monolog throws when it cannot
     * open its stream). Same guard as SharedFeatureflipCore::warn().
     */
    private function warn(string $message): void
    {
        try {
            $this->logger->warning($message);
        } catch (\Throwable) {
            // Nowhere left to report to.
        }
    }

    private function cacheKey(string $suffix): string
    {
        return self::FLAGS_KEY_PREFIX . $this->sdkKeyHash . '_' . $suffix;
    }

    /**
     * @return array<string, mixed>
     */
    private function flagToArray(Flag $flag): array
    {
        return [
            'key' => $flag->key,
            'version' => $flag->version,
            'type' => $flag->type,
            'enabled' => $flag->enabled,
            'variations' => array_map(fn($v) => ['key' => $v->key, 'value' => $v->value], $flag->variations),
            'rules' => array_map(fn($r) => [
                'id' => $r->id,
                'priority' => $r->priority,
                'conditionGroups' => array_map(fn($g) => [
                    'operator' => $g->operator,
                    'conditions' => array_map(fn($c) => [
                        'attribute' => $c->attribute,
                        'operator' => $c->operator,
                        'values' => $c->values,
                        'negate' => $c->negate,
                    ], $g->conditions),
                ], $r->conditionGroups),
                'serve' => $this->serveToArray($r->serve),
                'segmentKey' => $r->segmentKey,
            ], $flag->rules),
            'fallthrough' => $flag->fallthrough ? $this->serveToArray($flag->fallthrough) : null,
            'offVariation' => $flag->offVariation,
            'prerequisites' => array_map(fn($p) => [
                'prerequisiteFlagKey' => $p->prerequisiteFlagKey,
                'expectedVariationKey' => $p->expectedVariationKey,
            ], $flag->prerequisites),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function segmentToArray(Segment $segment): array
    {
        return [
            'key' => $segment->key,
            'version' => $segment->version,
            'conditions' => array_map(fn($c) => [
                'attribute' => $c->attribute,
                'operator' => $c->operator,
                'values' => $c->values,
                'negate' => $c->negate,
            ], $segment->conditions),
            'conditionLogic' => $segment->conditionLogic,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serveToArray(\Featureflip\Model\ServeConfig $serve): array
    {
        return [
            'type' => $serve->type,
            'variation' => $serve->variation,
            'bucketBy' => $serve->bucketBy,
            'salt' => $serve->salt,
            'variations' => $serve->variations ? array_map(fn($v) => ['key' => $v->key, 'weight' => $v->weight], $serve->variations) : null,
        ];
    }
}
