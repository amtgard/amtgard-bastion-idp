# Logging spike (design only)

| Document | Purpose | Status |
|----------|---------|--------|
| [design-plan.md](./design-plan.md) | Architecture: correlation, levels, SQLite/text, retention, tooling, log-read API + ORN | Ready |
| [instrumentation-checklist.md](./instrumentation-checklist.md) | **211** sites at `9980cf5`, milestones M1–M7 + global correlation | Ready |

Implementation is **not** in scope until both documents are reviewed and a delivery branch is cut.

**Agents:** [Logging spike design plan](b8a66ccf-d3f4-4886-a30e-f87c016418e0) · [Logging instrumentation checklist](b15dd3dd-e173-4a79-ae01-4e559d68bf54)

**Related:** [Style refactor implementation plan](../style-refactor-implementation-plan.md) established structured logging patterns in application code; this spike defines platform-wide observability.
