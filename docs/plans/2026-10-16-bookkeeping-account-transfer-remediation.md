# Bookkeeping Account and Transfer Remediation

> **AI 生成聲明 (AI-Generated Declaration)**：本文件由 AI 依需求分析自動生成，內容尚未經人類審核。僅供參考，需人類 review + confirm 後方可執行。
> **Review Status**: ⚪ Pending
> **Reviewed By**: (人工填寫姓名/日期)

**Human Review**: ⚪ Pending
**Created**: 2026-10-16
**Status**: 🟡 In Progress
**Priority**: High

---

## Background & Goals

Correct bookkeeping account-default integrity, close account-activity races during entry and transfer writes, add revisioned transfer updates across MCP and Filament, remove the accounts-page balance N+1, and tighten MCP input schemas. No migration, import, legacy-table operation, or destructive database command will be executed.

## Tasks

| # | Task | Status | Sub Agent | Notes |
|---|------|--------|-----------|-------|
| 1 | Add a forward MySQL migration and adjust account service/model behavior so only the default row stores `true`; all other rows store `NULL`. | ✅ Complete | — | The migration normalizes `false` to `NULL`; the existing MySQL nullable unique index permits many `NULL` rows and one `true` row. It was not run. |
| 2 | Lock account rows and validate active status within the write transactions for entries and transfers. | ✅ Complete | — | Entry and transfer create/update paths lock account rows and validate activity before persistence. |
| 3 | Implement revision-checked, idempotent transfer update service behavior; expose it as a constrained MCP tool and Filament edit action. | ✅ Complete | — | `update_transfer` preserves existing operation/audit/replay behavior. |
| 4 | Replace per-record account balance calculation with a query-level aggregate that uses decimal strings rather than floats. | ✅ Complete | — | Accounts page now uses one decimal aggregate query. |
| 5 | Add focused service/MCP behavior tests for account defaults, transfer updates and replay/stale validation, inactive-account rejection, and aggregate balances. | ✅ Complete | — | Targeted service tests cover all listed observable behaviors. |
| 6 | Review changes and run PHP lint for modified PHP files, full Pint, then `BookkeepingServiceTest` in the `app` Docker container. | ✅ Complete | — | Manual final review found and corrected inactive-account validation on entry updates; all requested checks pass. |
| 7 | Reject transfer-update requests that contain no editable fields before any transfer, idempotency, or audit write; preserve existing MCP and Filament update behavior for real edits. | ✅ Complete | — | Validation runs before `execute()` begins its transaction; the test confirms unchanged revision and no additional operation/audit records. |
| 8 | Add an isolated test for the forward default-account normalization migration, proving historical `is_default = false` becomes `NULL` while `true` and existing `NULL` values remain intact. | ✅ Complete | — | The test directly calls `up()` only within the forced `mcp_testing` database; no Artisan migration or import was run. |
| 9 | Review the targeted fixes and run PHP lint on changed PHP files, Pint, and the narrow bookkeeping test file through `docker compose exec -T app`. | ✅ Complete | — | PHP lint and full Pint passed; `BookkeepingServiceTest` passed with 29 tests and 81 assertions. |

### Status Legend

- ⬜ Pending — not started
- 🔄 In Progress — sub-agent working on it
- ✅ Complete — implemented and reviewed
- 🔴 Blocked — dependency or issue, needs resolution
- ⏭️ Skipped — not needed, with reason

## Review Log

| Date | Reviewer | Findings | Action Taken |
|------|----------|----------|--------------|
| 2026-10-16 | implementation review | Entry update locked an account but did not reject it when inactive. | Moved the active check into the locked account lookup; reran Pint and targeted tests. |
| 2026-10-16 | final review intake | Transfer update accepts a payload with no editable fields, producing a revision and audit write; the normalization migration lacks direct regression coverage. | Pending human approval for the focused remediation. |
| 2026-10-16 | targeted final review | Reviewed the validation boundary and both regression tests; no Critical or Major findings. | Confirmed the rejection occurs before any idempotency or audit write and that the migration test uses only `mcp_testing`. |

## Notes

- Existing migration `2026_10_16_030009_add_accounts_and_transfers_to_bookkeeping.php` must remain immutable; the correction is a new forward migration.
- The implementation phase is blocked until a human confirms this plan, per repository workflow rules.
- The forward migration test will call only its `up()` method against the isolated `mcp_testing` test database; no Artisan migration, import, or production database operation will run.
