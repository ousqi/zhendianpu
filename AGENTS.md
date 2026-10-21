<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## 项目开发说明

### 技术栈与运行环境

- 本项目使用 PHP 8.4 和 Laravel 13 开发。
- 项目位于 WSL 中，通过 Docker Compose 运行开发环境。
- PHP 应用服务名为 `app`；容器内项目目录为 `/var/www/html/develop_mcp`。PHP、Composer 和 Artisan 命令均在该容器中执行，避免使用 Windows 或 WSL 宿主机上的 PHP 环境。
- 在 WSL 的项目根目录（Compose 配置文件所在目录）执行命令。执行前先检查实际 Compose 配置及服务状态。

### 常用命令

在已运行的服务中执行项目命令的完整格式为 `docker compose exec -T -w /var/www/html/develop_mcp app <命令>`，不能省略 `exec`、`-w /var/www/html/develop_mcp` 或 `app`。`-w` 确保 Artisan、Composer 和测试命令在 Laravel 项目目录内运行，避免因容器默认工作目录不同而失败。

```bash
# 查看服务状态
docker compose ps

# 启动应用服务及其依赖
docker compose up -d app

# 查看 PHP 版本
docker compose exec -T -w /var/www/html/develop_mcp app php -v

# 安装 Composer 依赖
docker compose exec -T -w /var/www/html/develop_mcp app composer install

# 执行 Laravel Artisan 命令
docker compose exec -T -w /var/www/html/develop_mcp app php artisan about

# 运行测试
docker compose exec -T -w /var/www/html/develop_mcp app php artisan test

# 检查 PHP 文件语法（替换为实际文件路径）
docker compose exec -T -w /var/www/html/develop_mcp app php -l path/to/file.php
```

自动化或非交互式执行时，使用完整的 `docker compose exec -T -w /var/www/html/develop_mcp app <命令>` 格式禁用 TTY，例如：

```bash
docker compose exec -T -w /var/www/html/develop_mcp app php artisan test
docker compose exec -T -w /var/www/html/develop_mcp app composer install --no-interaction
```

从 Windows 发起命令时，应通过 WSL 进入 Linux 项目目录，再执行 Docker Compose 命令。不要在 Windows 的 UNC 路径下直接执行 PHP 或 Composer。

### 开发约定

- 修改前阅读相关代码、`composer.json` 和 Compose 配置，遵循项目现有目录结构、编码风格及 Laravel 约定。
- 提交的业务代码注释使用中文；仅为复杂业务逻辑添加必要注释。
- 保持代码兼容 PHP 8.4 和 Laravel 13，不擅自升级或降级技术栈。
- 安装依赖使用 `composer install`；只有任务涉及依赖更新时才使用 `composer update`，并检查 `composer.lock` 的变更。
- 不手动修改 `vendor/` 或其他生成文件，不提交 `.env`、密钥或凭据。
- 修改应用逻辑后，在 `app` 容器内运行与变更相关的测试；若环境无法启动或检查失败，应明确说明原因及未完成的验证。
- 数据库迁移、队列等 Artisan 命令同样通过 `docker compose exec -T -w /var/www/html/develop_mcp app php artisan ...` 执行。运行前确认目标环境，不执行与任务无关的数据清空或重建操作。

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== agent-orchestration/workflow rules ===

# Agent Orchestration & Workflow

## Role Separation

- The main agent is an orchestrator only: it must not write code directly.
- Before implementation, the main agent must create a task plan.
- The main agent delegates all code changes through the `task` tool, maintains progress loops, resolves blockers, and coordinates sub-agent outputs.
- Sub-agents are executors responsible for implementation, testing, and reviews.
- Use agent roles as follows:
  - `explore`: codebase analysis, file searches, architecture questions, and existing-pattern discovery.
  - `code-reviewer`: code review, security checks, best-practice verification, and Laravel convention compliance.
  - `general`: implementation, test writing, file modifications, and multi-step execution.
  - `requirement-clarity`: requirement clarification before implementation.

## Mandatory Workflow

For every task that changes source code, follow this sequence:

1. **PLAN** — Create or update a task plan using `todowrite` or a plan document.
2. **WAIT** — Stop and wait for explicit human reviewer approval of the plan.
3. **DELEGATE** — After approval only, spawn sub-agent(s) to implement the approved work.
4. **REVIEW** — Spawn a sub-agent to review the implementation against the plan.
5. **UPDATE** — Update the plan or todo list with current task status.
6. **LOOP** — Check progress and continue delegating work until the project goal is complete.

### Mandatory Human Approval Gate

