# Developer knowledge lab — DEV-KB-01

Owner: coordinator. Status: in_progress. Claimed 2026-10-13.

Add a separate `/developer/knowledge` page; preserve the existing console and login.
Use a dark technical canvas with a searchable module/API/function tree, node details,
animated recorded execution, recent traces, and daily health reports.

Source contracts reviewed: DeveloperAccess::PAGES/identity, Developer::start/index,
RouteManager::dispatch/compile, api/gateway.php, CatalogReadService::listing/detail/read,
ProductService::listing/detail, CommerceDatabase::query, MigrationRunner, CLI vayu.
Existing design assets and modifications belong to the preceding DEV-UI-01 slice.

Acceptance:

- Derive actual APIs from registered routes; distinguish registered knowledge from
  recorded execution and uninstrumented functions. No inferred successful steps.
- Test only allowlisted same-origin read-only APIs. Display actual response status,
  trace ID and step timings; playback follows captured parent/order relationships.
- Persist bounded sanitized spans without arguments, query values, SQL text,
  response bodies, customer identities or exception messages. Fail open on storage
  failure; never commit a business transaction to save monitoring.
- Reuse the dedicated Developer session and `monitor.workflows.read` permission.
  All data/report endpoints check authorization server-side.
- Report observed API and module counts, 5xx failures, expected 4xx outcomes and
  durations by UTC day. No observations means unknown health. Persist daily
  snapshots using a short CLI/cron command; support report download in the UI.
- Verify SQLite and actual disposable MySQL migration/storage behavior, routing and
  Developer regressions; verify rendered desktop/mobile interactions.

This is explicit span instrumentation, not automatic PHP profiling. Only registered
instrumented boundaries can appear as observed functions. Execution animation is
playback of captured timings; ordinary short PHP requests complete before playback.
Availability outside the PHP process requires an independent host monitor.

Setup, evidence and limits: pending implementation.
