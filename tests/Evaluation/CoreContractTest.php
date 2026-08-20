<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\EvaluationEvent;
use Featureflip\FeatureflipClient;
use Featureflip\Model\Flag;
use Featureflip\SharedFeatureflipCore;
use Featureflip\Store\FlagStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * coreContractVectors — the shared CORE's contract, one layer above the evaluator.
 *
 * GoldenVectorTest asserts what the evaluator computes, with the .NET engine as its
 * oracle. This class asserts the shared core's client-facing contract
 * (typed-accessor strictness, malformed-variation handling), where the engine has
 * no opinion and in fact disagrees: it returns null where an SDK must return the
 * CALLER'S default. That is why #1989 and #2281 could not be locked with the
 * existing classes, and why both shipped as 6-of-7-SDK divergences no CI could see.
 * These vectors are hand-authored, not engine-generated.
 *
 * expect.reason is a CANONICAL token mapped to PHP's SCREAMING_SNAKE vocabulary.
 *
 * DO NOT edit vectors.json to make PHP pass — if a vector fails, fix the PHP SDK.
 */
final class CoreContractTest extends TestCase
{
    private const REASONS = [
        'Error' => 'ERROR',
        'Fallthrough' => 'FALLTHROUGH',
        'FlagNotFound' => 'FLAG_NOT_FOUND',
    ];

    /** @return array<mixed> */
    private function vectors(): array
    {
        $raw = file_get_contents(__DIR__ . '/../golden/vectors.json');
        self::assertNotFalse($raw, 'could not read vectors.json');
        $data = json_decode($raw, true);

        return $data['coreContractVectors'] ?? [];
    }

    /**
     * @param array<mixed> $flags
     * @param array<mixed> $inspectors
     */
    private function clientFor(array $flags, string $id, array $inspectors): FeatureflipClient
    {
        $store = new FlagStore(new Psr16Cache(new ArrayAdapter()), 'contract-' . $id, 30);
        $store->putAll(array_map(static fn (array $f): Flag => Flag::fromArray($f), $flags), []);

        $coreRef = new \ReflectionClass(SharedFeatureflipCore::class);
        $core = $coreRef->newInstanceWithoutConstructor();
        $coreRef->getConstructor()->invoke($core, $store, null, null, null, $inspectors);

        $clientRef = new \ReflectionClass(FeatureflipClient::class);
        $client = $clientRef->newInstanceWithoutConstructor();
        $clientRef->getConstructor()->invoke($client, $core);

        return $client;
    }

    public function testCoreContractVectors(): void
    {
        $vectors = $this->vectors();
        self::assertNotEmpty($vectors, 'no coreContractVectors in fixture');

        $executed = 0;
        foreach ($vectors as $v) {
            // PHP exposes no separate int accessor — numberVariation covers every
            // JSON number. The skip is explicit so a capability gap cannot pass.
            if ($v['read']['as'] === 'int') {
                continue;
            }

            $events = [];
            $client = $this->clientFor(
                $v['flags'],
                $v['id'],
                [static function (EvaluationEvent $e) use (&$events): void { $events[] = $e; }]
            );

            $context = ['user_id' => $v['context']['userId']];
            $default = $v['read']['default'];

            $got = match ($v['read']['as']) {
                'bool' => $client->boolVariation($v['flagKey'], $context, (bool) $default),
                'string' => $client->stringVariation($v['flagKey'], $context, (string) $default),
                'number', 'double' => $client->numberVariation($v['flagKey'], $context, $default),
                default => self::fail("unmapped read.as {$v['read']['as']} — add it to the match"),
            };

            self::assertEquals(
                $v['expect']['value'],
                $got,
                "[{$v['id']}] {$v['description']}: wrong value"
            );

            // Typed accessors return only a value, so the reason is observed
            // through the inspector — the same surface a real caller would use.
            self::assertCount(1, $events, "[{$v['id']}] inspector must fire exactly once");
            self::assertSame(
                self::REASONS[$v['expect']['reason']],
                $events[0]->reason,
                "[{$v['id']}] wrong reason"
            );

            $client->close();
            ++$executed;
        }

        // A runner that silently skips everything is worse than no runner at all.
        self::assertGreaterThanOrEqual(12, $executed, 'too few contract vectors executed');
    }
}
