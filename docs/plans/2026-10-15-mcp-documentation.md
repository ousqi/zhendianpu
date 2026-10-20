# MCP 功能、工具接口与 ChatGPT 使用文档

> **AI 生成聲明 (AI-Generated Declaration)**：本文件由 AI 依需求分析自動生成，內容尚未經人類審核。僅供參考，需人類 review + confirm 後方可執行。
> **Review Status**: ⚪ Pending
> **Reviewed By**: (人工填寫姓名/日期)

**Human Review**: ⚪ Pending
**Created**: 2026-10-15
**Status**: 🟡 In Progress
**Priority**: High

---

## Background & Goals

目前專案已提供兩個受 OAuth 保護的 Streamable HTTP MCP Server：

- 臻点铺记账：`/mcp/zhendianpu`
- 平台状态：`/mcp/system-status`

但 `README.md` 仍是 Laravel 默认内容，缺少面向使用者的 MCP 能力说明、完整工具接口、权限与 OAuth 连接步骤，以及可直接在 ChatGPT 中使用的真实对话案例。本计划将在不改变既有 MCP 行为、接口或认证配置的前提下，补齐一份中文使用文档。

## Documentation Scope

目标文档应至少覆盖以下内容：

1. **平台概览与边界**：MCP 的用途、两个 Server 的职责分离、北京时间与人民币金额口径，以及不支持的能力（系统命令、文件读取、业务之外的数据访问）。
2. **连接信息**：生产/测试地址的填写方式、OAuth 2.1 授权流程、`mcp:use` scope、授权前提、重连与刷新工具清单的注意事项。实际域名不得凭空填写，应从部署环境或配置确认后再写入。
3. **访问控制与安全**：`auth:api`、`mcp.access:<module>`、限流中间件、读取与写入的角色边界、最小授权原则、Token 不可在聊天中粘贴或共享。
4. **完整工具接口参考**：每个工具的名称、用途、读写/幂等属性、调用前置条件、JSON 参数表、字段类型、必填性、枚举/范围、默认行为、成功结果、常见失败情形和 ChatGPT 调用要点。
5. **会话与记账规则**：先调用上下文工具、只记录已实际发生的收支、日期默认值、分类选择、一次多笔原子写入、`event_id` 幂等重试、更新/撤销前的 `entry_id` 与 `revision` 查询、作废不等于物理删除、期间结余不等于账户余额或利润。
6. **ChatGPT 使用案例**：从连接、状态检查、日常记账、查询、汇总、修正到撤销的端到端中文示例；每个示例均包含用户自然语言、ChatGPT 应调用的工具与关键参数、预期回复和必要的追问/拒绝示例。
7. **故障排除**：未授权、无模块权限、参数校验失败、分类不存在、重复事件、版本冲突、限流、断开后重新授权，以及“工具返回失败时不得宣称已记账”的回复规范。

## Confirmed Existing Interface Inventory

文档实施时以代码为准，当前盘点如下：

| Server | 路径 | 工具 | 说明 |
|---|---|---|---|
| 臻点铺 | `/mcp/zhendianpu` | `get_bookkeeping_context` | 读取北京时间、权限与启用分类；无参数。 |
| 臻点铺 | `/mcp/zhendianpu` | `add_entries` | 原子新增 1–50 笔已发生收支。参数为 `event_id` 与 `entries[]`；单笔含 `direction`、`amount`、`category_id`、可选 `occurred_on`、可选 `description`。 |
| 臻点铺 | `/mcp/zhendianpu` | `list_entries` | 按日期、方向、分类、关键字和作废状态分页查询；可用 `start_date`、`end_date`、`direction`、`category_id`、`keyword`、`include_voided`、`page`、`limit`。 |
| 臻点铺 | `/mcp/zhendianpu` | `get_bookkeeping_summary` | 使用与记录查询相同的筛选字段（不分页），返回收入、支出、期间结余与分类汇总。 |
| 臻点铺 | `/mcp/zhendianpu` | `update_entry` | 以 `event_id`、`entry_id`、`revision` 定位并乐观锁更新；可变更方向、金额、分类、日期、说明。 |
| 臻点铺 | `/mcp/zhendianpu` | `void_entry` | 以 `event_id`、`entry_id`、`revision` 撤销记录，保留历史而不物理删除。 |
| 平台状态 | `/mcp/system-status` | `get_status` | 无参数，只返回服务状态、版本、北京时间与请求编号；不读取记账数据。 |

所有臻点铺接口均要求先获得该模块授权。`get_bookkeeping_context`、`list_entries`、`get_bookkeeping_summary` 和 `get_status` 为只读、幂等调用；写工具也声明为幂等，但仅在重试时复用原 `event_id` 且参数完全一致。

## ChatGPT Case Coverage

文档须提供下列可复制案例，并明确 ChatGPT 何时应调用工具、何时须追问、何时应拒绝记账：

