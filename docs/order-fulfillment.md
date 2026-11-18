# Order fulfillment

The physical shipping workflow is `review` (در انتظار بررسی), `processing`
(در حال پردازش), `shipped` (ارسال شده), then `completed` (تکمیل شده).
Payment completion and cash-on-delivery admission select `review` instead of
`processing`. Virtual orders that already complete automatically are unchanged.
Bank transfers remain `on-hold` (در انتظار تأیید پرداخت) until payment is confirmed.

For orders whose shipping lines are all `local_pickup`, dispatch and completion
are one action: the stored terminal status remains `completed`, displayed as
تحویل شده. An attempted `shipped` save is normalized before persistence, so it
does not emit a separate shipped event. Missing or mixed shipping methods never
imply pickup. Existing order statuses are not migrated.

The admin editor hides dispatch for pickup. Admin rows, previews, account order
lists, order details/tracking, invoices, panel rows, REST display labels, and
voice/SMS status placeholders use the order-aware label. The global completed
filter remains تکمیل شده because a filter can contain both shipping and pickup.
REST status identifiers remain stable; `status_label` supplies the display text.

Review and shipped are registered, searchable, paid statuses. Stock reduction,
stock reservation release, sales counts and coupon usage use WooCommerce's
existing idempotent handlers. Payment holds are not added to paid statuses.
New-order and order-received emails retain their enabled settings through the
review transition. New voice events remain opt-in; existing configured templates
are preserved. Dispatch does not automatically confirm final delivery. Staff
complete shipping orders when the final completion criteria have been met.

Focused tests cover the primary sequence, preservation of auxiliary/plugin
statuses, mixed/missing shipping methods, normalization, contextual labels,
payment semantics, transactional email routing, and bulk controls.
