<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Psr\Log\AbstractLogger;

/** Test logger that records every message it receives. */
final class CollectingLogger extends AbstractLogger
{
    /** @var string[] */
    public array $messages = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}
