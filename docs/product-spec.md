# Product Specification

## Goal
Replace the SEO team's spreadsheet-based operations and manual monthly report creation with one internal Laravel/Filament system.

The product is not an Ahrefs or Semrush replacement. It is the agency's internal source of truth for:
- Clients
- Projects
- Team assignments
- Monthly targets
- Tasks
- Keywords
- Rankings
- Backlinks
- Content work
- Page optimisation
- Analytics entered/imported from external tools
- Monthly wins/recommendations
- Monthly reports

Core value:
> Enter SEO operational data once during the month, then reuse the same structured data to generate the monthly report.

## Existing workflow
Master Google Sheet
→ client-specific working Sheet
→ team manually records work
→ team manually gathers SEO data
→ team manually prepares monthly report
→ PDF/client report

Target workflow:
Platform
→ Client
→ Project
→ Monthly SEO Cycle
→ operations + analytics
→ report readiness
→ preview
→ final PDF
→ locked month

## Core hierarchy
Client
→ many Projects

Example:
Client: Afzal
- Casa Botanica
- Project B
- Project C

A Project represents one SEO engagement/site.

Monthly work belongs to a MonthlyCycle.

## Global navigation
- Dashboard
- Clients
- Projects
- My Tasks
- Reports
- Team
- Settings

## Project workspace
- Overview
- Monthly Work
- Tasks
- Keywords
- Backlinks
- Content
- Pages
- Analytics
- Reports
- Settings

## Roles
### Super Admin
Full access, including users, packages, settings, finalization, and unlocking locked months.

### SEO Manager
Can manage clients/projects/team assignments, see all projects, review/finalize reports, and view team workload.

### SEO Executive
Can work on assigned Projects only, enter operational data, prepare reports, and mark reports Ready for Review. Cannot finalize or unlock.

## Client
Represents the agency customer/account.

Fields include:
- name
- company
- contact
- email
- phone
- account manager
- status
- notes

SEO metrics do not belong directly to Client.

## Project
Represents one SEO project/site.

Fields include:
- client
- project name
- website URL
- target location
- package
- dates
- status
- primary SEO owner
- additional team members
- notes

Statuses:
- onboarding
- active
- paused
- completed
- cancelled

Only active Projects automatically receive new monthly cycles.

## Packages and targets
Packages contain configurable monthly target defaults.

Initial target examples:
- backlinks
- blogs
- guest_posts
- pages_optimized

Projects may override Package defaults.

When a MonthlyCycle is created, resolved target values are snapshotted.

Historical target snapshots never change later.

## Monthly SEO Cycle
Represents Project + year + month.

Example:
Casa Botanica — September 2026

Statuses:
- open
- reporting
- locked

MonthlyCycle anchors:
- targets
- tasks
- backlinks
- content
- page optimisations
- ranking snapshots
- analytics
- monthly commentary
- report

## Tasks
Operational work items.

Statuses:
- pending
- in_progress
- blocked
- completed
- cancelled

Priorities:
- low
- normal
- high
- urgent

Task completion does not count directly toward deliverables.

## Task Templates
Reusable onboarding checklists.

Examples:
- GSC setup
- GA4 setup
- sitemap
- technical audit
- keyword research
- competitor research
- metadata
- internal linking
- content audit

## Keywords and rankings
Keywords belong to Projects.

Track:
- keyword
- target page
- volume
- KD
- intent
- location
- branded/non-branded
- status

Rankings are snapshots, not spreadsheet date columns.

Do not store redundant movement/previous-position values.

## Backlinks
Track:
- monthly cycle
- published date
- published URL
- anchor
- target URL
- type
- status
- DA
- DR
- spam score
- notes

Types:
- guest_post
- citation
- profile
- forum
- blog_comment
- directory
- outreach
- other

Statuses:
- planned
- submitted
- live
- rejected
- removed

Only live Backlinks count toward target.
Live Guest Posts count toward both Backlinks and Guest Posts.

## Content
Track SEO content operations, not content authoring.

Types:
- blog
- landing_page
- service_page
- location_page
- guest_content
- other

Statuses:
- idea
- planned
- writing
- review
- approved
- published
- cancelled

Only published Blogs count toward Blog target.

## Pages and Page Optimisation
Pages are Project master data.

Page optimisation is historical activity.

Monthly Pages Optimised counts distinct Pages optimized in the cycle.

## Analytics
V1 uses manual entry/import.

GSC:
- clicks
- impressions
- CTR
- average position
- top queries
- top landing pages

GA4:
- active users
- new users
- sessions
- organic sessions
- engaged sessions
- engagement rate
- average engagement time
- event count
- key events
- country data

Authority:
- Moz DA
- linking root domains
- Ahrefs DR
- Ahrefs UR
- backlinks count
- referring domains

Live APIs come later.

## Monthly commentary
Types:
- win
- challenge
- observation
- recommendation
- next_month_focus

Capture these during the month so report preparation is easier.

## Reports
One MonthlyReport per MonthlyCycle.

Lifecycle:
Draft → Ready for Review → Final

Initial section keys:
- executive_summary
- site_authority
- organic_search
- website_traffic
- top_keywords
- landing_pages
- rankings
- audience_country
- backlinks
- recommendations

Projects configure enabled/required/order.

Configuration is snapshotted into monthly reports.

## Report readiness
A report may become Ready for Review only when all enabled + required sections are complete.

Target achievement is separate from report completeness.

## Finalization
Only Manager/Admin finalize.

Finalization:
- verifies readiness
- builds report snapshot
- generates/stores PDF
- records finalizer/time
- marks report Final
- locks MonthlyCycle

## Dashboard philosophy
The product should answer:
> What needs to be completed for this Project this month?

Example:
Casa Botanica — September

Monthly Target Completion:
- Backlinks 32 / 50
- Blogs 6 / 8
- Guest Posts 5 / 8
- Pages Optimised 8 / 8

Report Readiness:
- missing Rankings
- missing Recommendations

Tasks:
- 1 overdue
- 3 due this week

## V1 exclusions
- client portal
- billing
- sales CRM
- crawler
- Ahrefs/Semrush replacement
- custom rank scraping
- AI strategy engine
- chat/comments
- mobile app
- public API
- live external integrations
