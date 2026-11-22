# Changelog

All notable changes to `simplestats-io/app` will be documented in this file.

## v7.5.1 - 2026-11-22

### Fixed

- Payments with a gross amount of 0 are ignored on ingest (`POST /api/v1/stats-payment`). Payment providers create completed transactions without any charge, e.g. Paddle when a customer changes the payment method. They are no revenue, but were stored as payments and triggered payment alerts. The API answers them with 200 and does not store them. laravel-client v6.1.1 stops sending them in the first place.
- Calendar range presets are sorted after the rolling ones.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.5.0...v7.5.1

## v7.5.0 - 2026-11-22

### Upgrade notes

- The Docker image now serves the app with Laravel Octane on FrankenPHP (`serversideup/php` frankenphp-alpine) instead of nginx and PHP-FPM. `docker-compose.yml` and `.env` stay unchanged, nothing needs to be configured. To run without Octane, override the `app` command as described in [Laravel Octane](https://github.com/simplestats-io/app/blob/main/docs/self-hosted.md#laravel-octane).
- Classic installs (without Docker): Octane is optional, PHP-FPM keeps working. If you run Octane, call `php artisan app:reload-octane` after every update.

### Added

- Laravel Octane with FrankenPHP: the app boots once per worker, so requests (ingest included) skip the framework bootstrap and respond noticeably faster.
- `app:reload-octane` restarts the FrankenPHP workers without dropping requests.
- Compliance (GDPR, CCPA, PECR) and security pages.

### Changed

- Visitors are always tracked. The project form no longer offers them as an option, and a migration enables them on projects where they were switched off. Their visitor data was stored all along and shows up again.

### Fixed

- The tracking and project option pickers no longer show a stale selection after creating a project.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.4.0...v7.5.0

## v7.4.0 - 2026-11-21

### Added

- Visitors with a known referrer spam domain are dropped at ingest. Some bots send a real browser User-Agent from residential IPs and only give themselves away through a faked referer. The domains come from the community-maintained Matomo referrer spam list, which ships with the app and is refreshed weekly by the scheduler (`app:update-referrer-spam-list`).
- The visitor endpoint answers a spam referer with a `skip` hint, so laravel-client v6.1.0 and newer stop sending visitors from that domain for a day.

### Changed

- The bot check result is cached per User-Agent for a day, which makes the visitor and user endpoints noticeably faster.

### Upgrade notes

- Nothing to do. The weekly list refresh runs through the existing scheduler. Without outbound access to GitHub the bundled list is used.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.3.5...v7.4.0

## v7.3.5 - 2026-11-18

### Fixed

- Backups: removed the fixed size limits from the backup cleanup and monitor. The rotation already limits the number of backups, and a full disk shows up in the disk space health check.
- Sentry: errors thrown by Microsoft's link scanner (Outlook Safe Links) are no longer reported.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.3.4...v7.3.5

## v7.3.4 - 2026-11-18

### Changed

- Backups are now monitored with `backup:monitor`, scheduled daily at 06:00 when backups are enabled. It mails the admin address (`ADMIN_MAIL`) when the newest backup on a backup disk is older than one day. The daily "healthy backup" mail is turned off, so only problems are reported.
- This replaces the backup health checks added in v7.3.3, so they no longer appear on the health page.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.3.3...v7.3.4

## v7.3.3 - 2026-11-18

### Security

- Updated dependencies with security advisories (Laravel 12.72.0, Symfony components, phpseclib 3.0.58).

### Upgrade notes

- The `local` backup disk now keeps only the newest backup, the rotation only applies to other disks such as `s3`. If you back up to `local` only and want older backups, add an off-server disk via `BACKUP_DISKS=local,s3`.

### Added

- The health checks now report a failure when the newest backup on a backup disk is older than 26 hours, so a backup that silently stopped running is noticed.
- Owners who never finished the onboarding setup get a reminder mail.

### Fixed

- Backup cleanup runs after the backup, and the storage limit for the rotation is raised to 50,000 MB.
- Landing page performance and accessibility improvements.
- Removed an outdated custom events toggle from the docs.

### What's Changed

* Bump the npm-minor-and-patch group with 4 updates by @dependabot[bot] in https://github.com/simplestats-io/app/pull/405

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.3.2...v7.3.3

## v7.3.2 - 2026-11-17

### Fixed

- Stats groups with only a few value columns now show the full column labels (e.g. "Visitors" instead of "Vis.") whenever there is enough space. Views per Visitor is labelled "VPV" with an explanatory tooltip.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.3.1...v7.3.2

## v7.3.1 - 2026-11-16

### Fixed

- Entry pages and page views whose path merely ends in letters like a file extension (e.g. `/blog/accept-json`, `/blog/holding-you-back`) are no longer dropped. Only a real dotted extension of the last path segment (e.g. `/report.pdf`) marks a file now. The same fix ships in laravel-client v6.0.1.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.3.0...v7.3.1

## v7.3.0 - 2026-11-16

### Added

- **Visitor import:** import historical visitors with referrer, UTM parameters, entry page, location and device.
- **Page view import:** attach page views to imported or tracked visitors via their `visitor_hash`.
- **Fathom Analytics import:** upload a Fathom pageview export as it is on the new "Switch from another Tool" card on the Data Imports page. Visitors, page views, entry pages and sources match Fathom per day. See [How to import existing data](https://simplestats.io/docs/how-to-import-existing-data.html#importing-a-fathom-analytics-export).

### Fixed

- The user import failed when the file contained the documented tracking columns (`track_*`, `page_entry`, `location_*`, `device_*`).
- A finished data import could make later queue jobs of the same worker drop attributes on save.

### Upgrade notes

- Contains a migration (`stats_visitors.data_import_id`, index built concurrently). It runs automatically on container start.

### What's Changed

* Bump laravel/framework from 12.70.1 to 12.71.0 by @dependabot[bot] in https://github.com/simplestats-io/app/pull/404

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.2.1...v7.3.0

## v7.2.1 - 2026-11-16

### Fixed

- Page view KPIs show "-" instead of 0 for periods before a project's first tracked page view. The Query API returns `null` for these periods.

### Docs

- The event definition in Plans and Limits now states that page views are not events.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.2.0...v7.2.1

## v7.2.0 - 2026-11-16

### Added

- **Page Views.** SimpleStats now shows how many pages your visitors open:
  
  - a **Views** column in every dashboard breakdown
  - a **Top Pages** tab next to Entry Pages
  - the new KPIs **Page Views**, **Views per Visitor** and **Bounce Rate**
  
  The numbers appear automatically once a project sends page views with [laravel-client v6](https://github.com/simplestats-io/laravel-client). They are also available in the Query API and the MCP tools, and they do not count against the monthly event quota. See [Page Views](https://simplestats.io/docs/how-to-track-page-views.html) and [Counting Page Views](https://simplestats.io/docs/counting-page-views.html).
  
- New ingest endpoint `POST /api/v1/stats-views` for page views. It has its own rate limit.
  

### Upgrade notes

- The new tables are created by migrations, which the Docker image runs on start. No new environment variables are needed.
- Page views are sent by apps running laravel-client v6 through their queue (not on the `sync` queue). A long-running worker (`queue:work` or Horizon) sends them once a minute.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.1.0...v7.2.0

## v7.1.0 - 2026-11-15

### Fixed

- Dashboard views filtered to a registration cohort could show slightly inflated numbers for the current period (e.g. visitors or registrations that do not belong to the cohort), because their cached rows were incremented like unfiltered ones. These rows are now recomputed instead, and a migration clears the affected cache rows once.

### Upgrade notes

- Contains one migration (it only clears cache rows, they are rebuilt on the next page load). No action needed beyond the usual `php artisan migrate`.

### What's Changed

* Bump the npm-minor-and-patch group with 3 updates by @dependabot[bot] in https://github.com/simplestats-io/app/pull/403

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.0.4...v7.1.0

## v7.0.4 - 2026-11-12

### Fixed

- In dark mode the browser renders native UI dark as well (navigation bar, scrollbars, form controls), instead of light.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.0.3...v7.0.4

## v7.0.3 - 2026-11-12

### Fixed

- The browser status bar, including the one of the installed app, now matches the light or dark theme instead of always being white.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.0.2...v7.0.3

## v7.0.2 - 2026-11-11

### Fixed

- The web app manifest versions its icon URLs by content, so browsers that cached an older icon pick up the new one.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.0.1...v7.0.2

## v7.0.1 - 2026-11-11

### Fixed

- The app icons leave more room around the logo, so it no longer fills the whole launcher circle on Android. Reinstall the app to pick up the new icon.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v7.0.0...v7.0.1

## v7.0.0 - 2026-11-11

### Upgrade notes

- **The name and logo are no longer configurable through env.** `APP_NAME` no longer changes the displayed app name, and `APP_MARK_IMG`, `APP_LOGO_IMG`, `APP_LOGO_MAIL` and `APP_BACKGROUND` are no longer read. Installations that used them show the SimpleStats name and logo again after the upgrade. The name and logo are trademarks, see the [Trademark Guidelines](https://simplestats.io/trademark) for what is allowed with modified versions.
- Keep `APP_NAME` in your `.env` if it is set: it still serves as the prefix for cache, session, Redis and Horizon keys. Removing or changing it logs out all users.
- When building the frontend yourself, `VITE_APP_NAME` is no longer needed in `.env`.

### Added

- Self-hosted report and alert mails include a short note that cloud customers fund the development of the self-hosted edition.

### Changed

- The Trademark Guidelines now cover modified and publicly hosted versions and allow the name in a subdomain of your own installation.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.9.0...v7.0.0

## v6.9.0 - 2026-11-11

### Fixed

- The web app manifest now carries the configured app name (`APP_NAME`), starts on the dashboard and ships sharp icons, including a maskable icon for Android. Chromium browsers offer to install the app.

### Upgrade notes

- Includes a migration (`add_scheduling_to_announcements_table`) that runs automatically on deploy. It only backs a SaaS admin feature, self-hosted installations need no action.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.8.0...v6.9.0

## v6.8.0 - 2026-11-10

### Upgrade notes

- This release contains a data migration that runs `app:unify-locations` once. It merges duplicate country rows into one per country code. It commits per team, so it can take a while on large databases, but needs no manual action.

### Fixed

- Country names are now derived from the ISO country code, so the same country no longer shows up in several rows ("Netherlands" vs "The Netherlands"). Regions and cities without a name are no longer stored.
- Projects that track payments now require visitors or registrations to be tracked as well.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.7.0...v6.8.0

## v6.7.0 - 2026-11-04

### Upgrade notes

- Contains a new migration (`teams.installation_reminder_sent_at`). It runs automatically when the `app` container starts, no manual steps needed.

### Added

- New users are guided to their first data: until a team has received its first event, the dashboard shows a setup window with the installation steps (including the API token) and checks for incoming data live. As soon as the first data arrives it switches to a success message with an optional dashboard tour.

### Changed

- The dashboard tour no longer starts automatically, it is offered after the first data arrived.
- Removed the "Open Docs" banner, the setup window covers it.
- The setup page now ends with "Go to dashboard".

### Development

- `composer test` and CI run the test suite in parallel.
- Tests no longer send real HTTP requests: stray requests are blocked and the Paddle customer, pricing preview and self-tracking calls are stubbed.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.6.2...v6.7.0

## v6.6.2 - 2026-11-04

### Fixed

- The Retention page now shows a clear notice for projects that do not track registrations, instead of repeating the dashboard charts and breakdown.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.6.1...v6.6.2

## v6.6.1 - 2026-11-03

### Fixed

- Dropdowns inside dialogs (e.g. "Manage Access") are no longer cut off.
- Dropdowns open upwards when there is not enough room below them.
- Team invitations that are limited to selected projects now name those projects in the invitation mail.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.6.0...v6.6.1

## v6.6.0 - 2026-11-03

### Upgrade notes

- Contains a migration (`project_ids` on `team_user` and `team_invitations`). It runs automatically on container start (Docker) or with `php artisan migrate --force`.

### Added

- Per-project access for team members: administrators can limit an Editor or Viewer to selected projects, both when inviting and when updating a member. Limited members only see their projects, get per-project dashboards, only receive reports and alerts for their projects and don't see the other team members. Thanks @jhhazelaar!

### Security

- Tightened authorization for ad spend changes and for manually triggering reports.

### Changed

- Pull requests now come with a template that asks for screenshots of UI changes.
- Updated npm dependencies.

### What's Changed

* Bump the npm-minor-and-patch group with 3 updates by @dependabot[bot] in https://github.com/simplestats-io/app/pull/398
* Bump follow-redirects from 1.16.1 to 1.16.2 by @dependabot[bot] in https://github.com/simplestats-io/app/pull/400
* Bump glob and sucrase by @dependabot[bot] in https://github.com/simplestats-io/app/pull/401
* Bump minimatch and sucrase by @dependabot[bot] in https://github.com/simplestats-io/app/pull/402
* Add per-project access for editors and viewers by @jhhazelaar in https://github.com/simplestats-io/app/pull/399

### New Contributors

* @jhhazelaar made their first contribution in https://github.com/simplestats-io/app/pull/399

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.5.2...v6.6.0

## v6.5.2 - 2026-10-29

### Security

- Updated `symfony/mime` to 7.4.22 for [CVE-2026-45070](https://symfony.com/cve-2026-45070) (email header injection through MIME parameter names). Thanks @edgrosvenor!
- Updated the `qs` build dependency to 6.17.1 for [GHSA-6pq8-jhm2-2vcw](https://github.com/advisories/GHSA-6pq8-jhm2-2vcw). Thanks @edgrosvenor!

### Fixed

- Dropdown lists with long select options can now grow taller instead of cutting the options off.

### What's Changed

* Update qs to fix GHSA-6pq8-jhm2-2vcw by @edgrosvenor in https://github.com/simplestats-io/app/pull/397
* Update symfony/mime to fix CVE-2026-45070 by @edgrosvenor in https://github.com/simplestats-io/app/pull/396

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.5.1...v6.5.2

## v6.5.1 - 2026-10-27

### Changed

- `app:create-new-user` now prints a link to the GitHub repo after creating the first user of a self-hosted install. Nothing else changes, no action needed.
- The docs now install the client, Filament and Statamic packages via their new install commands (`simplestats-client:install`, `simplestats-filament:install`, `simplestats-statamic:install`).

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.5.0...v6.5.1

## v6.5.0 - 2026-10-27

### Changed

- Campaign revenue is now attributed by acquisition date, like the ad platforms do: the selected range picks a campaign's ad spend and the customers it acquired in that range, and revenue is everything those customers have paid to date. Before, spend in the range was compared with payments in the range, which mixed the cost of one period with revenue from customers acquired in earlier periods. Pick "All Time" to see whether a campaign paid off overall. Recently acquired customers have had little time to pay yet, so short ranges show a lower ROAS.
- CAC and CAC payback stay empty when a campaign has no ad spend in the selected range, instead of showing 0.

### Added

- Campaigns page: a "Runs" column shows the period covered by a campaign's ad spend entries.
- Campaigns page: campaigns without spend, registrations or revenue in the selected range are collapsed below the active ones and marked "No activity in this range".

### Fixed

- The "All Time" range covered only the last two months for projects whose stats start had not been calculated yet (it is updated nightly). It now falls back to the start of last year until then.
- Picking a range preset on the Campaigns page did nothing when the page had been opened with a range but without a preset.

### Upgrade notes

No action required. Docker installations pick up the release with `docker compose pull && docker compose up -d`.

### What's Changed

* Bump the github-actions group with 5 updates by @dependabot[bot] in https://github.com/simplestats-io/app/pull/395
* Bump the npm-minor-and-patch group with 3 updates by @dependabot[bot] in https://github.com/simplestats-io/app/pull/394

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.4.1...v6.5.0

## v6.4.1 - 2026-10-26

### Security

- Session and XSRF cookies are now marked `secure` automatically when `APP_URL` uses `https://`. Before, they were only secure if `SESSION_SECURE_COOKIE` was set explicitly. An explicit `SESSION_SECURE_COOKIE` value still takes precedence.

### Upgrade notes

No action required. Docker installations pick up the fix with `docker compose pull && docker compose up -d`.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.4.0...v6.4.1

## v6.4.0 - 2026-10-26

### Added

- **Official Docker image and compose setup.** SimpleStats now ships as a prebuilt image (`ghcr.io/simplestats-io/app`, amd64 and arm64) together with a `docker-compose.yml` that runs the app, Horizon, the scheduler, PostgreSQL and Redis. An optional Caddy profile takes care of HTTPS. Migrations run automatically on start, and updating is `docker compose pull && docker compose up -d`. See [Docker](https://simplestats.io/docs/self-hosted.html#docker-recommended) in the self-hosted docs.
- `/up` health endpoint for container and load balancer probes.
- `TRUSTED_PROXIES` env variable to trust a reverse proxy, so HTTPS and client IPs are detected correctly behind Caddy, Traefik, nginx and the like.

### Fixed

- Horizon workers on the self-hosted build no longer crash with "The stop-when-empty-for option does not exist". Shared dependencies now resolve to the same versions as the hosted version.
- `php artisan optimize` failed on the self-hosted build while caching views. The docs now use `php artisan optimize --except=views`.

### Security

- Updated `postcss-selector-parser` to 6.1.5 (GHSA-rj75-hqrm-r3gf). It is only used while building the frontend assets.

### Upgrade notes

No action required for existing installations. Recommended for classic (non Docker) setups:

- Run `php artisan optimize --except=views` instead of `php artisan optimize` when deploying.
- If SimpleStats runs behind a reverse proxy, set `TRUSTED_PROXIES` (`*` or the proxy IPs).

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.3.1...v6.4.0

## v6.3.1 - 2026-10-26

### Security

- Entry pages are now correctly scoped to the current team. Previously the `GET /api/v1/stats/groups?stats_type=page_entry` endpoint could return entry pages that did not belong to the requesting team. Updating is recommended for all self-hosted installations.

### Fixed

- The groups endpoint for entry pages no longer runs out of memory on installations with many entry pages.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.3.0...v6.3.1

## v6.3.0 - 2026-10-24

### Upgrade notes

- Contains a migration that recalculates the login and payment counters once. It runs automatically with `php artisan migrate` and takes a few seconds even on large databases.

### Fixed

- Paying Users, Reg-to-Pay, ARPPU and Time to Pay could be too high in projects whose payment ids also occur in another project. The nightly counter check now ranks payments per project, and affected dashboards refresh automatically.

### Improved

- Logins of paying users no longer discard the cached current period, so dashboards with month or year ranges stay fast throughout the day.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.2.1...v6.3.0

## v6.2.1 - 2026-10-24

### Security

- Upgrade `laravel/mcp` to 1.1.1 (fixes GHSA-m25c-38v7-94p8). The MCP endpoint now also supports the 2026-07-28 protocol revision, existing MCP clients keep working unchanged.
- Upgrade `livewire/livewire` to 4.4.8 (fixes GHSA-mhf7-xp6r-6qrc).
- Upgrade `vite` to 6.4.5 to fix the `launch-editor` advisories (build tooling only).

### Fixed

- Bump `follow-redirects` to 1.16.1 and `driver.js` to 1.10.1.
- Update `laravel/framework` to 12.70.1 and related dependencies.

### Upgrade notes

No action required. Run `composer install` and `npm ci && npm run build` as usual.

### What's Changed

* Bump driver.js from 1.9.0 to 1.10.1 in the npm-minor-and-patch group by @dependabot[bot] in https://github.com/simplestats-io/app/pull/391
* Bump follow-redirects from 1.16.0 to 1.16.1 by @dependabot[bot] in https://github.com/simplestats-io/app/pull/392

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.2.0...v6.2.1

## v6.2.0 - 2026-10-19

### Upgrade notes

- Run `php artisan migrate`: a migration adds the missing `check_name` and `check_label` columns to the health check history table.
- No new required environment variables. New optional ones:
  - `CACHE_STORE` (the Laravel 11+ name) is now read; `CACHE_DRIVER` keeps working as a fallback.
  - `BACKUP_ENABLED` (default `true`) turns scheduled backups off where the platform already backs up the database. On Laravel Cloud backups are off by default and target the attached bucket.
  - `SHARED_DISK` (default `local`) selects the disk used to hand data import uploads to the queue worker, for setups where web and worker do not share a filesystem.
  

### Added

- The first user created with `php artisan app:create-new-user` is now an admin, so a fresh self-hosted install can reach the admin panel, Horizon and Pulse right away.
- Trademark guidelines page and a license & trademarks section in the README.
- Horizon and Pulse are linked for admins in the profile dropdown, which is now grouped into sections.

### Fixed

- Scheduled project reports no longer abort when one of the recipients' accounts is deactivated, so all other reports of that run are sent again.
- Project reports and alerts are no longer sent to deactivated accounts.
- Removing a member from a team (or leaving it) now also removes their report and alert subscriptions for that team's projects.
- The Horizon health check is only registered when the queue actually runs on Horizon.
- Data imports work when the web server and queue worker run on separate filesystems.
- Page titles no longer render as "undefined SimpleStats" in builds without a local `.env`.
- The `storage/` skeleton is part of the repository again, so a fresh clone installs without errors.
- Broken API link in the landing page footer; the old API documentation URL now redirects to the ingest API docs.

### What's Changed

* Keep the storage skeleton in the repository by @edgrosvenor in https://github.com/simplestats-io/app/pull/383
* Read the cache store from CACHE_STORE as well as CACHE_DRIVER by @edgrosvenor in https://github.com/simplestats-io/app/pull/384
* Stop page titles rendering as "undefined SimpleStats" by @edgrosvenor in https://github.com/simplestats-io/app/pull/386
* Register the Horizon health check only when the queue runs on Horizon by @edgrosvenor in https://github.com/simplestats-io/app/pull/388
* Let backups default to off where the platform already backs the database up by @edgrosvenor in https://github.com/simplestats-io/app/pull/389
* Add the missing check_name and check_label health history columns by @edgrosvenor in https://github.com/simplestats-io/app/pull/385
* Hand data import uploads to the queue worker on a shared disk by @edgrosvenor in https://github.com/simplestats-io/app/pull/387
* Make the first user created by app:create-new-user an admin by @edgrosvenor in https://github.com/simplestats-io/app/pull/390

### New Contributors

* @edgrosvenor made their first contribution in https://github.com/simplestats-io/app/pull/383

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.1.1...v6.2.0

## v6.1.1 - 2026-10-15

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.1.0...v6.1.1

## v6.1.0 - 2026-10-14

### Upgrade notes

- **Backup disks are now configurable via `BACKUP_DISKS`** (comma-separated, default `local`). Previously backups were always written to `local` and `s3`. If you rely on off-server backups on S3, set `BACKUP_DISKS=local,s3` in your `.env` before updating, otherwise backups are only stored on the server. See `docs/self-hosted.md` (Backups).

### Fixes

- Server-side rendered public pages (landing page, auth pages) briefly showed `[object Promise]` before the page loaded, caused by the Vue 3.5.44 update in v6.0.1.

### What's Changed

* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/simplestats-io/app/pull/379
* Bump actions/cache from 4 to 6 by @dependabot[bot] in https://github.com/simplestats-io/app/pull/377
* Bump actions/checkout from 5 to 7 by @dependabot[bot] in https://github.com/simplestats-io/app/pull/378
* Bump the npm-minor-and-patch group with 13 updates by @dependabot[bot] in https://github.com/simplestats-io/app/pull/382

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/simplestats-io/app/pull/379

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.0.1...v6.1.0

## v6.0.1 - 2026-10-13

### Security

Updates dependencies with known vulnerabilities: `maatwebsite/excel` 3.1.70, `phpoffice/phpspreadsheet` 1.30.7, `livewire/livewire` 4.4.7, `phpseclib/phpseclib` 3.0.57, `web-auth/webauthn-lib` 5.3.9 and `vue` 3.5.44, plus several patched npm packages. No configuration changes are required, a regular update (`composer install`, `npm ci`, `npm run build`) is enough.

**Full Changelog**: https://github.com/simplestats-io/app/compare/v6.0.0...v6.0.1

## v6.0.0 - 2026-10-13

First tagged release of the open source version. Self-hosted installations should use tagged releases from now on, see the updating guide: https://simplestats.io/docs/self-hosted.html#updating
