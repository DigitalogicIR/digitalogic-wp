# Event-driven storefront delivery

Tracking: https://github.com/atomicdeploy/digitalogic-wp/issues/540

Product commits keep their existing identity-bound events. A transaction-owned, metadata-only outbox additionally guarantees a full `catalog.updated` invalidation can be recovered after a process crash. It stores generation, opaque token, UTC staging time and pending state; never prices or stock. It is staged before the owner COMMIT, dispatched after writer locks release, and acknowledged with exact-value CAS only after Panel queue persistence and delivered or durably recoverable Redis wake. Duplicate wake delivery is intentional. This is at-least-once invalidation, not business replay or exactly-once notification delivery.

Public WS retention gaps send `catalog.updated` with `cursor_reset_required`, `cursor`, and `reason=cursor_gap`; SSE uses `realtime.ready`. Clients reset even when the cursor moves backwards and fetch canonical authority. Filtered/private sequence gaps are not inferred from numeric adjacency.

The storefront consumes product/full invalidations. `/catalog/` reuses the current canonical page request while preserving filters, navigation and edited quantities. Main-loop product cards may use the bounded session-aware `wc-ajax=digitalogic_product_fragments` GET (maximum20 exact Woo IDs), generation-fenced before/after rendering with canonical theme templates. Other membership/layout changes use full canonical reconciliation. Healthy SSE uses configurable120-second recovery checks; failed transport uses15seconds. Events remain primary.

## Release evidence and boundaries

- First browser-consumer release installed2026-11-18; actual public `/catalog/` invalidation-to-render761ms after removing stale WP Rocket derived assets. Earlier installed-file hashes alone missed stale served JavaScript.
- `/shop/` currently renders category tiles; its4252ms refresh is not evidence of product-card price correctness.
- Focused PHP checks:88tests/610assertions,10Windows socket skips. Crash/rollback/outbox tests use isolated state; Linux real-Redis and live changed-value acceptance remain separate gates.
- No real price, stock, currency, order or Patris booking was changed for testing. Existing three sale-stock holds remain authoritative.
- Source branch reconciles previously deployed stock-hold receiver/feed changes, Panel MobileMergeEffects defer, and shopper WS implementation. These baseline differences must never be overwritten by older main files. Only reviewed changed files are deployed.
- Initial live backup: `/var/backups/digitalogic-storefront-events.OmDlvR`. Further backend deployment and complete end-to-end latency remain pending independent fixture acceptance at this checkpoint.

## Recovery and profiling evidence (2026-11-18)

The production `digitalogic-wordpress-cron.timer` runs independently of web traffic: `OnActiveSec=2s`, `OnUnitInactiveSec=10s`, `AccuracySec=1s`. Its service runs `wp cron event run --due-now` as `www-data` and checks that `DISABLE_WP_CRON` is enabled. Readback reported success and Apache, PHP-FPM and the WebSocket service active. The outbox's two-second retry delay is a due time, not an execution guarantee: recovery also waits for the timer and any preceding cron work. The shared runner has a 35-minute service timeout; continuous WebSocket connections alone do not accelerate it.

`Digitalogic_Product_Sync_Receiver::receive()` already returns `receiver_timing_ms`, including validation, transaction, projection, delivery planning, destination verification/drain, persistence and event phases; cache-flush and listener timings are added when exercised. Capture this from the next authorized natural delivery or isolated fixture. Nested timings overlap and must not be summed as independent wall time. Do not replay production prices or stock solely to obtain a profile.

Receiver post-commit invalidation explicitly covers WooCommerce post/meta, product instances, product transients and taxonomy caches. Currency commits also purge WP Rocket's domain cache. The ordinary-product candidate now persists `cache_product_ids` in the transaction-owned outbox and unions older pending coverage. After COMMIT and lock release, it purges HTML before publishing the catalog cursor. Provider failure retains the marker and schedules recovery; rollback publishes and purges nothing.

