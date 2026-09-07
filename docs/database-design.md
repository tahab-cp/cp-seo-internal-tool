# Database Design

## Principles
Relational database centered on:

Client
→ Project
→ MonthlyCycle
→ Monthly Operational Data
→ MonthlyReport

Separate master data from monthly activity data.

Avoid generic key/value or giant JSON structures for core SEO records.

## Tables

### users
- id
- name
- email
- password
- is_active
- email_verified_at
- timestamps

Roles/permissions handled separately.

### clients
- id
- name
- company_name nullable
- contact_name nullable
- email nullable
- phone nullable
- status
- account_manager_id nullable
- notes nullable
- timestamps
- soft deletes

Relationships:
- belongsTo account manager
- hasMany Projects

### projects
- id
- client_id
- package_id nullable
- name
- website_url
- target_location nullable
- status
- start_date nullable
- end_date nullable
- primary_seo_user_id nullable
- notes nullable
- timestamps
- soft deletes

Relationships:
- belongsTo Client
- belongsTo Package
- belongsTo primary SEO User
- belongsToMany Users
- hasMany MonthlyCycles
- hasMany Pages
- hasMany Keywords

### project_user
- id
- project_id
- user_id
- project_role nullable
- timestamps

UNIQUE(project_id, user_id)

### packages
- id
- name
- description nullable
- is_active
- timestamps

### package_targets
- id
- package_id
- target_key
- label
- target_value
- sort_order
- timestamps

UNIQUE(package_id, target_key)

Initial keys:
- backlinks
- blogs
- guest_posts
- pages_optimized

### project_target_overrides
- id
- project_id
- target_key
- label
- target_value
- timestamps

UNIQUE(project_id, target_key)

### monthly_cycles
- id
- project_id
- year
- month
- status
- started_at nullable
- locked_at nullable
- locked_by nullable
- timestamps

UNIQUE(project_id, year, month)

Statuses:
- open
- reporting
- locked

### monthly_cycle_targets
- id
- monthly_cycle_id
- target_key
- label
- target_value
- timestamps

UNIQUE(monthly_cycle_id, target_key)

These are snapshots.

### task_templates
- id
- name
- description nullable
- is_active
- timestamps

### task_template_items
- id
- task_template_id
- title
- description nullable
- category nullable
- phase nullable
- default_due_days nullable
- sort_order
- timestamps

### tasks
- id
- project_id
- monthly_cycle_id nullable
- task_template_item_id nullable
- assigned_user_id nullable
- created_by
- title
- description nullable
- category nullable
- status
- priority
- due_date nullable
- completed_at nullable
- timestamps
- soft deletes

### pages
- id
- project_id
- url
- path nullable
- title nullable
- page_type nullable
- status
- timestamps
- soft deletes

UNIQUE(project_id, url)

### page_optimizations
- id
- project_id
- page_id
- monthly_cycle_id
- user_id nullable
- optimized_at
- meta_title_updated boolean
- meta_description_updated boolean
- content_updated boolean
- internal_links_updated boolean
- schema_updated boolean
- notes nullable
- timestamps

### keywords
- id
- project_id
- keyword
- target_page_id nullable
- keyword_role nullable
- search_volume nullable
- keyword_difficulty nullable
- search_intent nullable
- location nullable
- is_branded
- status
- timestamps
- soft deletes

Statuses:
- active
- paused
- archived

Intents:
- informational
- navigational
- commercial
- transactional
- local
- unknown

### ranking_snapshots
- id
- keyword_id
- monthly_cycle_id
- checked_at
- position nullable
- ranking_url nullable
- source
- created_at

Do not store movement/previous_position.

Suggested:
UNIQUE(keyword_id, checked_at, source)

### backlinks
- id
- project_id
- monthly_cycle_id
- created_by
- published_date nullable
- published_url
- anchor_text nullable
- target_url nullable
- type
- status
- domain_authority nullable
- domain_rating nullable
- spam_score nullable
- notes nullable
- timestamps
- soft deletes

