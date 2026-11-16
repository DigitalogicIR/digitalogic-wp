# Shared customer activity

WooCommerce remains authoritative for customer carts and checkout. Woodmart remains authoritative for wishlists. Recently viewed products use one bounded record on the existing WordPress customer, projected into the installed website providers and returned through the same Laravel account gateway used by Android and the PWA.

## Client contract

Both routes require the existing current native bearer or the PWA's host-only encrypted cookie plus its tab-scoped CSRF generation. Clients cannot supply an account ID. Responses and errors are private/no-store.

| Route | Input | Response |
| --- | --- | --- |
| `GET /app-api/account/recently-viewed` | None | `{items:[{product_id,viewed_at}],revision}` |
| `POST /app-api/account/recently-viewed` | `{views:[{product_id:int,viewed_at:ISO8601 UTC}]}` | Same canonical snapshot |
| `GET /app-api/account/events` | `cursor` integer, `wait` from 0 to 20 seconds | `{events,cursor,has_more,reset}` |

Bootstrap advertises `capabilities.recently_viewed`, `capabilities.account_events` and their matching endpoint URLs. The rollout option is `digitalogic_mobile_account_activity`. A capability does not replace account authentication.

History accepts at most 30 entries per batch and retains the newest 30 distinct public product identities from the last 90 days. UTC timestamps may use `Z` or `+00:00`, with optional fractional seconds; responses use UTC whole seconds. For each product, the greatest timestamp wins. Repeating an accepted batch is safe, and older offline intent cannot move a newer view backwards. Equal timestamps use product ID as a deterministic tie-break.

Removed/private products, entries older than retention, and clock values more than five minutes ahead are ignored with a successful snapshot so one unusable entry does not strand the remaining outbox. Acceptable future skew is clamped to server time. Malformed fields or unsupported request keys reject the request. A timestamp supplies ordering only; it grants no identity, product, price or stock authority.

Guest history stays on its own device/browser. Signing in does not silently claim a shared browser's guest cookie. WordPress user metadata `_digitalogic_recently_viewed` is the canonical customer record. Bounded hourly cleanup removes expired stored entries; reads always apply retention immediately.

## Private updates

The endpoint reuses the existing durable event sequence and shopper projection. `cart.updated`, `favorites.updated` and `history.updated` carry reduced invalidation metadata; they contain no product lists or browsing contents. Existing owner-scoped profile, order and message invalidations retain their established projection. No other account's event is returned. Public browser WebSockets remain public.

An idle poll returns HTTP 200 with an empty `events` list within 20 seconds. `has_more` asks the client to continue from the returned cursor. `reset:true` means the cursor is initial, outside the retained queue or ahead of the server: refresh current account snapshots and adopt the returned cursor. Numeric replay IDs remain separate from stable `event_id` values such as `wp-panel:123`.

Each poll is bound to its captured account and session, with authority checked before reads and immediately before returning. Revocation, expiry or a retired merge alias returns 401 without an event payload. One poll per session plus bounded admission protects the PHP worker pool. HTTP 429 asks the client to back off. PWA generation mismatch remains 403 before the controller, preventing a stale tab from reading another signed-in account. Abort local requests on logout/switch; never replay ambiguous mutations under the new account.

## Existing website behavior

The website uses its ordinary WordPress logged-in cookie and `X-WP-Nonce` on separate same-origin REST adapters. It never receives a native bearer or a panel-session handoff. Visible product-page JavaScript records the current product; server prefetch/rendering alone does not count a view.

Woodmart's `woodmart_recently_viewed_products` cookie is newest-first. WooCommerce's `woocommerce_recently_viewed` cookie is oldest-first. Their existing size limits and widget behavior remain in place. Where a view identifies a variation, website presentation uses its exact public Woo parent, while the cloud record retains the actual variation ID. No name/SKU similarity establishes that relationship.

Known Woodmart AJAX handlers keep their own nonce, validation and write logic. The adapter compares canonical membership before/after the completed provider action, then emits an owner-only invalidation. Native wishlist events emit after commit. Cart events remain behind canonical Woo session persistence. Inbound website refreshes use bounded same-page reads, revalidate the captured cookie/nonce, and then replace exact recent, wishlist, cart and mini-cart fragments. They never trigger a second unfenced Woo AJAX fetch. Focused/dirty controls and checkout are preserved.

## Account consolidation

History content and count join the signed merge-plan inventory. The accepted operation unions source and target by greatest timestamp under the existing owner locks, inside the canonical transaction. Target history is bounded by the same retention rules, source alias history is retained, and invalidation delivery is deferred until commit. A changed inventory invalidates the old plan rather than silently changing reviewed intent. Pending client history should be drained before requesting the plan.

Native wishlist synchronization also takes the canonical identity owner lock and rechecks the initiating session after waiting. A retired source cannot use a delayed request to mutate its old wishlist after consolidation.

## Delivery status

The full source suite passed 1,342 tests / 12,031 assertions (35 platform-dependent skips). New PHP adapters pass WordPress PHPCS. Six JavaScript unit checks and seven real Chromium scenarios cover rendered fragments, dirty/focused carts, checkout protection, hidden product pages and session-switch rejection.

The isolated canonical WordPress/WooCommerce acceptance passed 79 assertions: native/PWA history (28), actual website cookie/REST/provider rendering and private events (28), in-flight session revocation (5), and real account-merge rollback/union/recovery (18). The fixture made no orders or successful external connections and restores its exact pretest database. Production activation and independent readback are tracked separately; no real customer history mutation is claimed by these fixtures.
