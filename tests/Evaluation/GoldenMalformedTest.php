<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\DataSource\Poller;
use Featureflip\Http\HttpClient;
use Featureflip\Store\FlagStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Runner for the shared `malformedConfigVectors` class (#2315).
 *
 * The rule: a config payload violating the wire contract is discarded WHOLESALE,
 * never partially applied.
 *
 * **php reaches that outcome by a different route than its siblings, and the fixture
 * is shaped so the two converge.** The six other SDKs drop the whole payload on the
 * first violation. php instead skips malformed entries INDIVIDUALLY
 * (`Poller::parseEach`) — deliberate since #2258, because a request-scoped process
 * has no in-memory snapshot to fall back on, so salvaging the healthy flags beats
 * serving defaults for all of them. A bad flag alongside good ones therefore
 * partially applies here and nowhere else.
 *
 * Every reject vector consequently carries exactly ONE entity, and it is the
 * malformed one: the parsed list comes back empty, `Poller`'s last-known-good guard
 * fires, and the store is left byte-identical — the same observable outcome the other
 * six produce by discarding. That guard only fires on a WARM store (an empty store is
 * legitimately replaceable with an empty payload), which is why the shared seed is
 * applied first and asserted.
 *
 * php also has no SSE path at all, so unlike its siblings there is exactly one parse
 * boundary to exercise.
 */
final class GoldenMalformedTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $block;

    public static function setUpBeforeClass(): void
    {
        $raw = file_get_contents(__DIR__ . '/../golden/vectors.json');
        self::assertIsString($raw, 'could not read golden/vectors.json');
        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::$block = $data['malformedConfigVectors'];
    }

    private function store(): FlagStore
    {
        return new FlagStore(new Psr16Cache(new ArrayAdapter()), 'test', 30);
    }

    /** @param array<string, mixed> $payload */
    private function pollerFor(array $payload, FlagStore $store): Poller
    {
        $http = $this->createStub(HttpClient::class);
        $http->method('get')->willReturn($payload);

        return new Poller($http, $store);
    }

    /**
     * Applies the shared seed. A runner whose seed silently failed would "pass" every
     * reject vector for entirely the wrong reason — and here it would also disable the
     * last-known-good guard, which needs a non-empty store.
     */
    private function seededStore(): FlagStore
    {
        $store = $this->store();
        $this->pollerFor(self::$block['seed'], $store)->fetch();
        self::assertNotNull($store->getFlag('mc-seed'), 'seed snapshot did not apply');

        return $store;
    }

    public function testFixtureHasVectors(): void
    {
        self::assertGreaterThanOrEqual(8, count(self::$block['vectors']));
    }

    public function testMalformedConfigVectors(): void
    {
        $executed = 0;

        foreach (self::$block['vectors'] as $v) {
            ++$executed;
            $store = $this->seededStore();
            $label = "[{$v['id']}] {$v['description']}";

            $this->pollerFor($v['payload'], $store)->fetch();

            if ($v['expect'] === 'reject') {
                self::assertNotNull(
                    $store->getFlag('mc-seed'),
                    "{$label}: the seeded config was replaced from a malformed payload",
                );
                self::assertNull(
                    $store->getFlag('mc-bad-type'),
                    "{$label}: a flag from the rejected payload reached the store",
                );
            } elseif ($v['expect'] === 'accept') {
                $applied = $store->getFlag('mc-accepted-flag') !== null
                    || isset($store->getSegments()['mc-accepted']);
                self::assertTrue($applied, "{$label}: a forward-compatible payload was rejected");
            } elseif ($v['expect'] === 'dropEntity') {
                // Neither accept nor reject: the payload APPLIES, minus the entities
                // carrying an enum this build cannot evaluate. Both halves are
                // asserted — "dropped" alone is satisfied by discarding the whole
                // payload, and "kept" alone by tolerating the bad value.
                //
                // php reaches this outcome through the SAME per-entry skip it already
                // used for malformed entries, which is why every dropEntity payload
                // must retain at least one flag: a payload whose every flag was dropped
                // would trip Poller's last-known-good guard and read as "the drop did
                // not happen".
                foreach ($v['dropFlags'] ?? [] as $key) {
                    self::assertNull(
                        $store->getFlag($key),
                        "{$label}: flag {$key} should have been dropped",
                    );
                }
                foreach ($v['dropSegments'] ?? [] as $key) {
                    self::assertArrayNotHasKey(
                        $key,
                        $store->getSegments(),
                        "{$label}: segment {$key} should have been dropped",
                    );
                }
                foreach ($v['keepFlags'] ?? [] as $key) {
                    self::assertNotNull(
                        $store->getFlag($key),
                        "{$label}: flag {$key} should have been kept",
                    );
                }
                foreach ($v['keepSegments'] ?? [] as $key) {
                    self::assertArrayHasKey(
                        $key,
                        $store->getSegments(),
                        "{$label}: segment {$key} should have been kept",
                    );
                }
            } else {
                self::fail("unmapped expect {$v['expect']}");
            }
        }

        self::assertGreaterThanOrEqual(8, $executed, 'too few malformed vectors executed');
    }
}