The page-cache adapter uses Rocket's canonical product URL planner and filters, includes variation parents and public taxonomy ancestors, and coalesces archive/shop URLs into one `rocket_clean_files` call. Homepage invalidation uses `rocket_clean_home` separately: passing the homepage directory to `rocket_clean_files` would recursively remove the whole site's cache. Archive-directory invalidation includes pagination. No alternative page-cache provider was found in this installation; an enabled `WP_CACHE` without the required provider fails explicitly rather than acknowledging the outbox. Rocket's void purge APIs prove invocation/completion, not physical deletion or upstream CDN invalidation. External normal-URL readback after a natural production commit remains a separate release gate; a cache-busting event refresh is insufficient.

The real-Woo fixture exposed a distinct new-product publication failure: the mapping guard rejects a priced second save before an applied source mapping exists. Existing-product delivery fixtures must seed an actual baseline source record and matching `applied_products[code].woocommerce_id`, all owner metadata and SKU, then clear the guard's request-local index. Metadata alone is insufficient. Preserve the new-product failure separately; do not disable the guard to make acceptance pass.

## Crash-recovery release blocker

The independent real-Redis fixture rejected `fcfb664`: after SQL COMMIT and process termination before post-commit cleanup, recovery acknowledged the event while the warm Mobile API still returned the previous stock and price with HTTP 200. HTML purge alone was insufficient. This candidate was not deployed.

The repair persists both changed leaf IDs and the receiver's coordinated parent cache plan. Recovery first clears supported runtime-only cache and the canonical Woo/post/meta/term caches, then advances the report generation through the existing durable effect receipt using the stable outbox token. It verifies the authoritative SQL generation before HTML purge, event publication and exact marker ACK. A generation failure or failed Woo runtime eviction retains pending work. Receipt reads are refreshed under the generation SQL mutex, preventing stale long-running workers from overwriting newer receipt state. Repeated recovery may repeat safe cache eviction but must not replay the business mutation or move the generation backwards.

Local crash/replay and generation-failure checks are separate from the required real warm-HTTP reproduction. Keep deployment blocked until that reproduction passes on the repaired head; do not treat TTL expiry, HTTP 503 or a later retry as immediate freshness acceptance.

Independent `fc021dd` acceptance passed 23 real-bus checks, including a warm Redis HIT followed by SQL COMMIT, SIGKILL and immediate fresh HTTP 200 before TTL expiry. Five HTTP worker checks established fresh request state with shared Redis; the persistent WebSocket daemon was checked separately. This supersedes only the crash blocker, not the outstanding visible-interface and production readback gates.

## First delivery of a new Product Code

Issue [402](https://github.com/DigitalogicIR/digitalogic-wp/issues/402) now has a real full-envelope reproduction without a preseeded applied mapping. The feed's price-authorized save succeeds, then the materializer's publication save fails after that authorization has ended. The product remains pending; per-product retryability and top-level retryability differ and must not be conflated.

The focused repair introduces an exact-object publication save scope after the materializer's existing target validation. The source lock, product lock, SKU, owner source/dataset/code, source revision and record hash must match at every guard boundary. Only that Woo object and its metadata ID receive write authority; the no-target authorization check remains false. Public mapping and price eligibility continue to require the committed applied ledger. The scope restores its prior value in `finally`, including failed saves and rollback. No provisional applied mapping or global guard disabling is used. A new-SKU real-receiver rerun is required before this repair can be accepted.

Rocket parser readback also confirmed that a draft/plain permalink such as `/?post_type=product&p=14701` resolves to path `/`; its query does not narrow filesystem deletion. The page-cache adapter therefore excludes root paths and the site's home path based on parsed host/path, regardless of query or fragment, including encoded slashes. Homepage invalidation remains exclusively `rocket_clean_home`; never send such URLs to recursive `rocket_clean_files`.
