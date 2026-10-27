<?php

declare(strict_types=1);

namespace Featureflip\Tests\Events;

use Featureflip\FeatureflipClient;
use PHPUnit\Framework\TestCase;

/**
 * The `reason` / `ruleId` fields on the Evaluation event (#4268).
 */
final class EvaluationEventReasonTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    /**
     * A String flag whose matching rule serves it, read through the bool accessor,
     * is reported to the caller as ERROR (the type guard hands back the default).
     * The event must say the same, and must not name the rule: the rule matched,
     * but it did not serve the traffic the caller got.
     */
    public function testATypeMismatchedReadIsAnErrorEventWithoutARuleId(): void
    {
        $api = new CapturingEvalApi([[
            'key' => 'greeting', 'version' => 1, 'type' => 'String', 'enabled' => true,
            'variations' => [['key' => 'hi', 'value' => 'hello'], ['key' => 'bye', 'value' => 'goodbye']],
            'rules' => [[
                'id' => 'rule-1', 'priority' => 0,
                'conditionGroups' => [['operator' => 'And', 'conditions' => [
                    ['attribute' => 'plan', 'operator' => 'Equals', 'values' => ['pro'], 'negate' => false],
                ]]],
                'serve' => ['type' => 'Fixed', 'variation' => 'hi'],
                'segmentKey' => null,
            ]],
            'fallthrough' => ['type' => 'Fixed', 'variation' => 'bye'],
            'offVariation' => 'bye',
            'prerequisites' => [],
        ]]);

        $client = $api->client();
        $this->assertFalse($client->boolVariation('greeting', ['user_id' => 'u', 'plan' => 'pro'], false));
        $client->flush();

        $this->assertCount(1, $api->events);
        $event = $api->events[0];
        $this->assertSame('error', $event['reason']);
        $this->assertSame('hi', $event['variation']);
        $this->assertArrayNotHasKey('ruleId', $event);
    }
}
