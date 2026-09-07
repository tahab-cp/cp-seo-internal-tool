# Implementation Roadmap

## Rule
Implement one milestone at a time.

For each milestone:
1. read `CLAUDE.md`
2. read relevant `/docs`
3. inspect current repository
4. state intended changes
5. implement only requested milestone
6. write tests
7. run full tests
8. run formatter/static checks
9. report files/packages/migrations/tests/manual steps

## Milestone 0 — Foundation
Implement:
- Laravel
- MySQL
- Filament
- authentication
- queues
- scheduler readiness
- testing
- directories:
  - app/Actions
  - app/Enums
  - app/Jobs
  - app/Policies
  - app/Services
  - app/Support

Tests:
- app boots
- unauthenticated user blocked from Filament
- authenticated user can access Filament

Do not create SEO domain models.

## Milestone 1 — Users, Roles & Permissions
Roles:
- Super Admin
- SEO Manager
- SEO Executive

Implement central authorization and user activation.

Tests:
- Admin manages users
- Manager cannot manage system roles
- Executive cannot access user management
- inactive user blocked

## Milestone 2 — Clients
Create:
- clients table/model
- ClientResource

Authorization:
Admin/Manager create/edit/archive.

Tests:
- Manager creates
- Executive cannot
- archive works
- validation

## Milestone 3 — Projects & Team Assignment
Create:
- projects
- project_user

Implement Client → many Projects.

Project access:
Executive only assigned Projects.

Tests:
- multi-project Client
- assigned access
- unrelated access denied
- Manager sees all
- additional member access

## Milestone 4 — Packages, Targets & Overrides
Create:
- packages
- package_targets
- project_target_overrides
- TargetResolver

Tests:
- overrides win
- defaults work

## Milestone 5 — Monthly Cycles & Target Snapshots
Create:
- monthly_cycles
- monthly_cycle_targets
- CreateMonthlyCycleAction
- EnsureMonthlyCycleAction
- monthly scheduler

Tests:
- uniqueness
- snapshots
- overrides
- historical stability
- idempotency
- active vs paused automation

## Milestone 6 — Task Templates, Onboarding & Tasks
Create:
- task_templates
- task_template_items
- tasks
- GenerateOnboardingTasksAction

UI:
- Settings templates
- Project Tasks
- My Tasks

Tests:
- template generation
- Project/cycle assignment
- access control
- completed_at

## Milestone 7 — Pages & Page Optimisation
Create:
- pages
- page_optimizations

Implement Pages Optimised progress using distinct Page count.

Tests:
- duplicate URL protection
- correct relationships
- same Page twice counts once

## Milestone 8 — Keywords & Rankings
Create:
- keywords
- ranking_snapshots
- bulk ranking entry

Tests:
- relationships
- movement calculations
- null ranking

## Milestone 9 — Backlinks
Create:
- backlinks
- Backlinks UI
- target progress integration

Rules:
- only live Backlinks count
- live Guest Post counts in both relevant targets

Tests:
all statuses and Guest Post behavior

## Milestone 10 — Content
Create:
- content_items
- Content UI
- Blog progress integration

Rule:
only published Blogs count.

Tests:
status/type behavior.

## Milestone 11 — Manual Analytics
Create:
- gsc_monthly_metrics
- gsc_query_metrics
- gsc_page_metrics
- ga4_monthly_metrics
- ga4_country_metrics
- authority_metrics

UI:
Project Analytics.

Manual/import only.

Tests:
one-to-one summary uniqueness and Project isolation.

## Milestone 12 — Monthly Notes & Report Configuration
Create:
- monthly_notes
- project_report_sections
- monthly_reports
- monthly_report_sections
- ReportReadinessService

Tests:
- config snapshot
- historical stability
- required/optional readiness

## Milestone 13 — Report Editor, Preview & PDF
Create:
- custom Report Editor
- HTML preview
- ReportSnapshotBuilder
- PdfReportGenerator
- FinalizeMonthlyReportAction

Lifecycle:
Draft → Ready for Review → Final

Tests:
- readiness gating
- Executive cannot finalize
- Manager can
- snapshot stored
- PDF path stored
- cycle locks

This is the first V1 launch candidate.

## Milestone 14 — Locking, Unlocking & Audit
Create:
- centralized locked-cycle protection
- UnlockMonthlyCycleAction
- audit logging

Admin-only unlock with reason.

Test mutation prevention across all monthly entities.

## Milestone 15 — Dashboards & Management Views
Executive:
- My Projects
- Tasks Due
- Overdue
- Reports needing work
- Monthly Target Completion

Manager:
- Clients/Projects
- report statuses
- overdue Tasks
- Projects behind target
- team workload

No performance scoring.

## Milestone 16 — CSV Imports
Prioritize:
- Keywords
- Rankings
- Backlinks
- GSC Queries
- GSC Landing Pages
- GA4 Countries

Workflow:
Upload → Map → Preview → Validate → Import

Queue large imports.

## Milestone 17 — Existing Spreadsheet Migration
Migrate current office spreadsheet data into the stable model.

Do not distort new architecture to mimic spreadsheet quirks.

## Milestone 18 — Production Hardening
Review:
- auth
- indexes
- backups/restore
- queue worker
- scheduler
- file/PDF storage
- logging
- HTTPS
- deployment
- realistic data volumes

## Git strategy
Use `main` plus short-lived feature branches.

Examples:
- feature/clients
- feature/projects
- feature/monthly-cycles
- feature/backlinks
- feature/reporting

Keep commits small.

## Milestone 0 prompt for Claude Code
Read CLAUDE.md and every file inside /docs before making changes.

We are implementing Milestone 0 only: Project Foundation.

First inspect the repository and tell me briefly what you intend to change.

Then:
- verify Laravel configuration
- configure MySQL through environment variables
- install/configure Filament if needed
- configure Filament authentication
- create missing architecture directories
- configure database queues for development
- ensure scheduler is ready
- verify testing
- add tests proving:
  1. app boots
  2. unauthenticated user cannot access Filament
  3. authenticated user can access Filament
- run migrations
- run full tests
- run formatter

Do NOT create SEO business models/tables yet.

Do not create:
Client, Project, Package, MonthlyCycle, Task, Backlink, Keyword, Ranking, Content, Page, Analytics, Report.

Do not install unnecessary dependencies.

Do not redesign `/docs`.

After implementation report:
- files created
- files modified
- packages installed
- migrations run
- test results
- manual steps
