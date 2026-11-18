# Durable product content and Google readiness

The canonical WordPress plugin owns `Digitalogic_Product_Content_Queue`. Product
publication, WooCommerce saves, stock-status changes and direct stock/identity
metadata writes wake an observation at request shutdown. Variations resolve to
their parent. A parent snapshot includes the available variation identities so
newly available options are reviewed as well.

Two private InnoDB tables retain observations and generation-specific jobs.
New publications, restocks, changed identity, erased descriptions and edited copy
are recoverable transitions. Repeated identical observations do not enqueue
duplicates. Quantity or price changes alone do not generate content work.
Draft/unpublished products are observed but not published by this worker.

The server runs three independent native systemd jobs:

| Timer | Work |
| --- | --- |
| `digitalogic-product-content-reconcile` | Scan 200 parents every two minutes using a durable cursor; a full sweep recovers missed hooks and direct SQL imports. |
| `digitalogic-product-content-worker` | Process two content jobs, then wait 30 minutes. |
| `digitalogic-product-seo-worker` | Process five SEO jobs, then wait ten minutes; normally at most 720 inspections/day. |

Event admission is synchronous at shutdown; the sweep is a safety net. Native
systemd supervision continues when the operator's PC is off. Rebooted services
resume persisted jobs and expired leases. Jobs use a 45-minute lease, random
fencing token and generation checks; a stale worker cannot finish or publish a
superseded job. Failed items use bounded exponential backoff. Evidence blocks
are delayed and do not stop other products. SEO waits for its content job.

`worker.py` reuses the installed Codex CLI to draft JSON from public product
inputs and primary manufacturer documents. Plugins and connected apps are
disabled for drafting, and its shell is read-only. The worker validates source
hosts and literal document excerpts, then passes a whitelisted payload to the
capability-gated WordPress publisher. Unknown manufacturers remain blocked for
review. Draft generation never gets a claim token. Public evidence uses the
existing loopback Privoxy egress; the pinned storefront is fetched locally.

Before publication the worker saves the previous product copy and SEO fields
under the root-only worker state directory. The publisher locks database rows,
checks the current product identity and content fingerprint, updates only copy
columns and approved metadata, and verifies the stored fields. Existing nonempty
guides are preserved. Stock, price, identifiers, slugs, ownership, title and
authentication state are outside its write contract. It clears the product and
Rank Math sitemap cache after commit. Both saved descriptions exclude store
identifiers and follow the internal Persian electronics glossary.

SEO verifies HTTP 200, canonical URL, robots indexability, a nonempty description
and exact inclusion in a product sitemap. It reuses the installed Search Console
importer's root-only credential and existing proxy for **read-only URL inspection**.
An inspection failure is retried. A non-PASS verdict becomes `waiting_google`
and is checked again in one to seven days. Only Google's observed PASS verdict
closes the SEO job. This does not use the restricted Google Indexing API for
ordinary product pages and does not claim to request or guarantee indexing.

## Operations

Use an existing verified WordPress administrator with both `manage_options` and
`manage_woocommerce`; no new account or secret is required. `--user=1` below is
the verified production administrator, not an authentication bypass.

```sh
wp digitalogic content-queue install --user=1
wp digitalogic content-queue reconcile --limit=200 --user=1
wp digitalogic content-queue observe --id=PRODUCT_ID --user=1
wp digitalogic content-queue status --user=1
wp digitalogic content-queue claim --stage=content --id=PRODUCT_ID --user=1
wp digitalogic content-queue publish --user=1 < reviewed-payload.json
wp digitalogic content-queue finish --user=1 < fenced-outcome.json
```

Claims and publication payloads are private; never paste claim tokens into a
report, public log, public product field or model prompt. `status` omits tokens.
Manual repair uses the same lease and publisher contract, followed by public
readback before `finish`. A public readiness check is not a Google indexing
result. Private competitor matching remains in the existing research ledger;
it must not be fabricated from a matching product title.

Install the reviewed `worker.py`, prompt, schema and manufacturer allowlist
under `/opt/digitalogic-product-content` as root-owned files. Install the six
unit files under `/etc/systemd/system`, validate with `systemd-analyze verify`,
and enable the three timers. Keep state under
`/var/lib/digitalogic-product-content` as root mode 0700 (files 0600). Preserve
the existing WordPress site-owner execution and server-local Google credential.
Do not put rollback files in the public WordPress tree. The ops runtime is
installed separately from the plugin ZIP, while the queue class is included in
the canonical plugin bootstrap so a plugin upgrade retains lifecycle hooks.

The reconciler publishes aggregate queue counts and sweep timestamps to the
existing node exporter textfile collector. The private `status.json`, job
results and systemd journals make blocked sources, Google errors and retries
inspectable without treating them as successful work.

## Verification

```sh
python3 -m unittest discover -s ops/product-content -p 'test_worker.py'
composer test -- --filter ProductContentQueueTest
```

Production acceptance also exercises duplicate observations, exclusive and
expired leases, stale/superseded tokens, A-B-A restock, delayed blocked jobs,
content-before-SEO ordering, compare-and-set publication, both description
readbacks and preservation of commercial metadata in isolated test tables.
Then inspect actual product pages and current Search Console results.
