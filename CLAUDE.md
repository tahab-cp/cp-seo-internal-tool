# Claude Code Instructions — SEO Operations & Reporting Platform

## Project
Internal SEO Operations & Reporting app for an agency SEO team.

Stack:
- PHP / Laravel
- MySQL
- Filament / Livewire
- Tailwind
- Laravel Queues
- Laravel Scheduler
- Blade/HTML reports
- Chromium-based PDF generation later

## Source of truth
Before making changes, read every file under `/docs`.

Do not silently redesign the product. If a request conflicts with `/docs`, explain the conflict before changing architecture.

## Core hierarchy
Agency
→ Clients
→ Projects
→ Monthly SEO Cycles
→ Operational SEO Data
→ Monthly Reports

A Client may have many Projects. SEO operational data belongs to Projects, not directly to Clients.

## Architecture rules
- Laravel is the whole V1 application.
- Filament is the presentation layer.
- Put workflows in `app/Actions`.
- Put calculations/domain operations in `app/Services`.
- Put authorization in `app/Policies`.
- Put background work in `app/Jobs`.
- Put persistence/relationships in `app/Models`.
- Put enums in `app/Enums`.
- Do not place substantial business logic inside Filament resources/pages.
- Prefer explicit Actions over hidden model-observer workflows.
- Use transactions for multi-record workflows.
- Do not install unnecessary packages.

## Roles
- Super Admin
- SEO Manager
- SEO Executive

SEO Executives may only access Projects where they are either the primary SEO owner or a member of the project team.

Authorization must be enforced server-side, not only by hiding UI.

## Monthly cycle rules
`monthly_cycles` is the reporting-period anchor.

Only one cycle may exist per Project/year/month.

Historical monthly targets are snapshots. Package or Project target changes must not modify previous cycles.

Only active Projects automatically receive new monthly cycles.

## Target progress
Progress is derived, never manually stored.

Backlinks:
`status = live`

Guest Posts:
`type = guest_post AND status = live`

Blogs:
`content_type = blog AND status = published`

Pages Optimised:
count distinct optimized `page_id` values for the MonthlyCycle.

Call the aggregate metric `Monthly Target Completion`, not SEO Score or SEO Performance.

## Reports
Lifecycle:
Draft → Ready for Review → Final

SEO Executives may prepare reports and mark complete reports Ready for Review.
SEO Managers and Super Admins may finalize.

Finalization must:
1. verify readiness
2. build a report snapshot
3. generate/store the PDF
4. mark report Final
5. record finalizer/time
6. lock the MonthlyCycle

## Locked cycles
Locked monthly cycles are immutable to normal users.

Only Super Admin may unlock, and a reason is required.

## Historical data
Prefer archive/status/soft delete for important business data.

Do not hard-delete years of reporting history because a Project is archived.

## Database conventions
- BIGINT unsigned IDs
- `{model}_id` foreign keys
- utf8mb4
- UTC timestamps
- Laravel/PHP backed enums stored as strings
- avoid MySQL ENUM
- avoid giant generic JSON blobs for core SEO data
- JSON is acceptable for report snapshots/settings

## Testing
Every implemented business rule needs tests.

A milestone is not complete until:
- relevant tests exist
- full tests pass
- formatter/static checks pass
- authorization is tested where relevant

## Implementation discipline
Implement one milestone at a time.

Before coding:
1. read `CLAUDE.md`
2. read relevant `/docs`
3. inspect existing code
4. state intended changes briefly
5. implement only the requested milestone
6. write/run tests
7. run formatter/static checks
8. report files changed, packages, migrations, tests, and manual steps

Do not implement future milestones unless explicitly requested.

## Out of scope for initial V1
- client portal
- invoicing
- CRM
- website crawler
- Ahrefs/Semrush replacement
- custom rank scraping
- AI SEO strategy engine
- chat/comments
- complex approvals
- mobile app
- public API
- live GSC/GA4 OAuth
- Ahrefs/Semrush APIs
- employee performance scoring
