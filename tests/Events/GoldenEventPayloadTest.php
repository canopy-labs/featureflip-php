<?php

declare(strict_types=1);

namespace Featureflip\Tests\Events;

use Featureflip\Events\Event;
use Featureflip\Events\EventProcessor;
use Featureflip\Http\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Runner for the shared `eventPayloadVectors` class (#2360).
 *
 * Locks what identify() and track() actually put on the wire:
 * `{type, flagKey, userId?, variation?, timestamp, metadata?}`.
 *
 * That shape had no executable spec at all, which is the direct cause of #2359 — a
 * three-way payload divergence across six server SDKs (js/node/python forwarded the
 * caller's attributes as `metadata`; php/go/ruby, this one included, discarded them)
 * sat unnoticed indefinitely. Nothing compared an emitted event against an expected
 * shape, and the receiving end reduces every event to a counter tuple, so no
 * downstream assertion caught it either.
 *
 * Hand-authored rather than engine-generated, because the engine emits no events: it
 * returns an EvaluationResult, and the payload is built a layer above that. See
 * tools/golden-vectors/README.md for the full runner contract.
 *
 * The capture point is `HttpClient::post()`, so the assertion lands on the body the
 * processor actually serializes. That matters more here than in most siblings: php is
 * the SDK where an empty bag would encode as a JSON array (`[]`) rather than an object
 * and be rejected outright by the backend's `Dictionary<string, JsonElement>?` binder,
 * and that hazard is invisible one layer up.
 *
 * Note php cannot distinguish an ABSENT metadata argument from an explicitly empty
 * one — `track()` declares `array $metadata = []` — so the two vectors covering that
 * exercise the same call here. They still assert the same outcome the fleet must
 * agree on: neither puts a `metadata` key on the wire.
 */
final class GoldenEventPayloadTest extends TestCase
{
    /**
     * An ISO-8601 instant that designates UTC. Deliberately not an equality check:
     * the precision and the zero-offset spelling differ legitimately per SDK (this
     * one emits milliseconds and a "Z"), so a literal expectation would lock in a
     * divergence rather than a contract.
     */
    private const UTC_INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|\+00:00)$/';

    /**
     * The context capabilities this SDK has. A vector requiring anything outside
     * this set is skipped explicitly, so a structural gap cannot masquerade as a
     * pass. php takes a plain array, so it has both: the identity spelling is
     * observable, and a context can carry attributes with no identity at all.
     *
     * @var list<string>
     */
    private const CAPABILITIES = ['mapContext', 'anonymousContext'];

    /** @var list<array<string, mixed>> */
    private static array $vectors;

    public static function setUpBeforeClass(): void
    {
        $raw = file_get_contents(__DIR__ . '/../golden/vectors.json');
        self::assertIsString($raw);
        /** @var array<string, mixed> $parsed */
        $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array<string, mixed>> $vectors */
        $vectors = $parsed['eventPayloadVectors'];
        self::$vectors = $vectors;
    }

    public function testHasVectorsToRun(): void
    {
        $this->assertNotEmpty(self::$vectors);
    }

    public function testEveryEventPayloadVector(): void
    {
        $executed = 0;

        foreach (self::$vectors as $v) {
            if (array_diff($v['requires'] ?? [], self::CAPABILITIES) !== []) {
                continue;
            }

            /** @var array<string, mixed> $context */
            $context = $v['context'];
            $event = $v['kind'] === 'identify'
                ? Event::identify($context)
                // A vector with no `metadata` key omits the argument entirely, which
                // must put the same bytes on the wire as an explicitly empty bag.
                : Event::custom($v['eventKey'], $context, $v['metadata'] ?? []);

            $captured = $this->postThrough($event);

            $this->assertCount(1, $captured, $v['id']);
            $sent = $captured[0];

            // The EXACT field set, not a subset: #2359 was a field being present in
            // three SDKs and absent in three, which a subset assertion cannot see.
            $gotKeys = array_keys($sent);
            $wantKeys = array_merge(array_keys($v['expect']), ['timestamp']);
            sort($gotKeys);
            sort($wantKeys);
            $this->assertSame($wantKeys, $gotKeys, $v['id']);

            foreach ($v['expect'] as $field => $expected) {
                $this->assertSame($expected, $sent[$field], $v['id'] . ': ' . $field);
            }

            $this->assertMatchesRegularExpression(self::UTC_INSTANT, $sent['timestamp'], $v['id']);
            $executed++;
        }

        // A runner that silently skips everything is worse than no runner at all.
        $this->assertGreaterThanOrEqual(13, $executed);
    }

    /**
     * Pushes one event through the real processor and returns the events exactly as
     * they were serialized for POST /v1/sdk/events.
     *
     * @return list<array<string, mixed>>
     */
    private function postThrough(Event $event): array
    {
        $captured = [];
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('post')
            ->willReturnCallback(function (string $path, array $body) use (&$captured): bool {
                if ($path === '/v1/sdk/events') {
                    // Round-trip through the encoder the processor's POST uses, so
                    // php's array-vs-object encoding is part of what is asserted.
                    $encoded = json_encode($body, JSON_THROW_ON_ERROR);
                    $captured = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)['events'];
                }

                return true;
            });

        $processor = new EventProcessor($httpClient, 100);
        $processor->push($event);
        $processor->flush();

        return $captured;
    }
}