### content_items
- id
- project_id
- monthly_cycle_id nullable
- assigned_user_id nullable
- target_keyword_id nullable
- title
- content_type
- status
- planned_publish_date nullable
- published_at nullable
- published_url nullable
- notes nullable
- timestamps
- soft deletes

### gsc_monthly_metrics
- id
- monthly_cycle_id
- clicks
- impressions
- ctr
- average_position
- source
- synced_at nullable
- entered_by nullable
- timestamps

UNIQUE(monthly_cycle_id)

### gsc_query_metrics
- id
- monthly_cycle_id
- query
- clicks
- impressions
- ctr
- average_position
- timestamps

### gsc_page_metrics
- id
- monthly_cycle_id
- page_id nullable
- page_url
- clicks
- impressions
- ctr
- average_position
- timestamps

### ga4_monthly_metrics
- id
- monthly_cycle_id
- active_users nullable
- new_users nullable
- sessions nullable
- organic_sessions nullable
- engaged_sessions nullable
- engagement_rate nullable
- average_engagement_time_seconds nullable
- event_count nullable
- key_events nullable
- source
- synced_at nullable
- entered_by nullable
- timestamps

UNIQUE(monthly_cycle_id)

### ga4_country_metrics
- id
- monthly_cycle_id
- country
- active_users nullable
- new_users nullable
- sessions nullable
- engaged_sessions nullable
- engagement_rate nullable
- event_count nullable
- key_events nullable
- timestamps

### authority_metrics
- id
- monthly_cycle_id
- moz_domain_authority nullable
- moz_linking_root_domains nullable
- ahrefs_domain_rating nullable
- ahrefs_url_rating nullable
- backlinks_count nullable
- referring_domains_count nullable
- source
- synced_at nullable
- entered_by nullable
- notes nullable
- timestamps

UNIQUE(monthly_cycle_id)

### monthly_notes
- id
- monthly_cycle_id
- type
- title nullable
- body
- sort_order
- created_by
- timestamps

Types:
- win
- challenge
- observation
- recommendation
- next_month_focus

### project_report_sections
- id
- project_id
- section_key
- title
- is_enabled
- is_required
- sort_order
- timestamps

UNIQUE(project_id, section_key)

### monthly_reports
- id
- monthly_cycle_id
- status
- executive_summary nullable
- review_notes nullable
- snapshot_json nullable
- generated_pdf_path nullable
- generated_at nullable
- finalized_at nullable
- finalized_by nullable
- timestamps

UNIQUE(monthly_cycle_id)

Statuses:
- draft
- ready_for_review
- final

### monthly_report_sections
- id
- monthly_report_id
- section_key
- title
- is_enabled
- is_required
- status
- sort_order
- custom_text nullable
- settings_json nullable
- timestamps

UNIQUE(monthly_report_id, section_key)

Statuses:
- incomplete
- complete

## Relationship summary
Client 1:N Project

Project N:M User

Project N:1 Package

Project 1:N MonthlyCycle

Project 1:N Page

Project 1:N Keyword

MonthlyCycle:
- 1:N MonthlyCycleTargets
- 1:N Tasks
- 1:N Backlinks
- 1:N ContentItems
- 1:N PageOptimizations
- 1:N RankingSnapshots
- 1:1 GscMonthlyMetrics
- 1:N GscQueryMetrics
- 1:N GscPageMetrics
- 1:1 Ga4MonthlyMetrics
- 1:N Ga4CountryMetrics
- 1:1 AuthorityMetrics
- 1:N MonthlyNotes
- 1:1 MonthlyReport

MonthlyReport 1:N MonthlyReportSections

Keyword 1:N RankingSnapshots

Page 1:N PageOptimizations

## Conventions
Use:
- BIGINT unsigned IDs
- foreign keys
- utf8mb4
- UTC timestamps
- string-backed PHP enums
- soft deletes where specified

Avoid:
- MySQL ENUM
- stored progress percentages
- spreadsheet-style date columns
- generic SEO-data JSON blobs
