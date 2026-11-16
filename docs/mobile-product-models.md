# Product summaries and complete model details

The public WooCommerce catalog remains the identity, availability and price authority. Bootstrap advertises `capabilities.product_models: true` only in the runtime containing the new read route. The existing product routes and compact legacy PDP variations remain compatible.

## Client contract

`GET /app-api/products` returns `detail_level: summary`: one cover image, no full description, empty `attributes` and `variations`, canonical category summaries and the exact full public `variation_count`. Variable products load their public children once per projection to compute both count and available-only prices. Counts include unavailable models; unavailable price fields and `modified_at` stay absent/empty and null respectively. No prices or stock are inferred from names or attributes.

`GET /app-api/products/{id}` returns `detail_level: full`, full assigned gallery, visible attribute options, description, related IDs and approved documents. A variable parent retains the first 100 compact public variations for older clients, now filtered before slicing. `variations_complete` states whether that compact inventory is complete. `models_endpoint` identifies the additive full-model route; `models_complete` is false unless the parent has no public children, since compact rows are not full model details.

```http
GET /app-api/products/14382/models?page=1&per_page=24
GET /app-api/products/14382/models?page=2&per_page=24&revision=<first-page-revision>
```

Response fields:

| Field | Contract |
| --- | --- |
| `parent_id` | Exact requested public variable parent |
| `revision` | 64 lowercase hex characters, shared by pages from one committed catalog generation |
| `cache_ttl_seconds` | 60, server cache expiry; not permission to ignore a change event |
| `items` | Full canonical product projections, `type: variation`, exact `id`, `parent_id`, `variation_attributes` map, assigned media and actual available-only prices |
| `pagination` | Integer `page`, `per_page`, `total`, `total_pages` |

Default page size 24, maximum 50; page range 1–999999. Numeric child identity ordering is stable within a revision. The complete inventory is filtered by the same public product predicate as detail: exact parent relation, published, no password, visible and valid public title. Draft, hidden, private, unrelated and removed records cannot appear. Out-of-stock public models remain selectable for viewing, with no saved price or price-update date. Requests past the last page return an empty list with truthful totals. There is no 100-model ceiling on this endpoint.

Every continuation after page1 must include the first-page revision. Missing/malformed continuation returns 400 `model_revision_required`; malformed numeric query returns 400 `invalid_model_query`. Changed revision returns 409 `{code: catalog_revision_changed, reset: true, revision: <current>}`: discard the incomplete model set and restart page1. Revision lifetime ends when the canonical generation changes; there is no server-held cursor or snapshot reservation. A generation failure returns 503 `catalog_generation_unavailable`; a concurrent change returns 503 `catalog_changed_during_read` with `reset: true`. Errors are never cached. Product absence/non-variable parent returns 404 `product_not_found`.

Retain full details separately from summaries in the client. Loading another PLP must not replace a hydrated PDP with an empty summary. Background loading may show the selected model immediately while remaining pages hydrate; cancellation and account/navigation fences belong to the client. Offline cached details must preserve their age, completeness and revision and revalidate on resume.

## Cache and change propagation

The existing WordPress object cache is connected to Redis. Laravel's configured default cache is database, but this adapter does not create a second Laravel cache. `MobileCatalogCache` uses 256 deterministic slots in group `digitalogic-mobile-v2`, an exact-key envelope, 60-second expiry and 256 KiB maximum encoded payload. A collision is an eviction/miss, never a different response. Oversize or cache failure bypasses admission; the maximum payload footprint is 64 MiB plus serialization/key overhead. No key scan, unbounded registry or global flush is used. Old mobile cache keys expire normally.

The canonical report generation is read directly from the database before cache lookup/build and again before response. Missing or changed generation cannot authorize a shared fallback key, mixed response or cache write. Public HTTP uses weak semantic ETag with `max-age=0, must-revalidate`; Redis still serves a hit without rebuilding. WordPress authenticated contexts use `private, no-store` and bypass shared response storage.

Existing canonical product/variation save, Patris sync, price/stock metadata, attachment and taxonomy hooks remain authoritative. Additional watched fields cover gallery IDs, approved product documents, image alt text, sale dates, backorders, sold-individually and review aggregates. Direct stock hooks use the existing report transaction deferral. Generic attribute term changes and the terminal `after_delete_post` hook also advance generation; the latter runs after WordPress clears its deleted-object cache. No production catalog records are rewritten by this feature.

SSE and shopper WSS retain their existing shared public projection:

```json
{"name":"product.deleted","data":{"scope":"product","product_id":14382,"object_id":11944,"parent_id":14382,"change":"deleted"}}
```

`object_id` is the changed/deleted child; `product_id` is its canonical parent. For a root deletion, `object_id == product_id` and `parent_id == 0`. The producer captures identity before deletion; the terminal generation emits the existing coalesced `search.invalidated` refresh. WSS additionally preserves stable `event_id: wp-panel:<queue-id>`, independent of numeric replay cursor. Events contain no prices, private catalog fields or customer information. Clients should refresh the affected open PDP/model family and PLP; `search.invalidated`, currency change or cursor reset requests broader reconciliation.

## Acceptance ledger

- Source implemented: five scoped runtime files; no auth, provider template, cart or order mutation changes.
- Focused unit coverage: cache bounds/collisions, complete child inventory, continuation validation, generation read/build/hit fences, ETag 304 and authenticated cache bypass.
- Canonical isolated Woo fixture, deployment hashes and public apex/PWA readback are recorded in the owner PR checkpoint after their gates pass. Production product edits and real orders/SMS are excluded from this acceptance.
