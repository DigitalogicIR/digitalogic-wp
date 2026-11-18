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
