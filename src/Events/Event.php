<?php

declare(strict_types=1);

namespace Featureflip\Events;

final class Event
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public static function evaluation(string $flagKey, array $context, ?string $variationKey): self
    {
        return new self('Evaluation', [
            'type' => 'Evaluation',
            'flagKey' => $flagKey,
            'userId' => (string) ($context['user_id'] ?? ''),
            'variation' => $variationKey ?? '',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $metadata
     */
    public static function custom(string $eventKey, array $context, array $metadata = []): self
    {
        return new self('Custom', [
            'type' => 'Custom',
            'flagKey' => $eventKey,
            'userId' => (string) ($context['user_id'] ?? ''),
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function identify(array $context): self
    {
        return new self('Identify', [
            'type' => 'Identify',
            'flagKey' => '$identify',
            'userId' => (string) ($context['user_id'] ?? ''),
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