| # | 场景 | 用户示例 | 预期工具行为 |
|---|---|---|---|
| 1 | 首次连接 | “帮我连接臻点铺 MCP” | 填写 MCP 地址、完成 OAuth 授权、确认工具可见。 |
| 2 | 连通性检查 | “系统现在正常吗？” | 调用 `get_status`，只说明平台状态。 |
| 3 | 初始化上下文 | “今天有哪些可用记账分类？” | 调用 `get_bookkeeping_context`，展示启用分类和权限。 |
| 4 | 单笔支出 | “今天买面粉花了 180 元。” | 先取得/复用上下文，匹配支出分类后调用 `add_entries`。 |
| 5 | 单笔收入 | “今天营业收入 1,200 元。” | 匹配收入分类并写入；未实际收到时不得写入。 |
| 6 | 一句话多笔 | “今天买面粉 180、电费 260、营业收入 1200。” | 用同一 `event_id` 调用一次 `add_entries`，原子写入三笔。 |
| 7 | 日期补充 | “昨天付了房租 3000。” | 使用明确日期写入；未提供日期时说明采用北京时间当天。 |
| 8 | 分类歧义 | “买东西花了 200。” | 金额和支出明确但分类无法匹配时，追问或使用已确认的“其他”分类并告知。 |
| 9 | 未发生交易 | “准备买一台冰箱 5000。” | 不调用写入工具，解释报价/计划/未支付欠款不记账。 |
| 10 | 查询流水 | “查一下本月所有电费。” | 用日期范围、分类或关键字调用 `list_entries`，返回记录 ID 与版本。 |
| 11 | 期间汇总 | “本月收入、支出和结余是多少？” | 调用 `get_bookkeeping_summary`，明确结余不是账户余额或利润。 |
| 12 | 分类分析 | “这个月哪类支出最多？” | 获取汇总中的分类金额并说明筛选范围。 |
| 13 | 修改记录 | “把刚才面粉那笔改成 200。” | 先 `list_entries` 获取 `entry_id`/`revision`，再调用 `update_entry`。 |
| 14 | 撤销误记 | “刚才那笔电费记错了，撤销。” | 先查记录及版本，再调用 `void_entry`，说明已撤销而非删除。 |
| 15 | 幂等重试 | “刚才网络断了，那笔有没有记上？” | 使用相同 `event_id` 与完全相同参数安全重试或先查询；不生成新的事件 ID。 |
| 16 | 权限不足 | “为什么我不能记账？” | 展示服务端权限失败，不虚构成功；引导联系管理员授予模块写权限。 |
| 17 | 版本冲突 | “修改失败，提示版本不一致。” | 重新查询最新记录，征得确认后以最新版本更新；不强制覆盖。 |
| 18 | 断开重连 | “ChatGPT 里的工具不见了。” | 检查 MCP 连接、重新授权并刷新工具列表，不暴露 Token。 |

## Tasks

| # | Task | Status | Sub Agent | Notes |
|---|------|--------|-----------|-------|
| 1 | 盘点 MCP 路由、OAuth 配置、权限中间件、Server 指令及工具 schema；确认文档中可公开的连接信息。 | ⬜ Pending | explore | 以当前代码和部署配置为准，不输出凭据。 |
| 2 | 核对 ChatGPT 当前 MCP 连接流程与术语，避免将其他客户端的步骤写成 ChatGPT 操作。 | ⬜ Pending | explore | 使用官方最新文档；明确可用套餐/工作区限制（如有）。 |
| 3 | 编写中文 MCP 使用文档，覆盖范围、认证、权限、安全、端点、完整工具接口和错误处理。 | ⬜ Pending | general | 文档位置、标题及是否替换默认 README 由已确认计划决定。 |
| 4 | 编写完整 ChatGPT 连接与 18 个场景的对话案例，确保参数、前置查询、幂等和并发规则均与代码一致。 | ⬜ Pending | general | 不包含真实 Token、用户数据或未经确认的生产地址。 |
| 5 | 对照代码和官方 ChatGPT 文档审查接口、示例和安全提示；修正 Critical/Major 问题。 | ⬜ Pending | code-reviewer | 重点检查工具 schema、写入确认语义与 OAuth 指引。 |
| 6 | 检查 Markdown 链接、代码块、示例 JSON 和敏感信息，更新计划状态。 | ⬜ Pending | general | 文档变更不运行应用测试或构建。 |

## Review Log

| Date | Reviewer | Findings | Action Taken |
|------|----------|----------|--------------|
| 2026-10-15 | — | Plan created; implementation has not started. | Awaiting human confirmation. |

## Notes

- 这是文档计划，不授权修改 MCP 接口、OAuth 设置、路由、权限或业务数据。
- 实施前须确认最终文档落点：建议新增项目根目录的 `docs/mcp.md`，并从 `README.md` 添加入口；不会删除现有 README 内容。
- ChatGPT 的 MCP 界面、套餐可用性和连接术语可能变化，实施阶段须以 OpenAI 官方最新文档核验。
- 任意示例必须使用占位符，不得包含真实访问令牌、OAuth 客户端密钥、个人账目或生产环境敏感信息。
