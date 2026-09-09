# Legacy Spreadsheet Migration (Milestone 17)

An administrative toolkit that moves the office's historical spreadsheet
data into the application through the normal domain actions. It is not a
product feature: there is no Filament screen, only an Artisan command run
by a Super Admin.

```
php artisan seo:migrate-legacy path/to/workbook.xlsx --dry-run --actor=admin@agency.test
php artisan seo:migrate-legacy path/to/workbook.xlsx --apply   --actor=admin@agency.test --mapping=storage/legacy/mapping.json --json-report=storage/legacy/report.json
```

Exactly one of `--dry-run` / `--apply` is required. A dry run parses, maps
and validates everything and rolls every domain change back; it records a
`legacy_migration_runs` row marked `dry-run` with the full report. Apply
writes for real. The source file is only ever opened for reading.

## Source formats

- one `.xlsx` workbook with one worksheet per entity (Google Sheets → File
  → Download → Microsoft Excel), or
- a directory containing one `.csv` per sheet, named after the sheet.

Sheet and column names are matched case-insensitively (spaces, dashes and
underscores are equivalent). Each sheet lists a few documented aliases;
there is no other header guessing. Unknown sheets and columns are reported
as "unmapped" so they can be decided on.

## The migration workbook layout

Every data sheet carries a `project` column: the project name or website
exactly as written on the `Projects` sheet (or a key from the mapping
file). Periods are written `YYYY-MM`. Dates are `YYYY-MM-DD` (optionally
with `HH:MM[:SS]`); slashed dates are rejected as ambiguous.

| Sheet | Columns (`*` required) |
|---|---|
| `Projects` | `legacy_client*` (account / sheet name), `client_name`, `project_name*`, `website_url*`, `target_location`, `owner` (email or mapped name), `team` (comma-separated), `package`, `start_date`, `status`, `notes` |
| `Targets` | `project*`, `period*`, `target_key*`, `target_value*`, `label` — the target that applied in that historical month |
| `Rankings` | `project*`, `keyword*`, `location`, `target_page_url`, `period`, then one column per observation date (`2026-07-01`, `Jul 01 2026`, or `Jul 01` with `ranking_year` in the mapping) |
| `Backlinks` | `project*`, `published_url*`, `type*`, `status*`, `published_date`, `period`, `anchor_text`, `target_url`, `domain_authority`, `domain_rating`, `spam_score`, `notes` |
| `Content` | `project*`, `title*`, `type*`, `status*`, `period`, `target_keyword`, `planned_date`, `published_at`, `published_url`, `assignee`, `notes` |
| `Tasks` | `project*`, `title*`, `status`, `due_date`, `assignee`, `period`, `category`, `priority`, `description` |
| `PageOptimizations` | `project*`, `page_url*`, `optimized_at*`, `period`, `meta_title`, `meta_description`, `content`, `internal_links`, `schema` (yes/no), `user`, `notes`, `title` |
| `Analytics` | `project*`, `period*`, `gsc_clicks`, `gsc_impressions`, `gsc_ctr`, `gsc_average_position`, `ga4_*` summary columns, `moz_domain_authority`, `moz_linking_root_domains`, `ahrefs_domain_rating`, `ahrefs_url_rating`, `authority_backlinks_count`, `referring_domains_count`, `authority_notes` |
| `GscQueries` | `project*`, `period*`, `query*`, `clicks*`, `impressions*`, `ctr`, `average_position` |
| `GscPages` | `project*`, `period*`, `page_url*`, `clicks*`, `impressions*`, `ctr`, `average_position` |
| `Ga4Countries` | `project*`, `period*`, `country*`, `active_users`, `new_users`, `sessions`, `engaged_sessions`, `engagement_rate`, `event_count`, `key_events` |
| `Notes` | `project*`, `period*`, `type*` (win, challenge, observation, recommendation, next_month_focus), `body*`, `title` |

Percentages keep the human convention (8.5 means 8.5%). Blank ranking
cells and the documented not-ranking tokens (`-`, `NR`, `100+`, `0`, …)
become Not Ranking (NULL); a position of 0 is never stored.

## Mapping file

`config/legacy-migration.php` holds the explicit office mapping; a JSON
file passed with `--mapping` is merged over it:

```json
{
  "clients":  {"Afzal": 3},
  "projects": {"casabotanica.co.uk": 12},
  "users":    {"Hassan": "hassan@agency.test"},
  "packages": {"Gold": 2},
  "ranking_year": 2026,
  "values":   {"task_status": {"Done ✔": "completed"}}
}
```

## Identity and rerun rules

| Entity | Identity | Existing record |
|---|---|---|
| Client | ledger, then explicit `clients` mapping | reused via mapping; same name without mapping = conflict |
| Project | ledger, explicit `projects` mapping, then normalised website URL within the client | reused, attributes untouched; same URL under another client = conflict |
| MonthlyCycle | project + year + month | reused without re-snapshotting targets; locked = conflict, never unlocked |
| Targets | cycle + key | applied only when the cycle is created by the migration; otherwise equal = skip, different = conflict |
| Page | project + URL | reused |
| Keyword | normalised keyword + location within the project | reused |
| RankingSnapshot | keyword + checked_at + source (manual) | equal = skip, different = conflict |
| Analytics | cycle (summaries), cycle dataset (detail rows) | equal = skip, different = conflict, never overwritten |
| Backlink, Content, Task, PageOptimization, Note | ledger fingerprint of the row's identity fields | skip |

Every run is recorded in `legacy_migration_runs` with the source SHA-256
checksum and the mapper version; issues live in `legacy_migration_issues`;
`legacy_migration_records` is the ledger. Each (sheet, project) group runs
in its own transaction: a failing group is rolled back and reported
without affecting the others.

## What is deliberately not done

No Google Sheets API or OAuth, no fuzzy client or user matching, no user
creation, no reconstruction of finalized reports or PDFs, no unlocking of
finalized months, no XLSX upload in the product.
