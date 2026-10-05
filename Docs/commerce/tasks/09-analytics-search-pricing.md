# Event ingestion, bounded analytics, search, pricing and recommendations

Scope: EVT-01/02, PRC-01, SRCH-01, REC-01. Read [contracts](../contracts.md) and [queue](05-queue.md) first. Heavy scans/rebuilds run in bounded maintenance jobs, not customer requests. Names below are proposed services/tables.

Existing references: [PDO helper](../../../Website/config/db.php#L36), [API registration](../../../Website/api/gateway.php#L5), [mobile product filters](../../../Mobile/lib/data/models/filters.dart#L1), [static product query behavior](../../../Mobile/lib/data/repositories/static_repositories.dart#L32), [mobile rating/price fields](../../../Mobile/lib/data/models/product.dart#L3).

## EVT-01

Allowlisted events: product_view, search, add_to_cart, purchase, and server-authorized order_cancelled when needed for net-sales accounting. Public events carry bounded product/variant/category IDs or normalized query; server derives principal, session key, time and request ID. No arbitrary browser `purchase` or user IDs. Limit payload size, frequency and retention; no email/address/payment/token content.

Proposed events fields: id, event_type, event_key unique nullable for ordinary observations, user_id nullable, anonymous/session pseudonymous key, product_id/order_id nullable, bounded payload, occurred_at, created_at. Add `event_processing` metadata (`event_id` unique FK, state pending/processed, processed_at) separately so events themselves remain immutable. Purchase key = `order:<id>:purchase:v1`; cancellation key is its unique order transition. Keep trusted purchase/cancellation business-key markers in a separate durable ledger (or equivalent immutable order metadata) that survives raw-event retention; deleting an old event must not let an operator-replayed intent count the order again.

Ingest algorithm: validate type/fields/principal/rate → begin → insert/check the durable trusted business key where required → insert event with unique key → insert processing metadata → commit. Duplicate trusted key returns the prior event reference or an already-archived result without another pending row. Handler for held `purchase_events` jobs loads authoritative order snapshot and writes business key/event/processing metadata atomically; replay is idempotent. Enable queue only after schema, consumer and backlog replay checks pass. Event handler must not discard accepted intent because notification delivery failed.

Transaction ownership: the begin/commit steps above apply only to standalone ingestion. When called from a DB-only queue handler or order transaction, EventService receives the existing PDO/transaction and never begins/commits its own transaction. In a job, event + durable business key + processing metadata + final token/live-lease acknowledgment commit together; failed acknowledgment rolls them all back under the queue guide's domain-first effect protocol.

Purchase here means accepted order placement, not verified payment revenue. Net sales/demand metrics exclude cancelled orders through authoritative state/reversal events; label dashboards accordingly. Real settlement/refund metrics wait for payment integration.

Checks: forged purchase denied, oversized/rate-limited public event, duplicate purchase intent, missing-order intent, cancelled order correction, session/PII policy, held backlog then enable/replay, concurrent duplicate event keys.

## EVT-02

A naive `last_event_id = max(id)` watermark can skip an earlier insert that commits after a later ID. Do not use it as the sole processing guarantee. Pending metadata is the durable source of unprocessed work, with bounded indexes/state claims.

Batch algorithm:

1. Begin; lock up to a configured small batch of pending processing rows in ID order, using supported queue-like skip-locking or a tested serialized fallback.
2. Read immutable events, resolve trustworthy order facts, and compute deterministic daily deltas/pair contributions.
3. Update `product_daily_metrics` with unique product/date buckets and insert unique event/pair contribution records where necessary. Apply late-arriving events to their own UTC bucket, not today's bucket.
4. Mark those metadata rows processed and update progress/checkpoint in the same transaction. On crash all effects/checkpoint roll back; retry must not double count.
5. Commit, stop at runtime/batch limit; never hold the transaction while writing archives or contacting providers.

Order cancellation correction must use a consistent metric policy: purchase contribution is counted once; cancellation inserts one negative contribution against the original order bucket, or a bounded authoritative bucket rebuild replaces it. Do not apply both. Use indexed authoritative order data to handle reversal arriving before purchase processing. Counts and monetary metrics must be explicitly net/gross labeled.

Retention: select processed events older than configured cutoff, never pending intents → write bounded private archive segment if retention policy requires it → verify durable archive manifest/checksum → delete only that confirmed batch/processing metadata in a bounded transaction. Plain expired-observation deletion can be a configured policy; do not call deletion an archive. Keep IDs/business dedupe records long enough for all retries/rebuilds, or duplicate historical intents can count again. Track archive failure and lag without unbounded growth hidden by a success flag.

Vectors: crash between metrics update/checkpoint; late low-ID commit; duplicate/late events; cancellation before purchase processed; replay; archive write failure; expired event still pending; deterministic 30-day window rolloff. UTC/day boundary uses DB-configured timezone consistently.

## PRC-01

Required MVP capability, default disabled. Proposed pricing_rules hold allowed type, scope, effective interval, integer factor_ppm, min/max/floor/ceiling, priority/revision and actor audit. Rules never reference individual willingness-to-pay.

Algorithm: base unit price → current published metric generation → choose allowed active demand/inventory/campaign/membership factors by deterministic priority → multiply bounded factors with integer half-up rounding at each documented step → clamp approved floor/ceiling → return unit_price_minor and rule/calculation revision. Initial demand/inventory examples can use threshold bands instead of fitting an ML model; define bands/configuration explicitly. Missing metrics/rules or disabled pricing returns base price, not zero. Membership applies only to an explicit published benefit.

Concrete initial rule policy for an enabled test/store-configured ruleset:

| Factor | Metric and decision | factor_ppm |
| --- | --- | --- |
| Demand neutral | Fewer than 3 accepted noncancelled product orders in prior 28 days, or missing metrics | 1,000,000 |
| Demand high | Let A = accepted product-order count in last 7 days, B = count in the immediately preceding 28 days; `8*A >= 3*B` after minimum-support check | 1,050,000 |
| Demand low | Supported baseline and `8*A < B` | 950,000 |
| Demand normal | Otherwise | 1,000,000 |
| Inventory high | Variant on-hand > 0 and `4*available >= 3*on_hand` | 980,000 |
| Inventory otherwise | Remaining valid stock states; do not add scarcity surcharge initially | 1,000,000 |
| Campaign/member | Explicit active published configured benefit; only one winning rule per factor scope | Configured bounded factor, neutral when absent |

These bands are initial configurable business policy, not measured optimal prices. Product-order counts deduplicate products within one order and exclude cancellations; do not compare heterogeneous sqft/slab quantities as if they were the same unit. Apply factors in demand → inventory → campaign → membership order, round after each factor and clamp to approved limits. Checked cross-multiplication avoids float thresholds and overflow.

Recalculate through ProductService/PricingService on product/rule changes or bounded job batches; advance durable cache version when a published effective price changes. Checkout recomputes/validates current effective price and persists the rule version/amount; existing order snapshots never change. Keep quote acceptance policy explicit when a factor changes between preview and submit.

Vectors: base 10,000 with factor 900,000 → 9,000; disabled rules → 10,000; floor 9,500 with that factor → 9,500; factor/product overflow rejected; overlapping rules deterministic; expired rules ignored; order snapshot preserved after recalculation. Admin preview explains factors and never presents simulated demand as measured data.

## SRCH-01

Add native MySQL FULLTEXT on agreed name/description/tags text columns. Verify availability, tokenizer/min-word/stopword behavior and the exact deployed engine. Use prepared MATCH/AGAINST inputs plus ordinary filters. SQLite fallback uses bounded escaped LIKE/category matching with documented reduced relevance; do not issue MySQL FULLTEXT there.

Ranking is two stages to keep work bounded:

1. Candidate query: visible products with filters and lexical score; deterministic relevance then ID order; candidate limit initially 1,000 (configurable/measured). No-keyword discovery uses a separately documented base sort. Exclude nonsellable variants before ranking.
2. Bulk-load daily sales/rating/stock/feature/freshness inputs for those candidates. Normalize to [0,1], protect zero denominators, compute weighted score, sort score descending then product ID ascending.

Weights: relevance .40, net sales_30d .20, rating .15, stock .10, featured .10, freshness .05. Until reviews are implemented, omit rating and divide remaining weights by .85. Freshness is `max(0, 1 - age_days/90)` initially; stock is a binary sellability factor; sales normalized by max candidate sales; lexical score normalized by max candidate lexical score. No keyword means redistribute absent relevance weight too or use a separate explicitly documented discovery sort.

Pagination operates on a stable cached candidate/ranked generation (catalog + metrics version + normalized query). Candidate caps make results approximate over a larger corpus; expose capped/has_more metadata, not a false exact total. Measure query plans/latency/memory before raising limits or broad wildcard fallback. Escape LIKE wildcard characters for SQLite fallback; never concatenate uncontrolled sort or boolean operators.

Checks: zero matches/zero max sales, stopwords/short words, price/category/brand filters, same-score ID tie, stock removed after cache warmup, stable next page, candidate-cap honesty, full-text MySQL query plan and fallback behavior.

## REC-01

Start with category/brand/price similarity; co-view/co-purchase runs only with sufficient validated data. Proposed pair contributions/counts use bounded generation/windows and unique support keys to avoid repeated refresh inflation.

Co-view algorithm: for a validated view, load at most the last 10 distinct products in the same pseudonymous session within 30 minutes → create directional source/target pairs excluding self → insert unique `(source, target, session, UTC-day)` support → aggregate a 30-day window. Add indexed session/time lookup; never cross join the whole events table.

Co-purchase algorithm: read one accepted, noncancelled order's distinct product IDs, cap batch policy (initial max 20 for pair computation; larger orders use a documented bounded sampling/rule) → generate pairs excluding self → one unique order/pair support → aggregate excluding cancellations. Do not count quantities or retry attempts as separate people.

Rebuild top suggestions in bounded source-product batches using pair support (initial minimum 3 distinct supports), then category/brand/price-distance fallback. Filter inactive/nonsellable/self products; deduplicate; stable tie by ID; initial result max 8. Write shadow generation, mark complete then atomically publish generation/cache version. Partial generation is invisible. A missing generation returns similarity/popular fallback.

Tests: sparse/new product, repeated same session/order, >20-product order, cancellations, self/out-of-stock candidates, crash before publish, generation swap, maximum pair work bound and deterministic fallback. Avoid demographic or individual price inference.
