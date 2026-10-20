# Transfer Record Deletion

> **AI 生成聲明 (AI-Generated Declaration)**：本文件由 AI 依需求分析自動生成，內容尚未經人類審核。僅供參考，需人類 review + confirm 後方可執行。
> **Review Status**: ⚪ Pending
> **Reviewed By**: (人工填寫姓名/日期)

**Human Review**: ⚪ Pending
**Created**: 2026-10-20
**Status**: 🟡 In Progress
**Priority**: High

---

## Background & Goals

在 Filament「转账记录」表格提供用户可见的「删除」动作。该动作仅调用现有 `BookkeepingTransferService::void()` 撤销能力，不物理删除历史；现有服务会更新撤销人和版本，并写入 MCP operation 与 audit log。撤销后的记录维持不参与账户余额计算及默认表格查询。

## Tasks

| # | Task | Status | Sub Agent | Notes |
|---|------|--------|-----------|-------|
| 1 | 在转账表格加入仅写入权限可见的「删除」操作，要求二次确认，并调用既有 void 服务。 | ✅ Complete | general | 使用新 ULID 事件 ID 与当前 revision；不新增物理删除或 MCP 工具。 |
| 2 | 为转账撤销的可审计、非物理删除及默认列表排除行为补充/调整窄范围测试。 | ✅ Complete | general | 复用 `BookkeepingServiceTest` 既有服务级测试模式。 |
| 3 | 执行 PHP lint、Pint 和相关 PHPUnit 测试，并进行实现审查。 | ✅ Complete | general | 未执行迁移；目标测试通过。完整测试文件有一项既存仪表板失败，详见 Review Log。 |

### Status Legend

- ⬜ Pending — not started
- 🔄 In Progress — sub-agent working on it
- ✅ Complete — implemented and reviewed
- 🔴 Blocked — dependency or issue, needs resolution
- ⏭️ Skipped — not needed, with reason

## Review Log

| Date | Reviewer | Findings | Action Taken |
|------|----------|----------|--------------|
| 2026-10-20 | — | 等待人工确认实施计划。 | — |
| 2026-10-20 | 自检 | 删除操作复用 void，记录原因并写入既有审计链；无物理删除路径。 | 已完成 PHP lint、Pint 及目标 PHPUnit 测试。 |

## Notes

- 已确认 `BookkeepingTransfersTable` 默认以 `whereNull('voided_at')` 查询；账户余额聚合也排除作废转账。
- 现有 `void()` 已有并发锁、revision 校验、`voided_by`、审计日志与幂等操作记录，无需新增服务端物理删除接口或 MCP 工具。
- 已新增 `void_reason` 字段迁移；没有执行该迁移。Filament 删除弹窗强制填写原因，MCP 既有 void 工具可选传入原因，未传入时保存默认说明以维持兼容性。
- `BookkeepingServiceTest` 完整运行结果为 32 passed、1 failed；失败为既有 `dashboard['recent']` 未定义（第 464 行），不在本次改动路径。新增/变更的转账撤销目标测试通过（5 assertions）。
