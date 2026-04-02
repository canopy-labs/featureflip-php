<?php

declare(strict_types=1);

namespace Featureflip\DataSource;

use Featureflip\Http\HttpClient;
use Featureflip\Model\{Flag, Segment};
use Featureflip\Store\FlagStore;

final class Poller
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly FlagStore $store,
    ) {}

    public function fetch(): void
    {
        $data = $this->httpClient->get('/v1/sdk/flags');

        $flags = array_map(
            fn(array $f) => Flag::fromArray($f),
            $data['flags'] ?? [],
        );

        $segments = array_map(
            fn(array $s) => Segment::fromArray($s),
            $data['segments'] ?? [],
        );

        $this->store->putAll($flags, $segments);
    }
}
