# Task guide index

Each task has a focused section below. Read [shared contracts](../contracts.md), the selected section and its source links; use [code-map.md](../code-map.md) when a symbol is unfamiliar. The [plan](../implementation-plan.md) and [tracker](../tracking.md) remain authoritative for dependencies/status. Everything here is an implementation specification, not completed runtime behavior.

| Task ID | Focused guide |
| --- | --- |
| DOC-01 | [Document maintenance and evidence](10-operations.md#doc-01) |
| HST-01 | [Host capability discovery](01-foundations.md#hst-01) |
| FND-01 | [PDO, migration ordering and CLI](01-foundations.md#fnd-01) |
| FND-02 | [Method/parameter routing and loading](01-foundations.md#fnd-02) |
| FND-03 | [Session/request/secret protections](02-identity.md#fnd-03) |
| IAM-01 | [Commerce roles and provisioning](02-identity.md#iam-01) |
| IAM-02 | [Guards, ownership and audit](02-identity.md#iam-02) |
| DAT-01 | [Catalog and variants](03-catalog-carts-schema.md#dat-01) |
| DAT-02 | [Inventory and reservations](04-inventory-orders.md#dat-02) |
| DAT-03 | [Addresses/carts and guest merge](03-catalog-carts-schema.md#dat-03) |
| DAT-04 | [Order snapshots and idempotency schema](04-inventory-orders.md#dat-04) |
| INF-01 | [Durable queue producer/schema](05-queue.md#inf-01) |
| INF-02 | [Claims, worker and retries](05-queue.md#inf-02) |
| INF-03 | [Cache and invalidation algorithms](06-cache.md#inf-03) |
| ADM-01 | [Protected local admin layout](07-admin.md#adm-01) |
| ADM-02 | [Product CRUD and upload lifecycle](07-admin.md#adm-02) |
| ADM-03 | [Inventory/order/customer admin](07-admin.md#adm-03) |
| ORD-01 | [Transactional order placement](04-inventory-orders.md#ord-01) |
| ORD-02 | [Expiry/cancel and notification jobs](04-inventory-orders.md#ord-02) and [queue handlers](05-queue.md#ord-02) |
| WEB-01 | [Catalog/product SSR](08-storefront-api.md#web-01) |
| WEB-02 | [Cart/account and checkout flow](08-storefront-api.md#web-02) |
| WEB-03 | [Versioned API contracts](08-storefront-api.md#web-03) |
| EVT-01 | [Validated event ingestion](09-analytics-search-pricing.md#evt-01) |
| EVT-02 | [Checkpointed aggregation/retention](09-analytics-search-pricing.md#evt-02) |
| PRC-01 | [Bounded fixed-precision pricing](09-analytics-search-pricing.md#prc-01) |
| SRCH-01 | [Search candidates and ranking](09-analytics-search-pricing.md#srch-01) |
| REC-01 | [Bounded recommendation rebuild](09-analytics-search-pricing.md#rec-01) |
| OPS-01 | [Cron/metrics/recovery runbook](10-operations.md#ops-01) |
| OPS-02 | [MVP acceptance matrix](10-operations.md#ops-02) |
| OPT-01 | [Marketing/reviews/wishlists](11-optional-mobile.md#opt-01) |
| OPT-02 | [Validated product import](11-optional-mobile.md#opt-02) |
| OPT-03 | [Drive staging/sync](11-optional-mobile.md#opt-03) |
| OPT-04 | [Provider/payment state boundary](11-optional-mobile.md#opt-04) |
| MOB-01 | [API adapters and offline isolation](11-optional-mobile.md#mob-01) |

## Small-model execution prompt

```text
Implement only <TASK-ID> in sarada_marble_bankura.
Read AGENTS.md, its Docs/commerce/tracking.md row,
Docs/commerce/contracts.md and the linked section in tasks/README.md.
Open only the existing functions referenced by that guide first.
Verify prerequisites are integrated; do not invent an existing API or table.
Follow the named algorithm and test vectors, preserving current behavior.
Create only files required for real behavior; do not add empty skeletons,
generic CRUD generators, or placeholder dashboards.
Run relevant checks on disposable/local resources, update task evidence,
and report any required MySQL/provider checks that remain unperformed.
Do not deploy or start deferred tasks.
```

A model's cost/tier does not prove capability. These guides reduce the amount of inference required; tests and review still determine correctness. If a guide conflicts with code after changes, report the exact conflict and update its source references rather than copying outdated assumptions.
