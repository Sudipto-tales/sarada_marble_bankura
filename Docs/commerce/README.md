# Shared-hosting commerce implementation pack

Prepared on 2026-10-05 for `sarada_marble_bankura`.

This pack turns the proposed Hostinger architecture into repository-specific work. The documentation pack is complete and FND-01/02/03 foundations are implemented and verified locally. Commerce modules, workers and deployment remain upcoming work; see the tracker for evidence and hosting checks.

## Files

| File | Purpose |
| --- | --- |
| [prompt.md](prompt.md) | Copyable implementation prompt for a coding agent |
| [implementation-plan.md](implementation-plan.md) | Architecture, phases, dependencies, and acceptance criteria |
| [tracking.md](tracking.md) | Canonical task status, evidence, blockers, and handoffs |
| [agents.md](agents.md) | Agent roles, file ownership, delegation prompts, and integration rules |
| [code-map.md](code-map.md) | Verified existing files, line anchors, function names, and missing behavior |
| [contracts.md](contracts.md) | Canonical units/money, service contracts, locks, idempotency and errors |
| [data-flow.md](data-flow.md) | Request, catalog, checkout, jobs, analytics, import and mobile diagrams |
| [tasks/README.md](tasks/README.md) | All 34 tasks mapped to 11 focused guides with algorithms and test vectors |
| [../../AGENTS.md](../../AGENTS.md) | Repository instructions for agents doing the implementation |

## Start here

1. Read the implementation plan and root `AGENTS.md` for scope and dependencies.
2. Select one task from the task index; read its guide, shared contracts and linked code symbols. Use the smaller-model prompt in that index to keep the session focused.
3. Claim the first dependency-ready task in the tracker. Continue with `IAM-01` commerce grants/provisioning after verified foundations; hosting discovery remains open for account observations.
4. Record changed files, verification results, and unresolved issues after each task.
5. Advance through the MVP gates before attempting optional integrations or Flutter networking.

Task IDs are shared across the plan, tracker, and agent briefs. `done` means acceptance criteria have been demonstrated; a scaffold alone does not complete a functional task. Hosting-specific claims remain unverified until `HST-01` records the actual account capabilities.

The guides specify actual decisions and algorithms rather than empty PHP class templates: unit/money arithmetic, route matching, permission checks, stock transitions, transaction boundaries, leased queue claims/recovery, cache versioning, restartable aggregation and bounded search/recommendation work. Planned file paths and commands are explicitly distinguished from existing implementation. A free or smaller model can work one task at a time; passing relevant checks remains necessary.
