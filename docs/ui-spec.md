# UI / UX Specification

## Principles
Internal operations software:
- fast
- clear
- table-friendly
- desktop-first
- responsive but not mobile-first
- consistent statuses
- minimal navigation friction

## Global sidebar
- Dashboard
- Clients
- Projects
- My Tasks
- Reports
- Team
- Settings

Project-specific modules must not clutter the global sidebar.

## Project workspace header
Always show:
- Project name
- website
- Client
- Package
- Primary SEO owner
- status

Project navigation:
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

## Month selector
Monthly screens must prominently show month.

Switching month keeps the user in the same module.

Locked month shows:
`🔒 September 2026 — Finalized`

Explain that finalized periods are read-only.

## Executive Dashboard
Purpose:
"What do I need to work on?"

Cards:
- My Active Projects
- Tasks Due This Week
- Overdue Tasks
- Reports Requiring Work

My Project cards/table:
- Project
- Client
- Package
- Monthly Target Completion
- target breakdown
- Report Readiness

Attention area:
- missing report data
- overdue Tasks
- targets behind

## Manager Dashboard
Purpose:
"What is the team's status?"

Cards:
- Active Clients
- Active Projects
- Reports Final
- Reports Ready for Review
- Overdue Tasks

Tables:
- Report Status
- Projects Requiring Attention
- Team Workload

No employee performance score.

## Clients
Index columns:
- Client
- Contact
- Active Projects
- Account Manager
- Status

Search:
- name
- company
- contact

Filters:
- Status
- Account Manager

Actions:
- View
- Edit
- Archive

Client form:
- Client Name *
- Status *
- Company
- Contact
- Email
- Phone
- Account Manager
- Notes

Useful action:
Create & Add Project

Client detail tabs:
- Overview
- Projects
- Notes

## Projects Index
Columns:
- Project
- Client
- Website
- Package
- Primary SEO
- Status
- Current Month completion

Filters:
- Status
- Client
- Package
- Primary SEO

Executive sees only accessible Projects.

## Create Project Wizard
Step 1:
- Client
- Project Name
- Website URL
- Target Location
- Start Date

Step 2:
- Package
- show package targets
- optional custom overrides

Step 3:
- Primary SEO Owner
- Additional Members

Step 4:
- Generate onboarding checklist
- Create current monthly cycle
- summary

## Project Overview
Month selector.

Monthly Target Completion:
- Backlinks actual/target
- Blogs actual/target
- Guest Posts actual/target
- Pages Optimised actual/target
- aggregate completion

Report Readiness:
- completed/required
- percentage
- checklist
- missing items
- Continue Preparing Report

Tasks:
- overdue
- due this week
- completed this month

## Monthly Work
Show:
- month
- target progress
- Wins
- Challenges/Observations
- Recommendations/Next Month Focus

Target cards link to their source module.

## Tasks
Columns:
- Task
- Assignee
- Category
- Due
- Priority
- Status

Views:
- Current Month
- All
- Mine
- Overdue
- Completed

Quick actions:
- Start
- Complete
- Edit

No Kanban required in V1.

## Keywords
Columns:
- Keyword
- Target Page
- Volume
- KD
- Intent
- Branded
- Latest Rank
- Status

Actions:
- Add Keyword
- Import CSV

Filters:
- Status
- Intent
- Branded
- Target Page

Keyword detail:
- keyword metadata
- ranking history
- month start
- latest
- human-readable movement

Bulk ranking entry is required for speed.

## Backlinks
Header:
- selected month
- Live actual/target
- Guest Posts actual/target

Actions:
- Add Backlink
- Import CSV

Columns:
- Date
- Published URL
- Anchor
- Target
- Type
- DA
- DR
- Spam
- Status

Filters:
- Month
- Type
- Status
- DA/DR ranges

Allow All Time view.

## Content
Header:
- selected month
- Blogs Published actual/target

Columns:
- Title
- Type
- Target Keyword
- Assignee
- Planned
- Published
- Status

Track workflow only; do not build content authoring.

## Pages
Columns:
- Page
- URL
- Type
- Primary Keyword
- Last Optimised

Page detail:
- master info
- target keyword(s)
- optimisation history
- Record Optimisation action

Optimization form:
- Reporting Month
- Optimised At
- Meta title
- Meta description
- Content
- Internal links
- Schema
- Notes

## Analytics
Month selector.

Data-source indicators.

GSC:
- Clicks
- Impressions
- CTR
- Average Position
- Top Queries
- Landing Pages

GA4:
- Active Users
- New Users
- Sessions
- Organic Sessions
- Engaged Sessions
- Engagement Rate
- Average Engagement Time
- Event Count
- Key Events
- Country data

Authority:
- Moz DA
- linking root domains
- Ahrefs DR
- Ahrefs UR
- total Backlinks
- referring domains
- notes

V1 manual entry/import.

## Reports
Project report list columns:
- Period
- Status
- Readiness
- Finalized By
- Finalized Date

Actions:
- Open
- Preview
- Download PDF when available

## Report Editor
Custom workflow page.

Header:
- Project
- period
- status
- readiness

Sections:
- Executive Summary
- Site Authority
- Organic Search
- Website Traffic
- Top Keywords
- Landing Pages
- Rankings
- Audience
- Backlinks
- Recommendations

Each shows Complete or Missing.

Operational metrics are read from source tables, not duplicated.

## Report Preview
Render HTML closely matching final PDF.

Workflow:
Edit → Preview → Correct → Preview → Finalize

## Finalize confirmation
Explain:
- final PDF will be generated
- month will lock
- normal users will no longer edit monthly data

## Global Reports
Manager-centric month-end control center.

Columns:
- Project
- Client
- Owner
- Readiness
- Status
- Action

Filters:
- Month
- Status
- Owner
- Client

## My Tasks
Filters:
- Due Today
- This Week
- Overdue
- All Open
- Completed

Columns:
- Project
- Task
- Due
- Priority
- Status

## Team
Managers/Admin only.

Columns:
- Team Member
- Active Projects
- Open Tasks
- Overdue
- Reports Ready

Workload/status only.

## Settings
### Packages
Manage Package + target repeater.

### Task Templates
Manage ordered onboarding templates.

### Users
Name, email, role, active/deactivated.

### Report Branding
Agency name, logo, contact email, phone, website, address.

### Project Settings
- General
- Team
- Package & Targets
- Report Sections
- Lifecycle

Warnings:
Package/target changes affect future cycles only.
Report-section changes affect future reports only.

## Empty states
Teach the workflow.

Examples:

Backlinks:
"No backlinks recorded for September."
[Add Backlink]

Rankings:
"No ranking snapshots yet."
[Update Rankings]

Analytics:
"No GSC data for September."
[Enter Manually] [Import CSV]

Monthly Notes:
"No wins recorded yet. Capture notable results during the month so reporting is easier later."
[Add Win]

## Modals/drawers
Good for:
- Add Backlink
- Add Task
- Add Win
- Add Recommendation
- Record Page Optimisation
- Add Content

Full pages for:
- Create Project wizard
- Project settings
- Report Editor
- complex Analytics

## CSV import UX
Upload
→ Map Columns
→ Preview
→ Validate
→ Import

Show invalid rows. Do not silently skip.

## Global search
At minimum:
- Clients
- Projects

## Dangerous actions
Confirm:
- Archive Project
- Finalize Report
- Unlock Month
- Deactivate User

Routine actions should remain fast.
