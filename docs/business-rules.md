# Business Rules

## Core
1. A Client may have many Projects.
2. A Project belongs to exactly one Client.
3. SEO operational data belongs to a Project.
4. Monthly data is associated with a MonthlyCycle.
5. Only one MonthlyCycle may exist per Project/year/month.
6. Only one MonthlyReport may exist per MonthlyCycle.

## Visibility
Super Admin and SEO Manager may access all Projects.

SEO Executive may access only Projects where:
- they are `primary_seo_user_id`, or
- they exist in `project_user`.

Enforce server-side.

## Client management
Only Super Admin and SEO Manager may create/edit/archive Clients.

## Project management
Only Super Admin and SEO Manager may:
- create Projects
- change Client
- change Package
- assign team
- change Project lifecycle

## Project creation workflow
Create Project
→ attach team
→ assign Package
→ resolve target overrides
→ optionally generate onboarding Tasks
→ create current MonthlyCycle
→ snapshot targets
→ initialize report configuration/report state

Use explicit Action(s) and transaction(s).

## Project statuses
- onboarding
- active
- paused
- completed
- cancelled

Only active Projects automatically receive future monthly cycles.

## Package targets
Project override wins over Package default.

## Monthly target snapshots
At cycle creation:
1. resolve current targets
2. copy into `monthly_cycle_targets`

Never dynamically recalculate historical targets from current Package settings.

## Monthly cycle creation
Automated for active Projects at month start.

Must be idempotent.

Database uniqueness protects duplicates.

## Tasks
Tasks are workflow, not deliverable counters.

Completing a Task must not increment Backlinks/Blogs/etc.

## Backlinks
Count toward Backlinks target only when:
`status = live`

Count toward Guest Posts target only when:
`type = guest_post AND status = live`

Live Guest Post counts toward both targets.

Removed Backlinks do not count.

## Content
Count toward Blogs target only when:
`content_type = blog AND status = published`

## Pages Optimised
Count distinct optimized `page_id` values in the MonthlyCycle.

Multiple optimizations on the same Page in one month count once for target progress.

## Rankings
Rankings are snapshots.

Do not store previous position or movement.

Movement:
24 → 8 = improved 16
8 → 14 = declined 6

NULL = not ranking.

## Monthly Target Completion
Derived, not stored.

For aggregate completion:
- calculate percentage per configured target
- cap each target's aggregate contribution at 100%
- average the configured target percentages

Call it `Monthly Target Completion`.

Do not call it SEO Performance/Score.

## Analytics
V1 sources:
- manual
- csv_import

Future sources may include:
- gsc_api
- ga4_api
- ahrefs_api
- semrush_api
- dataforseo

## Monthly Notes
Types:
- win
- challenge
- observation
- recommendation
- next_month_focus

Locked with the MonthlyCycle.

## Report configuration
Projects define sections:
- enabled
- required
- order

Snapshot into monthly report sections.

Project config changes never alter historical reports.

## Initial readiness rules
Executive Summary:
non-empty summary

Site Authority:
AuthorityMetrics exists

Organic Search:
GscMonthlyMetrics exists

Website Traffic:
Ga4MonthlyMetrics exists

Top Keywords:
at least one GscQueryMetric

Landing Pages:
at least one GscPageMetric

Rankings:
configured ranking requirement is satisfied

Backlinks:
section can render from MonthlyCycle data

Recommendations:
at least one recommendation or next_month_focus note

Optional sections do not block readiness.

Target achievement does not determine report completeness.

## Report lifecycle
Draft
→ Ready for Review
→ Final

SEO Executive may move a complete report to Ready for Review.

Only SEO Manager/Super Admin may finalize.

## Finalization
Finalization must:
1. re-check readiness
2. build snapshot
3. generate/store PDF
4. mark Final
5. set finalized_at/finalized_by
6. lock MonthlyCycle

If generation fails, do not leave falsely finalized/locked state.

## Locked cycle
When locked, normal users cannot edit:
- monthly Tasks
- Backlinks
- ContentItems
- PageOptimizations
- RankingSnapshots
- GSC
- GA4
- Authority
- MonthlyNotes
- Report content

Protection must apply to forms, direct requests, imports, bulk actions, and custom actions.

## Unlocking
Only Super Admin.

Reason required.

Unlock should:
- return cycle to reporting/editable
- return report to draft/reviewable
- audit the reason
- preserve previous report file/history where practical

## Deletion
Prefer archive/status/soft delete.

Do not hard-delete historical Project/reporting data.

Deactivate Users with history instead of deleting.

## Business logic placement
Actions:
- CreateProjectAction
- CreateMonthlyCycleAction
- EnsureMonthlyCycleAction
- GenerateOnboardingTasksAction
- MarkReportReadyAction
- FinalizeMonthlyReportAction
- UnlockMonthlyCycleAction

Services:
- TargetResolver
- TargetProgressService
- ReportReadinessService
- ReportSnapshotBuilder
- PdfReportGenerator

Filament should call these; it should not own the domain logic.

## Audit
At minimum log:
- Project created
- Project reassigned
- Package changed
- target override changed
- report marked Ready
- report finalized
- cycle unlocked
- analytics corrected after unlock

## Critical tests
- Client can have multiple Projects
- Executive cannot access unrelated Project
- Manager can access all Projects
- only one MonthlyCycle per Project/month
- target snapshots remain historical
- Project override beats Package default
- live Backlink counts
- non-live Backlink does not
- live Guest Post counts toward two targets
- only published Blog counts
- distinct optimized Pages count once
- ranking movement calculation
- incomplete report cannot become Ready
- Executive cannot finalize
- Manager can finalize
- finalization locks cycle
- locked data cannot be edited
- Admin can unlock with reason
- final snapshot remains stable
