# Bookkeeping Overview Chart Review Fixes

> **AI 生成聲明 (AI-Generated Declaration)**：本文件由 AI 依需求分析自動生成，內容尚未經人類審核。僅供參考，需人類 review + confirm 後方可執行。
> **Review Status**: ⚪ Pending
> **Reviewed By**: (人工填寫姓名/日期)

**Human Review**: ⚪ Pending
**Created**: 2026-10-16
**Status**: 🟡 In Progress
**Priority**: High

---

## Background & Goals

Resolve the overview chart review findings without changing or running database migrations. The scope is Filament 5 chart compatibility, bounded date filtering and aggregation, decimal-safe account balances, category label limits with matching assistive output, accessible range controls, and regression coverage.

## Tasks

| # | Task | Status | Sub Agent | Notes |
|---|------|--------|-----------|-------|
| 1 | Update chart widgets for Filament 5 option signatures and disable polling. | ✅ Complete | general | `getOptions()` matches `array | RawJs | null`. |
| 2 | Enforce a bounded, non-reversed overview date range with Livewire validation feedback; keep trend labels within a safe limit using day/week/month buckets. | ✅ Complete | general | 1096-day maximum; 31 daily, 365 weekly, then monthly; max 53 labels. |
| 3 | Remove the `DECIMAL(14,2)` account-balance aggregate cap while preserving exact decimal-string output. | ✅ Complete | general | Uses `DECIMAL(30,2)` with decimal-string presentation. |
| 4 | Limit category charts to Top-N plus an `其他` bucket and render matching linked/non-linked assistive detail. | ✅ Complete | general | Top 7 plus `其他`; assistive content reads the same chart data. |
| 5 | Add active-state ARIA semantics and theme-aware chart colors following existing Filament/Tailwind conventions. | ✅ Complete | general | `aria-pressed`, Tailwind dark variants, and semantic Filament widget colors. |
| 6 | Add focused widget/page render, date validation/range, Top-N, and high-precision account-balance tests. Run PHP lint, Pint, Blade compilation, and focused tests. | ✅ Complete | general | 36 focused tests passed; no migration run. |
| 7 | Review implementation against this plan and resolve Critical/Major findings. | ⏭️ Skipped | — | No sub-agent tool is available; completed a direct final review and focused regression run. |
| 8 | Preserve the full overview layout for an empty selected range, with explicit chart placeholders, accurate zero totals, and safe record/account setup entry points. | ✅ Complete | main agent | No migrations or data writes; zero-balance accounts remain visible. PHP lint, Pint, Blade cache, and focused page/widget tests passed. |
| 9 | Restore the Filament Vite theme, remove legacy management CSS from the panel, and make the overview chart-first, responsive, and dark-mode aware without changing empty-range data behavior. | 🔴 Blocked | main agent | Theme, layout, and regression acceptance passed. `npm run build` is blocked before compilation because the existing Bunny font plugin cannot establish TLS; retry when font host connectivity is restored. |

### Status Legend

- ⬜ Pending — not started
- 🔄 In Progress — sub-agent working on it
- ✅ Complete — implemented and reviewed
- 🔴 Blocked — dependency or issue, needs resolution
- ⏭️ Skipped — not needed, with reason

## Review Log

| Date | Reviewer | Findings | Action Taken |
|------|----------|----------|--------------|
| 2026-10-16 | Main agent | No Critical or Major findings in direct final review. | Verified via PHP lint, Pint, Blade cache, and focused tests. |
| 2026-10-16 | Main agent | Filament panel was not compiling a Vite theme and inherited global legacy CSS. | Added the Filament 5/Tailwind 4 theme entry and removed the panel render hook; focused tests and Blade cache pass. Production build remains blocked by Bunny Fonts TLS connectivity. |

## Notes

- Filament installed version is 5.10.2; its `ChartWidget::getOptions()` signature is `array | RawJs | null`.
- The current balance projection casts to `DECIMAL(14,2)`, which can truncate sums beyond the source column's range.
- The selected date range currently has no maximum and the overview page invokes the query service directly without Livewire validation feedback.
- The empty-range update distinguishes absent income/expense records from accounts that legitimately have a zero balance, and does not supply example values to chart datasets.
