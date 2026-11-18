<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SDK key
    |--------------------------------------------------------------------------
    |
    | The server SDK key for the environment this app runs in. Keep it in your
    | environment, not in version control.
    |
    */
    'sdk_key' => env('FEATUREFLIP_SDK_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Evaluation API
    |--------------------------------------------------------------------------
    */
    'base_url' => env('FEATUREFLIP_BASE_URL', 'https://eval.featureflip.io'),

    /*
    |--------------------------------------------------------------------------
    | Timing
    |--------------------------------------------------------------------------
    |
    | poll_interval    Seconds a fetched flag configuration stays fresh before
    |                  the next evaluation refetches it.
    | flush_interval   Seconds between event flushes.
    | flush_batch_size Queued events that trigger a flush on their own.
    |
    */
    'poll_interval' => (int) env('FEATUREFLIP_POLL_INTERVAL', 30),
    'flush_interval' => (int) env('FEATUREFLIP_FLUSH_INTERVAL', 30),
    'flush_batch_size' => (int) env('FEATUREFLIP_FLUSH_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | The cache store that holds the flag configuration between requests. Use a
    | store shared by all workers (redis, database, memcached, file). The
    | "array" store is per-process, so every PHP-FPM request would refetch.
    | Null uses your default store.
    |
    */
    'cache_store' => env('FEATUREFLIP_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds. Applied to the built-in Guzzle client, which fetches the flag
    | configuration on the request that finds it stale. Ignored when you bind
    | your own Psr\Http\Client\ClientInterface in the container.
    |
    */
    'http_timeout' => (float) env('FEATUREFLIP_HTTP_TIMEOUT', 5),
    'connect_timeout' => (float) env('FEATUREFLIP_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    |
    | Where the SDK reports an unreachable API or a rejected key. Null uses
    | your default log channel.
    |
    */
    'log_channel' => env('FEATUREFLIP_LOG_CHANNEL'),

];
