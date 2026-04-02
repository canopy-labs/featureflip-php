<?php

declare(strict_types=1);

namespace Featureflip\Store;

use Featureflip\Model\{Flag, Segment};
use Psr\SimpleCache\CacheInterface;

final class FlagStore
{
    private const FLAGS_KEY_PREFIX = 'featureflip_flags_';

    /** @var array<string, Flag> */
    private array $flags = [];

    /** @var array<string, Segment> */
    private array $segments = [];

    private bool $loaded = false;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $sdkKeyHash,
        private readonly int $ttl,
    ) {
        $this->loadFromCache();
    }

    public function getFlag(string $key): ?Flag
    {
        return $this->flags[$key] ?? null;
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
        $this->saveToCache();
    }

    public function isExpired(): bool
    {
        if (!$this->loaded) {
            return true;
        }

        $timestamp = $this->cache->get($this->cacheKey('timestamp'));
        if ($timestamp === null) {
            return true;
        }

        return (time() - (int) $timestamp) >= $this->ttl;
    }

    private function loadFromCache(): void
    {
        $flagsData = $this->cache->get($this->cacheKey('flags'));
        $segmentsData = $this->cache->get($this->cacheKey('segments'));

        if ($flagsData !== null && is_array($flagsData)) {
            foreach ($flagsData as $data) {
                $flag = Flag::fromArray($data);
                $this->flags[$flag->key] = $flag;
            }
            $this->loaded = true;
        }

        if ($segmentsData !== null && is_array($segmentsData)) {
            foreach ($segmentsData as $data) {
                $segment = Segment::fromArray($data);
                $this->segments[$segment->key] = $segment;
            }
        }
    }

    private function saveToCache(): void
    {
        $flagsData = array_map(fn(Flag $f) => $this->flagToArray($f), array_values($this->flags));
        $segmentsData = array_map(fn(Segment $s) => $this->segmentToArray($s), array_values($this->segments));

        $this->cache->set($this->cacheKey('flags'), $flagsData, $this->ttl);
        $this->cache->set($this->cacheKey('segments'), $segmentsData, $this->ttl);
        $this->cache->set($this->cacheKey('timestamp'), time(), $this->ttl);
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
