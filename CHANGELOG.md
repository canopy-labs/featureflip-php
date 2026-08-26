# Changelog

## 3.2.0 — 2026-08-26

### Changed

- A `Before`/`After` date operand that matches the ISO grammar but names no real calendar day now matches nothing, where it previously **rolled over into the following month**. `2024-02-30` resolved to 2024-03-01, `2023-02-29` (2023 is not a leap year) to 2023-03-01 and `2024-04-31` to 2024-05-01 — so a rule evaluated against a date its author never wrote. The evaluation engine and the C#, Go, Python and Java SDKs have always rejected these, so this converges the SDKs rather than making this one an outlier; until now a single saved rule could serve different variations to two users purely by which SDK their service ran. Follows #2480, which pinned the date *grammar* — an unreal day is **inside** that grammar, because a character class cannot express "is a real day", so the grammar guard was silent on it. ([#2491](https://github.com/canopy-labs/featureflip/issues/2491))

- A zero month or zero day is also rejected. This SDK was alone in rolling these **backwards** into the previous year — `2024-00-01` resolved to 2023-12-01 and `2024-01-00` to 2023-12-31 — where the JS and Ruby SDKs already matched nothing. ([#2491](https://github.com/canopy-labs/featureflip/issues/2491))

- The leap-year rule is applied in full, including the century exception: `1900-02-29` and `2100-02-29` match nothing (divisible by 100 but not 400), while `2000-02-29` and `2024-02-29` continue to match. The check runs on the operand's **written** date, before any offset is applied, so `2024-02-30T00:00:00+05:00` is rejected even though it would resolve to 2024-02-29T19:00Z — a date that does exist. ([#2491](https://github.com/canopy-labs/featureflip/issues/2491))

**If you have a targeting rule using one of these operands**, rewrite it as the date you meant. The Management API has rejected an unreal day on write since #2480 (`PortableDateOperand` round-trips every grammar-matched operand through the engine's parser), so a rule saved from that release onward cannot carry one — only rules saved earlier are affected.

### Fixed

- A `Before`/`After` date operand that resolves outside the representable date range now matches nothing, where it previously resolved to a real instant. The evaluation engine parses with `DateTimeOffset.TryParse`, so its accepted range is 0001-01-01T00:00:00Z to 9999-12-31T23:59:59.999Z and it matches nothing outside that; this SDK resolved past **both** ends, so a single saved rule served different variations to two users purely by which SDK their service ran. ([#2500](https://github.com/canopy-labs/featureflip/issues/2500))

- The two reachable shapes are a **year-zero** operand and an operand carried out of range **by its offset**. `0000-01-01` is inside the ISO grammar and is a real proleptic date (`0000-02-29` exists — year 0 is divisible by 400), so neither #2480's grammar guard nor #2491's calendar-day check excluded it. Separately, `[0-9]{4}` constrains only the **written** year while a timezone offset moves the resolved instant, so `0001-01-01T00:00:00+05:00` fell below the floor and `9999-12-31T23:59:59-05:00` rose above the ceiling from years the grammar allows. The check therefore runs on the **resolved** instant — deliberately unlike #2491's, which runs on the written date. ([#2500](https://github.com/canopy-labs/featureflip/issues/2500))

- The exact boundaries remain accepted: `0001-01-01`, `0001-01-01T05:00:00+05:00`, `9999-12-31T23:59:59Z` and `9999-12-31T18:59:59-05:00` all still resolve. ([#2500](https://github.com/canopy-labs/featureflip/issues/2500))

**If you have a targeting rule using one of these operands**, rewrite it as the date you meant. The Management API has rejected them on write since #2480 (`PortableDateOperand` round-trips every grammar-matched operand through the engine's own parser, so it inherits the range bound), meaning only rules saved before that release can carry one.

## 3.1.1 — 2026-08-24

### Fixed

- A date operand is now trimmed of exactly the whitespace the evaluation engine trims (tab, newline, vertical tab, form feed, carriage return and space), and is rejected outright if it still carries a NUL, another control character, or a non-ASCII whitespace character. Each SDK had been relying on its own language's `trim`, and no two of those cover the same set, so the same operand could match on one SDK and match nothing on another. ([#2468](https://github.com/canopy-labs/featureflip/issues/2468))
- A digit string of 309 or more characters matches nothing instead of resolving to the **epoch**. The overflow guard relied on the `(int)` cast saturating, which holds only to 308 digits; beyond that the intermediate float is `INF` and `(int) INF` is `0`, so an absurdly long operand satisfied every `Before` comparison. ([#2468](https://github.com/canopy-labs/featureflip/issues/2468))
- An ISO-8601 operand naming hour 24 (`2024-01-01T24:00:00`) matches nothing, rather than rolling over to the next day. ([#2468](https://github.com/canopy-labs/featureflip/issues/2468))

## 3.1.0 — 2026-08-24

### Fixed

- **A rejected batch of analytics events is now noticed at all.** `HttpClient::post()` reported success for every response it received, because a PSR-18 client throws only on a *transport* fault — a 4xx or 5xx is an ordinary return value to it, not an exception. A 503 was therefore indistinguishable from a delivered batch: the `dropped N analytics event(s)` reporting added in 3.0.0 could not fire for it, and the events were thrown away as sent. Any non-2xx response is now a failure and carries its status through to the log line. This is the half that mattered in production, where the public edge answers this endpoint with a 503 at a low but constant rate — evaluation analytics were being lost steadily *and* invisibly. ([#2456](https://github.com/canopy-labs/featureflip/issues/2456))
- **Events survive a failed delivery instead of being discarded.** `flush()` chunked and cleared the queue *before* posting anything, so a batch the endpoint refused was already gone by the time the refusal was known. A retryable failure — any 5xx, a 429, or a transport fault — now returns the batch to the *front* of the queue for the next flush, ahead of newer events. A permanent one still drops the batch, because retrying it would never succeed and would pin the queue at its bound: a 401 or 403 means the SDK key was rejected, a 400 means the body is malformed. Both outcomes are logged, and the line distinguishes what was kept from what was dropped. ([#2456](https://github.com/canopy-labs/featureflip/issues/2456))
- **The event queue is bounded at 10,000 events.** Keeping failed batches means an outage would otherwise grow it without limit. Past the bound the *oldest* events are shed and the count is reported — memory stays capped, the freshest analytics are the ones kept, and a long outage sheds the stale re-queued batches rather than starving new events. ([#2456](https://github.com/canopy-labs/featureflip/issues/2456))
- **A failing endpoint is retried once per `flushInterval`, not once per evaluation.** A re-queued batch leaves the queue at or above `flushBatchSize`, so the batch-size trigger would have fired on every subsequent event — one HTTP request per evaluation into an endpoint that is already failing, which is worse for the server than the dropping this release replaces. That trigger now backs off for one `flushInterval` after a retryable failure and resumes the moment a batch is delivered. The interval trigger is untouched: it is the retry vehicle. ([#2456](https://github.com/canopy-labs/featureflip/issues/2456))
- **The shutdown flush makes one attempt and lets the rest go.** Re-queueing during shutdown would keep events for a flush that will never come, and retrying until the queue drained would hang the process for as long as the endpoint stayed down. Whatever the final attempt cannot deliver is discarded, and reported. ([#2456](https://github.com/canopy-labs/featureflip/issues/2456))

### Notes

- `EventProcessor` — already `@internal` — takes `maxQueueSize` as a trailing constructor argument, so existing positional construction is unaffected, and gains `close()` for the final flush. `HttpClient` gains `lastFailure()`, which carries the response status alongside the message `lastError()` already returned; `lastError()` is unchanged.

## 3.0.2 — 2026-08-23

### Fixed

- An unrecognised condition operator now fails closed instead of matching every user. The default arm returned `false`, which a negated condition then inverted to `true` — so a config naming an operator the SDK did not know could silently target everyone. ([#2262](https://github.com/canopy-labs/featureflip/issues/2262))
- `identify()` and `track()` put the same payload on the wire as every other server SDK. The field set and shapes had drifted per language, so the same call produced different events depending on which SDK sent it. ([#2359](https://github.com/canopy-labs/featureflip/issues/2359))
- Event timestamps are stamped in UTC rather than the host's local timezone, so events from servers outside UTC no longer land with a skewed time. ([#2403](https://github.com/canopy-labs/featureflip/issues/2403))

## 3.0.1 — 2026-08-20

### Changed

- A type-mismatched read returns the caller's default and reports `'ERROR'`, instead of returning it under the evaluator's *success* reason, which left callers no signal at all. Reading a String flag through a number accessor, say, is now detectable rather than silent. Matching reads and the generic/JSON accessors are unchanged. ([#2281](https://github.com/canopy-labs/featureflip/issues/2281))

## 3.0.0 — 2026-08-19

### Removed

- **`Config::$initTimeout`.** It never had any effect and cannot be given one: the SDK evaluates through a PSR-18 client *you* supply, and PSR-18's `sendRequest()` exposes no timeout or cancellation hook, so the SDK cannot bound a call it did not originate. Set the timeout on the client you inject — `new GuzzleHttp\Client(['timeout' => 5])`. This is not cosmetic: the configuration fetch runs synchronously on the calling thread, and Guzzle's default timeout is `0`, so an evaluation API that accepts the connection and stalls could block a request indefinitely while `initTimeout` sat there looking like protection (#2267).

### Changed

- **A closed handle is now inert.** `close()` used to mean three different things depending on which method you called: inspectors and `refresh()` were suppressed, while the variation accessors, `track()`, `identify()` and `flush()` carried on — still reading the shut-down core's store, still reaching the network. Now variation calls return the default you pass, `variationDetail()` reports reason `ERROR`, and `track()`/`identify()`/`flush()` do nothing. Closing one handle never affects a sibling still holding the same core, and `close()` itself still flushes what was already queued. The old behaviour was justified in the test suite as "matching the Python and Ruby SDKs" — it never did: both guard every accessor, and Python likewise returns its default with reason `ERROR` from `variation_detail`. This restores that parity (#2267).


### Fixed

- Event tracking no longer throws into the calling application when the evaluation context carries a user identifier PHP cannot render as a string. `boolVariation()` and friends, `track()` and `identify()` all built their event's `userId` with a bare `(string)` cast: an object without `__toString` (a User entity, a value object) raised `\Error` and reached the caller, while an array raised a warning — producing the literal `userId` `"Array"` on bare PHP, or an `ErrorException` under Symfony's and Laravel's default error handlers. Such a value now degrades to an empty attribution and writes one `[featureflip] …` line to the PHP error log, rather than failing the request or silently mis-attributing it. This is the fail-safe guarantee #1990 gave the evaluator; event construction sits just outside that guard, and `track()`/`identify()` never had one (#2259).

  This covers the *event* path only. An unstringifiable value used as a rollout's `bucketBy` anchor, or as a targeting-rule attribute, still degrades inside the evaluator to the caller's default with reason `ERROR` — unchanged, and by design since #1990.

- A backed enum is now read via its backing value instead of being discarded, so `['user_id' => Plan::Pro]` attributes to `"pro"`. Previously it threw, like any other non-stringable object.

- Events honour the `userId` spelling of the user identifier. `user_id` stays canonical and is preferred when both keys hold a value, but the evaluator already accepted either when bucketing — so a caller who used the alias consistently was bucketed correctly while every analytics event was attributed to a blank user. Resolution matches the JS SDK's `context.user_id ?? context.userId`: a *present but null* `user_id` falls through to `userId`, a present-but-falsy one does not (#2259).

- **A configuration is now kept once fetched, so an Evaluation API outage no longer turns every flag off.** Cache entries were written with `ttl = pollInterval`, meaning they expired at the exact moment the SDK wanted to refresh them. Roughly thirty seconds into any outage the cache was empty, every flag reported `FLAG_NOT_FOUND`, and each one silently reverted to the default passed at its call site — a kill switch meant to be on served off. Retention and freshness are now separate: `pollInterval` still decides when to refresh, but only a *successful* fetch replaces what is stored. Retention remains best-effort — an eviction-based backend may still drop the entry, and a cache configured with its own default lifetime will expire it on that schedule — but either case degrades to an ordinary cold start rather than to silent defaults (#2258).
- **A single malformed flag no longer discards the whole configuration.** The payload was parsed in one pass, so one unparseable entry aborted the fetch and left the store empty — and since nothing was ever cached, it failed identically on every subsequent request instead of recovering. Entries are now parsed independently: a bad one is skipped and logged, the rest load. Required wire fields are validated explicitly rather than surfacing as a PHP warning followed by a constructor `TypeError` (#2258).
- **A response that succeeds but carries nothing usable no longer empties a working configuration.** An empty flag list, a missing `flags` key, or a payload whose every entry was skipped is refused rather than published, so a degraded response cannot reach the same outcome as a failed one. The trade-off is deliberate: genuinely deleting every flag in an environment will not propagate until the process restarts, and that is logged (#2258).
- **An unreadable cached configuration is discarded instead of thrown at the caller.** Parsing the cached snapshot happens in a constructor that nothing guards, so a corrupt entry raised straight into the host application — and with entries now retained rather than expiring, nothing would have aged it out. It is evicted and refetched (#2258).
- **A failed refresh backs off for one poll interval** instead of re-dialling an unreachable Evaluation API inline, on the caller's request thread, on every single request. The marker is stored in the shared cache, so the backoff holds across worker processes (#2258).
- **The cached configuration is written and read as one entry.** Flags, segments and the fetch time were three separate keys read in sequence, so a worker could load flags from one generation alongside segments from another — leaving a targeting rule pointing at a segment key that no longer existed and silently failing to match (#2258).
- **Event-delivery failures are reported**, once per flush rather than once per batch. `HttpClient::post()` swallowed every error, so a customer's analytics could be entirely absent with nothing to explain it. Delivery stays best-effort — it must never break an evaluation — but it is no longer silent (#2258).
- **A cache that rejects writes is reported.** The return value of the cache write was discarded, so an over-quota or read-only cache left every request refetching the entire configuration inline, indefinitely and undetectably (#2258).

- **Flags refresh inside a long-running worker.** The staleness check ran only when the client was constructed. Under PHP-FPM that is every request, so it behaved like polling; under Laravel Octane, RoadRunner, FrankenPHP or Swoole the client is built once at boot and kept for thousands of requests, so the check never ran again and flags froze at boot until the process restarted. The check now also runs on the evaluation path: one integer comparison when the configuration is fresh, and when it is stale the refresh is claimed before the fetch begins, so concurrent or re-entrant callers read the refreshed store rather than each starting their own. An unreachable API is still capped by the existing backoff. Note that the claim is per-process — several workers, or several Swoole coroutines yielding on the same fetch, can each refresh once per interval; what is bounded is any one caller repeating it (#2260).
- **Queued events drain without waiting for the process to die.** Nothing flushed the queue before shutdown, so a persistent worker accumulated one event per evaluation for its entire lifetime — an unbounded memory leak that also delivered no analytics at all until it exited. Events now ship once they reach `flushBatchSize`, or once `flushInterval` has elapsed, whichever comes first. Two triggers rather than one because a busy worker needs its memory bounded and a quiet one needs to ship at all (#2260).
- **`fastcgi_finish_request()` no longer fires as a side effect of the SDK merely existing.** It ran unconditionally in the shutdown handler, *before* the already-shut-down guard, so a client that had been closed still ended the host application's response. It now runs inside `shutdown()`, after the guard, and only when there are queued events worth returning the response ahead of (#2260).
- **The staleness check stays off the shared cache.** Moving it onto the evaluation path made it run per variation call, and while a failed refresh is backing off the store stays stale — so the backoff marker would have been read out of Redis (or the filesystem) once per flag check, for the whole outage. The answer is remembered in-process and re-asked at most once a second; a fresh configuration costs one integer comparison and touches nothing (#2260).
- **A poll interval of zero no longer disables the failure backoff.** It was used directly as the backoff duration, so asking to always refresh also meant re-dialling an unreachable evaluation API on every evaluation. The backoff is now at least one second, and `flushInterval` is floored the same way — zero there meant one HTTP POST per evaluation (#2260).
- **The interval trigger ages the oldest queued event, not the last flush.** Measured from the last flush, any worker whose events arrive further apart than the interval satisfied the check on every push and posted them one at a time — defeating batching for precisely the quiet worker the trigger was added to serve (#2260).
- **Nothing on the evaluation path can now escape into the host.** The refresh and the flush both reached a PSR-3 logger, which is host code — Monolog throws when its stream handler cannot open its file — and neither call was wrapped. Both are now contained, restoring the guarantee #1990 established. The flush is likewise re-entrancy-guarded, since delivery runs through the caller's own HTTP client, which may evaluate a flag itself (#2260).
- **The SDK key can no longer reach the host application's log.** Failure paths report the underlying exception verbatim, and that exception comes from the PSR-18 client the caller supplied — so a client that quotes request headers in its message would have handed over the `Authorization` value, which is the key. Guzzle does not; the SDK does not get to choose which client it is given. Everything the SDK logs now passes through a redactor, including the default error-log sink. A value shorter than 20 characters is left alone: a real key is 54, and redacting a short string corrupts every message it appears inside (#2266).

### Added

- **`FeatureflipClient::isInitialized()`.** Reports whether a flag configuration was ever loaded — `false` means every evaluation is returning the default you passed, typically a rejected SDK key or an unreachable Evaluation API with nothing cached. Since #2258 that condition is survivable and logged, but the host application had no way to *ask*: the state was computed internally, to pick between the two halves of the warning message, and never exposed. All six sibling server SDKs expose it. "Loaded" rather than "fresh": a configuration retained through an outage still counts, and an environment with no flags counts too — it loaded successfully. A closed handle reports `false` (#2269).

- **`FEATUREFLIP_SDK_KEY` now works.** The README has advertised it since the SDK shipped and nothing ever read it. It is not a stray claim: python, go, csharp and ruby all implement exactly this fallback, so PHP was the outlier rather than the documentation. Pass an empty string to `get()` and the key is read from the environment; an explicit key always wins; neither source raises `InvalidArgumentException` naming both. Resolution happens before the core cache lookup, so `get('')` and `get($theSameKey)` share one core (#2261).

- **`Config::$logger` — an optional PSR-3 logger.** Everything above used to fail behind a `catch (\Throwable)` that bound no variable and so could not log even in principle: a typo'd SDK key, an unreachable API and a malformed payload all produced total silence while every flag served its caller default. Pass your application's logger to receive these where you actually read them; omit it and the SDK writes to PHP's error log. A repeated failure is reported once per poll interval rather than once per request, so an outage does not flood the log. Adds `psr/log` to the package's requirements (#2258).

- **`FeatureflipClient::refresh()`.** Optional — the evaluation path refreshes by itself — but a persistent worker can call this from a tick hook so the fetch lands *between* requests rather than inside one, and no user's request ever pays for it:

  ```php
  Octane::tick('featureflip', fn () => $client->refresh())->seconds(10);
  ```

  It honours the poll interval and the failure backoff exactly as the automatic path does, so it is safe to call on a tight tick (#2260).

### Notes

- **Events can now be delivered mid-request.** Crossing `flushBatchSize` inside a request posts synchronously rather than waiting for shutdown, so an FPM request making more evaluations than the batch size no longer gets all of its analytics deferred past `fastcgi_finish_request()`. That is the cost of bounding the queue; raise `flushBatchSize` if a request routinely crosses it and the latency matters.
- `EventProcessor` is now marked `@internal` and takes `flushInterval` as a trailing constructor argument, so existing positional construction is unaffected.
- `flushInterval` now does something. It had been accepted by `Config` and documented while being wired to nothing; `initTimeout` is still in that state, tracked separately in #2261.

### Documentation

- **The Laravel snippet now runs.** It resolved `Psr\SimpleCache\CacheInterface` and `Psr\Http\Client\ClientInterface` from the container; neither is bound in a stock Laravel, so it threw `BindingResolutionException` on the first line a reader would copy. It now uses `cache.store` (Illuminate's repository does implement PSR-16) and constructs a Guzzle client **with a timeout**, since the configuration fetch runs on the calling thread (#2261).
- **The docs no longer tell PHP users to mock the SDK.** The reference page claimed "interfaces, not final classes, are used throughout, so PHPUnit can mock them" — the SDK ships zero interfaces and 23 of its 24 classes are `final`. It now points at `forTesting()`, which is the actual answer (#2261).
- **"Automatic background flag refresh" is gone from the README's feature list.** PHP has no background thread; the SDK re-fetches when the poll interval has lapsed (#2261).
- The reference page gains `inspectors` in the config table, `PREREQUISITE_FAILED` in the reasons table and `prerequisiteKey` on `EvaluationDetail` — all shipped, none documented — and its `forTesting()` signature is corrected (#2261).
- PHP is carved out of the SSE claims in the SDK overview and the reliability page, which presented streamed delta updates as universal across server SDKs. PHP polls and re-fetches the whole configuration, because it has no process that outlives a request (#2261).

## 2.4.2 — 2026-08-05

### Fixed

- `LICENSE` is now the verbatim Apache-2.0 text. Three phrases in the operative sections had been reworded and the appendix dropped, which left automated license scanners unable to identify it. The license itself is unchanged; the file now says what it always claimed to.

## 2.4.1 — 2026-08-02

### Fixed

- The `User-Agent` reports the SDK's real version. It had been pinned to `0.1.0` since the first release, so every request from a 2.x client identified itself as pre-1.0 (#2141).

## 2.4.0 — 2026-07-29

### Added

- **Evaluation inspectors (`onEvaluation`).** `Config` accepts an `inspectors` array of callables, each invoked synchronously with a `Featureflip\EvaluationEvent` exactly once per variation call on every exit path, including `FLAG_NOT_FOUND` and the evaluator's own `ERROR` reason. The event carries `flagKey`, a copy of the full `context`, the `value` the caller receives, `variationKey`, the SDK-native SCREAMING_SNAKE_CASE `reason`, `ruleId` (only on `RULE_MATCH`), `prerequisiteKey` (only on `PREREQUISITE_FAILED`) and an ISO-8601 `timestamp`. Non-callable entries are dropped when the client is created, and a throwing inspector is isolated (logged to the PHP error log; it neither changes the returned value nor stops the remaining inspectors). New type: `Featureflip\EvaluationEvent` (#1914). Also threaded through `FeatureflipClient::forTesting(array $flags, array $inspectors = [])`.

### Fixed

- An unexpected evaluator exception now fails safe to the caller's default with reason `ERROR` rather than propagating into the host request path. PHP was the only SDK where the throw escaped (#1990).
- A served variation key the flag does not define now reports reason `ERROR` with the caller's default, instead of a misleading success reason (#1989).

### Changed

- Dropped deprecated `ReflectionMethod::setAccessible()` calls (#1995).

## 2.3.0 — 2026-07-13

### Changed

- Covered by the cross-SDK golden-vector parity suite (#1477).

## 2.2.0 — 2026-06-19

### Added

- **Semantic-version condition operators** (`SemverEquals`, `SemverGreaterThan`, `SemverGreaterThanOrEqual`, `SemverLessThan`, `SemverLessThanOrEqual`) for local rule evaluation, comparing per semver precedence rather than as decimals (#1451).

### Fixed

- Per-flag rollout salt aligns bucketing with the engine and every other SDK; the previous `$flag` fallback re-bucketed users (#1452).
- Relational operators match against **any** supplied condition value (#1443).
- `MatchesRegex` is case-sensitive — the pattern is no longer compiled with the `i` modifier (#1453).
- Numeric operators return no-match on non-numeric operands instead of coercing `"abc"` to `0.0` (#1456).
- `Before`/`After` date operators aligned with the engine (#1455).
- Type-aware numeric coercion for `Equals`/`In` (#1458).
- Keyless rollouts serve the control variation deterministically (#1457).
- A context key explicitly set to `null` is treated as absent rather than `""` — the previous `array_key_exists` check let it fall through (#1460).
- Environment-level percentage rollouts with no variations no longer throw (#1469).

## 2.1.0 — 2026-05-27

### Added

- **Prerequisite flag support.** Flags can declare other flags as prerequisites; the flag's rules and fallthrough only run when every prerequisite serves the expected variation, otherwise the off variation is served with `reason = 'PREREQUISITE_FAILED'` and `prerequisiteKey` set on the `EvaluationDetail`. Resolution is recursive (depth-capped at 10, exceeded chains return `reason = 'ERROR'`) with per-call memoisation. New types: `Featureflip\Model\Prerequisite`. New `EvaluationDetail` field: `prerequisiteKey`. New batch entry point: `Evaluator::evaluateWithSharedMemo()` (#1112).

## 2.0.0

### BREAKING

- **Renamed `Client` to `FeatureflipClient`** — the main SDK class is now `Featureflip\FeatureflipClient`
- **Renamed `Client::create()` to `FeatureflipClient::get()`** — the factory method now returns cached instances keyed by SDK key (singleton-by-construction)
- **Constructor is private** — use `FeatureflipClient::get($sdkKey, $config)` to obtain a client

### Added

- **Singleton-by-construction factory** — `FeatureflipClient::get()` deduplicates by SDK key. Same key returns handles sharing one underlying connection and flag store
- **Refcounted disposal** — `close()` decrements a refcount. Resources are only cleaned up when the last handle closes. Double-close is a safe no-op
- **`FeatureflipClient::forTesting()`** — test stub factory (unchanged behavior, renamed from `Client::forTesting()`)

### Migration

```php
// Before (1.x)
use Featureflip\Client;
$client = Client::create('sdk-key', $config);

// After (2.x)
use Featureflip\FeatureflipClient;
$client = FeatureflipClient::get('sdk-key', $config);
```

All evaluation methods (`boolVariation`, `stringVariation`, etc.) are unchanged.