- After creating a plan document, immediately stop and explicitly ask a human reviewer to review and confirm it.
- Do not delegate implementation, modify source code, or otherwise enter the implementation phase until the human explicitly confirms (for example: 「確認」, 「可以開始」, or `approved`).
- If the reviewer requests changes, update the plan and request confirmation again before continuing.
- This gate applies to every source-code change, regardless of size.

## Plan Documents

For multi-task work, create plan documents in the project-root `docs/plans/` directory. Name each file `YYYY-MM-DD-<title>.md` (for example, `docs/plans/2026-10-15-user-import.md`) and use this structure:

```md
# <Task Title>

> **AI 生成聲明 (AI-Generated Declaration)**：本文件由 AI 依需求分析自動生成，內容尚未經人類審核。僅供參考，需人類 review + confirm 後方可執行。
> **Review Status**: ⚪ Pending | 🔄 In Review | ✅ Confirmed by Human
> **Reviewed By**: (人工填寫姓名/日期)

**Human Review**: ⚪ Pending
**Created**: YYYY-MM-DD
**Status**: 🟡 In Progress
**Priority**: High | Medium | Low

---

## Background & Goals

Brief description of why this task exists and what it aims to achieve.

## Tasks

| # | Task | Status | Sub Agent | Notes |
|---|------|--------|-----------|-------|
| 1 | Task description | ⬜ Pending | — | |
| 2 | Task description | 🔄 In Progress | general | |
| 3 | Task description | ✅ Complete | general | Reviewed |

### Status Legend

- ⬜ Pending — not started
- 🔄 In Progress — sub-agent working on it
- ✅ Complete — implemented and reviewed
- 🔴 Blocked — dependency or issue, needs resolution
- ⏭️ Skipped — not needed, with reason

## Review Log

| Date | Reviewer | Findings | Action Taken |
|------|----------|----------|--------------|
| YYYY-MM-DD | code-reviewer | Description | Fix applied |

## Notes

Any additional context, decisions, or blockers.
```

- Every AI-created plan, design, or planning document under `docs/` or elsewhere must include the AI-generated declaration shown above directly under its title.
- Before human approval, `Review Status` must remain pending or in review, `**Human Review**` must be pending, and the plan status must not be complete.
- Only a human may mark `Review Status` as `✅ Confirmed by Human` and complete the `Reviewed By` field.
- When editing an existing plan document, add the declaration if it is missing.

## Sub-Agent Delegation

- Provide full context in every delegation prompt: relevant paths, expected behavior, and applicable `AGENTS.md` constraints.
- Assign one sub-agent per logical task; do not batch unrelated changes.
- Run independent tasks in parallel and dependent tasks sequentially.
- Use `explore` before `general` when unfamiliar code must be understood first.
- After each implementation task, use `code-reviewer` for review.

## Main-Agent Progress Loop

- Break multi-task or long-running work into discrete tasks using `todowrite` or a plan document.
- Execute tasks individually or in independent parallel batches.
- After every batch, verify results and update task status.
- If a sub-agent fails or produces incorrect work, diagnose the issue, adjust the prompt, and re-delegate.
- Continue until every task is complete or explicitly skipped.
- After each major milestone, delegate a holistic review to `code-reviewer`.
- If work cannot proceed, mark it as blocked and state the reason in the plan.

## Mandatory Review Flow

Every code change must be reviewed before its task is complete:

1. After `general` completes implementation, delegate an implementation review to `code-reviewer`.
2. The reviewer must check AGENTS.md compliance, naming and patterns, service/repository use where applicable, error handling and logging, Chinese comments for business logic, unnecessary abstractions, and relevant test coverage.
3. The reviewer must classify findings as Critical, Major, or Minor.
4. For Critical or Major findings, delegate fixes to `general`, then repeat review.
5. Mark a task complete only after the reviewer reports no Critical or Major findings.

## Self-Verification Before Completion

Before declaring a task complete, verify:

- Run `php -l` on all modified PHP files.
- Run `php vendor/bin/phpunit` for affected test files.
- Check applicable conventions and the project self-check checklist.
- Confirm new code follows the existing service-layer and repository patterns where used by the application.
- Confirm affected existing tests pass without regressions.

If verification fails, diagnose the problem, delegate an in-scope fix, and verify again before completion.

## Forbidden Main-Agent Actions

The main agent must not:

- Write or edit source-code files directly; use sub-agents.
- Skip the plan-document step for multi-task projects.
- Skip explicit human confirmation before implementation.
- Skip implementation review.
- Mark tasks complete without sub-agent review confirmation.
- Abandon a task loop without marking it blocked and documenting the reason.
- Ignore self-check checklist items without justification.

</laravel-boost-guidelines>
