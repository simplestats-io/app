# SimpleStats Project Guidelines

## Overview

SimpleStats is a **server-side analytics SaaS** built specifically for Laravel applications. It provides precise insights into user origins, behaviors, and revenue attribution by tracking visitors, registrations, logins, and payments. The platform is **GDPR-compliant** and unaffected by ad blockers since all tracking occurs server-side.

### Why Server-Side Tracking?

- Ad blockers can block **30-50%** of client-side analytics data
- Server-side tracking ensures more accurate and comprehensive data collection
- Enhanced data security without relying on third-party cookies
- Full compliance with data protection regulations (GDPR)

## Architecture

### Two-Part System

1. **SimpleStats App** (this repository): The main Laravel SaaS application hosted at https://simplestats.io
   - Dashboard for viewing analytics
   - Team and project management
   - Data import functionality
   - API endpoints for receiving tracking data

2. **Laravel Client Package** (`packages/simplestats-io/laravel-client`): Composer package installed by customers
   - Located at `composer require simplestats-io/laravel-client`
   - Supports Laravel 11, 12, and 13
   - Sends tracking data to the SimpleStats API

### Tech Stack

- **Backend**: Laravel 12 (VILT Stack)
- **Frontend**: Vue 3 / Inertia.js / Tailwind CSS
- **Database**: PostgreSQL 14+
- **Queue**: Laravel Horizon for background job processing

### Database Strategy

- **SimpleStats app (SaaS)**: PostgreSQL
- **SimpleStats app (self-hosted)**: PostgreSQL
- **Client package (`packages/simplestats-io/laravel-client`)**: **DB-agnostic.** The client package is installed into the *customer's* Laravel app, whose database is unknown to us (MySQL, MariaDB, SQLite, PostgreSQL, ...). Never assume PostgreSQL when working in the client package. Anything the package touches (migrations, cache/DB-backed features, column sizes/types) must work across all Laravel-supported drivers, and code/config decisions must account for driver-specific limits (e.g. the MySQL `cache.value` column size limit for the DB cache driver). The PostgreSQL assumption applies **only** to the SimpleStats app itself, never to the client package.

### Migrations: No `down()` / No Rollbacks

- **We do not use `down()` methods.** Migrations are forward-only. Never add a `down()` method to a migration; rolling back is not part of our workflow (`migrate:rollback` is unused).

### Deployment Options

1. **SaaS** (recommended): Hosted at https://simplestats.io with automatic updates and dedicated support
2. **Self-hosted**: Free and open source (AGPL-3.0, public repo `simplestats-io/app`), full control over data and deployment
   - Runs on the fixed, unlimited `Self-Hosted` plan (`UserBase::selfHostedPlanConfig()`): no event/project limits, all features. Spark plans and `PLAN_AUTO_MAX_USERS` only apply to the SaaS.
   - SaaS-only scheduled chores (plan alarm, inactive-user cleanup) are not scheduled on self-hosted (`app/Console/Kernel.php`)
   - Setup, maintenance, and troubleshooting are the operator's responsibility
   - Requires: PHP 8.3+, Laravel 12+, PostgreSQL 14+, Node.js 21.6+

### Docker Image (Self-Hosted)

Self-hosters are pointed to the official image `ghcr.io/simplestats-io/app` (built by `.github/workflows/docker.yml` on every published release, amd64 + arm64, tags `X.Y.Z` / `X.Y` / `X` / `latest`) and the root `docker-compose.yml` (app, horizon, scheduler, postgres, redis, optional Caddy via `--profile https`). Keep these in mind:

- The image is the **self-hosted build** (`composer.json`), based on `serversideup/php` (nginx + PHP-FPM, Alpine). Its entrypoint runs `storage:link`, `migrate --force` and `optimize` on start of the `app` container (`AUTORUN_*` env vars).
- **Never enable the view cache for self-hosted builds** (`view:cache`, plain `php artisan optimize`): it compiles every Blade file, and the SaaS-only views (Filament pages, og-image) reference components that do not exist without `composer.saas.json`, so the command fails. Use `php artisan optimize --except=views`; views compile lazily.
- `VITE_*` values are baked into the bundle at image build time (`VITE_APP_NAME=SimpleStats`), runtime env cannot change them.
- Configuration is runtime env only (`env_file: .env` next to the compose file, template `.env.docker.example`). Nothing environment-specific belongs in the image.
- nginx FastCGI buffers are raised to 32k in the `Dockerfile`: the Vite preload `Link` headers exceed the 8k default and cause a 502.
- New PHP extensions, required env vars or changed processes must be reflected in `Dockerfile`, `docker-compose.yml` and `.env.docker.example`. A change that forces self-hosters to edit their compose file or `.env` is a **major** release.
- Reverse proxies are trusted via `TRUSTED_PROXIES` (`config/trustedproxy.php`, read by the framework's TrustProxies middleware). `/up` is the unauthenticated health probe, registered outside the `web` group so probes do not create sessions.

### Open Source Repo: Self-Hosted Is the Default Build

The repo is public. Its default build is the self-hosted one, the SaaS is the special case:

- `composer.json` / `composer.lock`: **self-hosted** (default, what contributors and self-hosters install with a plain `composer install`).
- `composer.saas.json` / `composer.saas.lock`: **SaaS** (adds Spark, Filament, og-image, blog/landing/translation tooling, path repos for the client packages). Locally always run Composer through `scripts/composer-saas.sh` (wraps `COMPOSER=composer.saas.json composer ...`, defaults to `install`); a plain `composer install` would swap the local `vendor/` to the self-hosted build. `scripts/deploy.sh` sets the same env var.
- `APP_TYPE` defaults to self-hosted; only `APP_TYPE=saas` enables SaaS code paths.
- External contributions require the signed CLA (`CLA.md`, `.github/workflows/cla.yml`). It is what allows combining contributed AGPL code with proprietary Spark in the SaaS. Never merge an external PR without a signed CLA.
- Issues in the public `simplestats-io/app` repo are visible to everyone (GitHub has no private issues in public repos), so they are reserved for external bug reports and feature requests. Internal ideas, roadmap items and maintainer-only tasks live as issues in the **private repo `simplestats-io/internal`** (all pre-launch issues were transferred there on 2026-10-13 and got new numbers; old `#123` references in commit messages do not match the `internal` numbers, but the old `github.com/simplestats-io/app/issues/123` URLs redirect to the transferred issue for maintainers). Never put customer names, domains, dashboard screenshots or other internal details into public issues, PRs or commit messages. Internal notes stay in `notes/` (gitignored).
- **`notes/` is its own git repository**, a checkout of the private `simplestats-io/internal` repo nested inside the app working tree (not a submodule, so the private URL never appears in the public repo). Commit and push notes from inside `notes/` (`git -C notes ...`), never from the app repo, and only after the maintainer's go-ahead like any other commit. On a fresh clone of the app, restore it with `git clone git@github.com:simplestats-io/internal.git notes`.

### Keep `composer.json` and `composer.saas.json` in Sync

The two manifests are maintained by hand, neither is generated from the other. Whenever one changes, decide explicitly and mirror where needed:

- **New package for a shared feature** (available self-hosted, e.g. `laravel/ai` + `laravel/mcp` for the AI assistant): add it to both files with the same version constraint.
- **New saas-only package** (e.g. Spark, Filament, og-image, blog/landing/translation tooling): add it to `composer.saas.json` only, and guard every call site behind `config('app.app_type') === 'saas'` (see `AppServiceProvider`, `app/Models/User.php`).
- **Version bumps of shared packages** (especially security fixes): bump in both files.
- **PHP requirement or `config.platform` changes**: mirror them (both are on `php: ^8.3` + platform pin `8.3.32` since `laravel/ai` requires `^8.3`). Also update the requirements lists in `README.md`, `docs/self-hosted.md`, and this file.
- After editing, regenerate the lock that does not belong to your local `vendor/` with `--no-install`, e.g. `composer update <packages> --with-all-dependencies --no-install --no-scripts` for the self-hosted lock while working on the SaaS vendor. Verify the resolved versions of shared packages match between `composer.lock` and `composer.saas.lock`.

### CI, Code Quality and Releases

GitHub Actions run on every push to `main` and every PR: `tests.yml` (Pest incl. the `Arch` suite, PostgreSQL service) and `static-analysis.yml` (Pint, Larastan, Rector dry-run). Locally the same checks are `composer check` and `composer test`; `composer lint` / `composer refactor` apply the fixes.

- **CI installs the self-hosted build (`composer.json`)**, never the SaaS one: no Spark credentials needed and fork PRs work. Every test that needs saas-only packages or code paths must call `$this->skipUnlessSaas()` (in `beforeEach` for whole files). Locally (`APP_TYPE=saas`) these tests still run.
- **Larastan** runs on level 6 with `phpstan-baseline.neon`. The baseline must be generated on the self-hosted build (otherwise its "class not found" entries are missing and CI fails): a temporary checkout with a plain `composer install`, then `composer types:generate-baseline`. Files that declare classes on top of saas-only packages are listed under `excludePaths.analyse` in `phpstan.neon`. `tests/` is not analysed until Pest 5 makes `pestphp/pest-plugin-phpstan` available.
- **Rector** runs with prepared sets plus rector-laravel. `CarbonToDateFacadeRector` and `IssetOnPropertyObjectToPropertyExistsRector` are skipped on purpose (semantic changes). Run Rector before Pint; Rector sometimes needs a second pass.
- **Arch tests** (`tests/ArchTest.php`) encode project conventions (no private controller helpers, no mutable Carbon, enums/concerns/requests shapes, Pest php/security presets). Existing violations are listed via `->ignoring()`; do not add new ones.
- **Dependabot** only updates GitHub Actions and npm. Composer updates stay manual because both manifests must be bumped together. Dependabot alerts only cover `composer.lock`, never `composer.saas.lock` (production), so `security-audit.yml` runs `composer audit --locked` on both locks weekly (no install, no Spark credentials needed). Locally: `composer audit --locked` and `COMPOSER=composer.saas.json composer audit --locked`.
- **Releases:** the SaaS is deployed from `main`, but only manually: the maintainer triggers every production deploy personally in Forge (auto-deploy is intentionally off), so a push to `main` is not live yet. Never deploy yourself. Self-hosters use tags. Create a GitHub release (`gh release create vX.Y.Z --generate-notes`), `update-changelog.yml` then writes the notes into `CHANGELOG.md` on `main` (never edit it by hand). SemVer: **major** = self-hosters must act (new required env var, raised PHP/PostgreSQL requirement, breaking config), **minor** = features and migrations, **patch** = fixes. Breaking steps go into the release notes.
- **Release notes must be complete when the release is published.** Two pitfalls, both hit with v6.1.1:
  - `--generate-notes` builds the notes from merged **PRs** only. Commits pushed directly to `main` do not show up, so a release without PRs gets nothing but the "Full Changelog" link. Write the notes yourself and pass them with `--notes` / `--notes-file` (`gh` prepends them to the generated part), e.g. `gh release create vX.Y.Z --generate-notes --notes-file notes.md`. Group by `## Security` / `## Upgrade notes` / `## Added` / `## Fixed`, and never put customer names or exploit details into them (public repo).
  - `update-changelog.yml` only runs on `release: released`, i.e. once at publish time. Editing the notes afterwards (`gh release edit`) updates the GitHub release but **not** `CHANGELOG.md`. To review first, create the release with `--draft`, check it, then publish with `gh release edit vX.Y.Z --draft=false`.
  - The workflow pushes an "Update CHANGELOG" commit to `main` right after publishing, so run `git pull` before the next local commit.
- **Release cadence (self-hosted):** `main` reaches the SaaS with each manual deploy, but self-hosters only get what is tagged, so untagged work silently never reaches them. The SaaS acts as the canary: tag once a change has run on production for a few days without new errors.
  - **Security fix:** patch release the same day, mention it in the release notes.
  - **Feature or migration landed:** minor release once it has soaked on the SaaS (a few days), do not batch features for months.
  - **Safety net:** if `main` has commits that are not in the latest tag and the latest tag is older than ~4 weeks, cut a release (fix bundle → patch, anything with features or migrations → minor).
  - When finishing a feature, a fix with user impact, or a migration, remind the maintainer that a self-hosted release is due (and whether it is patch, minor or major), instead of tagging yourself.

## Laravel Boost

This project uses **Laravel Boost**, an MCP server that auto-generates `CLAUDE.md` from the files in `.ai/guidelines/`.

- **Never edit `CLAUDE.md` directly** — it is auto-generated and will be overwritten.
- All project guidelines must be added/edited in `.ai/guidelines/` (e.g., `.ai/guidelines/project_guidelines.md`).
- Laravel Boost provides MCP tools for database queries, Artisan commands, documentation search, tinker, and more — use them.

## Skills

Domain-specific skills are available in `.claude/skills/` and should be activated when working in their domain:

- **`pest-testing`** — Activate when writing or debugging tests, adding assertions, or working with Pest.
- **`inertia-vue-development`** — Activate when creating/editing Vue pages, forms, or navigation with Inertia.
- **`tailwindcss-development`** — Activate when adding or modifying styles, working with Tailwind CSS utilities.

## Performance is Critical

The app must handle **hundreds of thousands of requests** and store massive amounts of data efficiently.

### Performance Guidelines

- Optimize all database queries - use indexes appropriately
- Use eager loading to prevent N+1 queries
- Leverage Laravel's queue system for heavy operations
- Use database-level aggregations where possible
- Cache frequently accessed data appropriately
- ~~Consider read replicas for high-traffic scenarios~~

## Data Model Concepts

### Entities Tracked

1. **Visitors**: Unique visitors identified by a GDPR-compliant hash (IP + User-Agent + daily salt)
2. **Users**: Registered users (linked to customer's User model)
3. **Logins**: User authentication events
4. **Payments**: Financial transactions with gross/net amounts in cents

### Flexible Tracking Configuration

Clients choose their tracking combination during registration. Not all apps need every feature:

| Tracking Type | Description |
|---------------|-------------|
| **Visitors** | Track anonymous visitors with UTMs, location, devices, etc. |
| **Registrations** | Track user signups and logins, including timestamps |
| **Payments** | Track revenue and link it back to visitors or registrations |
| **Subscriptions** | Track subscriptions (MRR/ARR). Requires Registrations + Payments. |

Common combinations:
- **Visitors only**: Landing pages, content sites
- **Visitors + Registrations**: Apps without payments
- **Visitors + Payments**: E-commerce without user accounts (guest checkout)
- **Visitors + Registrations + Payments**: SaaS with one-time payments / lifetime deals
- **All four (+ Subscriptions)**: Subscription SaaS

This is stored as a bitmask per project (`USAGE_*` on the `Project` model).

The **Subscriptions** bit (`Project::USAGE_SUBSCRIPTION`) is an explicit per-project *intent*, not inferred from whether a subscription payment already arrived. It gates all MRR/ARR surfaces (dashboard MRR chart, the Subscriptions area at route `recurring-revenue`, the nav link via `Team::tracksRecurring()` / shared `teamTracksRecurring` prop) so a subscription project shows an empty MRR line from day one instead of the chart silently appearing only after the first recurring payment. It implies Registrations + Payments (enforced in `ProjectRequest`), so checking the single bit is sufficient. The bit reuses value `8` from the retired "events" bit, which the `2026_06_11` migration cleared from all rows.

### Project Flags (business-model properties, separate from `usage`)

`projects` carries a **second** `smallInteger` bitmask, `flags` (default `0`), kept deliberately separate from `usage`. The distinction is semantic: `usage` answers *what is tracked* (Visitors/Registrations/Payments/Subscriptions, nouns for data kinds), while `flags` answers *what the project's business model is like* (properties). Keeping them apart means a property never collides with a tracking bit and the form UI can present them as distinct sections.

Both bitmasks live on the **`Project` model** (not in `StatsService`) and follow one consistent naming convention keyed off the column name (`usage` / `flags`, no "tracking" prefix): the usage bits as `Project::USAGE_*` + `Project::getUsages()` + `Project::getUsageBitsCount()` + `Project::hasUsages()`, and the flags as `Project::FLAG_*` + `Project::getFlags()` + `Project::getFlagBitsCount()` + `Project::hasFlags()`. Each `get*()` returns UI metadata (shape `['key' => ['bit', 'name', 'descr']]`). They are project configuration (columns on `projects`), not stats-aggregation logic, so the model is their home; `StatsService` keeps only stats/aggregation helpers.

The first flag is **`FLAG_PAID_ONLY`** (key `paid_only`, label "Paid users only"). It is an explicit per-project intent (never inferred from data) declaring that every registered user is a paying customer (subscription-only SaaS or a paid paywall, no free plan / no unpaid trial). Note the positive framing: the toggle the user enables states what *is* true, rather than a "no free tier" negation. When set, the "X" vs "paying X" KPI pairs collapse to identical values, so the redundant paying-variants are hidden from the dashboard and the metric detail menu: ARPPU (`lt_arppu`), Paying Users (`lt_pu`), DAPU (`pd_dapu`), NAPU (`pd_napu`), Reg-to-Pay (`lt_reg2pu`). ARPU↔ARPS and Subscribers↔Paying-Users are intentionally **kept** (their gap is meaningful: average lifetime, and the churned-customer segment). Hiding works via `hideWhenPaidOnly: true` on the KPI defs plus a `kpiVisible` clause in `useChartDefinitions.js`, driven by the `useProjectFlags` composable. The flag implies Registrations + Payments (enforced in `ProjectRequest`, same pattern as the Subscription bit), and the project-properties form section ("How does your project work?") is only shown when both are tracked.

The prop names mirror this too: `commonProps()` shares the `usageBits` / `flagBits` maps (`{key => bit}`) for the dashboard, and the project/setup controllers pass the full metadata as `usages` / `flags`. One difference: the **"All Projects"** pseudo-filter merges `flags` with bitwise **AND** (not OR like `usage`), so a property applies to the aggregate view only when *every* project has it.

The form UI lives under `resources/js/Components/Project/` and is layered: `FlagsSelector.vue` (the card/radio picker over the metadata) and `UsageSelector.vue` are the low-level pickers, each wrapped by a field component — `FlagsField.vue` / `UsageField.vue` — that bundles the label, the shared sub-text, the picker, and `InputError`. The field components centralize those texts in one place and carry a `stacked` prop for the Setup (`FullscreenCard`) layout vs. the `FormSection` grid, so Create/Edit/Setup all reuse the same wrapper. `FlagsField` also owns its own visibility rule (only shown when Registrations + Payments are tracked). The matching composables are `useUsage` / `useFlags`.

### Tracking Codes (UTM Parameters)

The client package tracks these dimensions:
- `source`: utm_source, ref
- `medium`: utm_medium, adGroup, adGroupId
- `campaign`: utm_campaign
- `term`: utm_term
- `content`: utm_content

The `tracking_codes` config is the source of truth for the `utm_*`/source params only. The `referer` is the real document referrer and is handled separately by `CheckTracking::getReferer()`: it comes from the HTTP `Referer` header (or an explicitly forwarded `document.referrer` via the `document_referer` param / `X-Document-Referer` header for headless/SPA setups). URL query params like `?referer=`/`?referrer=` are not tracked at all. `ref` stays a `source` alias because it is often a campaign tag (e.g. `?ref=newsletter`); the app backend still maps a domain-like `source` (one containing a dot) to a referer for backwards compatibility with older clients. Neither the client nor the backend ever records the app's own domain as a source or referer.

Payments are **always** associated with the user's registration tracking codes for campaign ROI analysis.

## API Design

### Base URL

`https://simplestats.io/api/v1/`

### Rate Limits

Rate limits are defined per plan in `config/spark.php` under `ApiRateLimit`:

| Plan       | Rate Limit |
|------------|------------|
| Free       | 200/min    |
| Starter    | 500/min    |
| Plus       | 1,000/min  |
| Business   | 2,500/min  |
| Scale      | 3,000/min  |
| Premium    | 3,500/min  |
| Ultimate   | 4,000/min  |
| Enterprise | 5,000/min  |

### Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/stats-visitor` | Track unique visitor |
| POST | `/stats-user` | Track new registration |
| POST | `/stats-login` | Track login event |
| POST | `/stats-payment` | Track payment (amounts in cents) |

### Important API Rules

- All amounts (gross, net) must be in **cents** (e.g., $1 = 100)
- Currency must be **ISO-4217** format (EUR, USD, GBP)
- Time format: `Y-m-d H:i:s P`
- IP and User-Agent are used for geo/device detection but **not stored**

## Client Package Architecture

Located in `packages/simplestats-io/laravel-client/`:

### Key Components

- **SimplestatsClientServiceProvider**: Registers middleware, observers, and event listeners
- **CheckTracking Middleware**: Captures visitor data on first GET request
- **UserObserver**: Tracks user registrations
- **PaymentObserver**: Tracks payments
- **UserLoginListener**: Tracks login events
- **SendApiRequest Job**: Queued API calls with retry logic

### SimplestatsClient Stays Orchestrator-Only

`SimplestatsClient` must remain a thin orchestrator: it builds payloads, fires events, and dispatches the queued API calls. Any non-trivial logic (e.g. invoking the customer's custom properties resolvers) belongs in small dedicated service classes under `src/Services/` (see `CustomPropertiesResolver`).

### Keep Client Config in Sync (Three Places)

Whenever the client package config changes (a new key, a changed default, an updated comment block), the same change must be applied in **all three** of these places, they are not generated from one another:

1. `packages/simplestats-io/laravel-client/config/simplestats-client.php` (the actual published config)
2. `packages/simplestats-io/laravel-client/README.md` (the config block in the README)
3. `docs/installation.md` (the full config listing under "Configuration")

Keep the comment blocks and defaults identical across all three so customers see the same thing wherever they look.

### Contracts for Customer Implementation

- `TrackablePerson`: Basic user tracking
- `TrackablePersonWithCondition`: Conditional user tracking (e.g., after email verification)
- `TrackablePayment`: Basic payment tracking
- `TrackablePaymentWithCondition`: Conditional payment tracking (e.g., after status = completed)
- `ResolvesUserCustomProperties` / `ResolvesVisitorCustomProperties`: Custom properties resolvers, registered in the `custom_properties_resolvers` config. Resolver failures must never break the host app (report + skip), a misconfigured resolver class (missing contract) is treated the same way.

### Visitor Hash

```php
// Hash = hash(User-Agent + IP + daily_salt)
// Stored in session: session('simplestats.visitor_hash')
```

### Duplicated / Mirrored Surface Between Backend and Clients (Keep in Sync)

Several pieces of logic are **deliberately duplicated** across three independent codebases rather than extracted into a shared package:

- **(A) backend** — the SimpleStats app (`app/`), Laravel 12, PostgreSQL only.
- **(B) laravel-client** — `packages/simplestats-io/laravel-client/`, runs inside customer apps, must stay DB-agnostic, lightweight, and Laravel-version-spanning.
- **(C) php-client** — `packages/simplestats-io/php-client/`, framework-agnostic plain PHP (no Laravel).

**Why no shared package (decision, 2026-07):** once you subtract the parts that legitimately diverge (B needs the inbound `Request` for Sec-Fetch/prefetch/`except`; A needs `LocationService` for datacenter-IP/VPN filtering; the `DeviceDetectorCache` store differs; "own domain" resolves from DB in A vs `config('app.url')` in B vs an argument in C; B must run on Laravel 8-13 and any DB), the truly shareable surface is small. A shared dependency pulled into the customer-facing B would force a coordinated B release + customer `composer update` for every shared-code change, against B's lightweight/minimal-deps constraint. The cost outweighs the churn. **If this shared surface keeps growing or a 4th consumer appears, do NOT create a new package** — promote the pure-PHP primitives into the existing framework-agnostic **php-client (C)** and have A and B depend on it.

Because it is kept in sync by hand, **treat every item below as a contract: change one side, change all listed sides in the same PR.**

| Concern | A (backend) | B (laravel-client) | C (php-client) | Sync obligation |
|---------|-------------|--------------------|--------------------|-----------------|
| **Visitor hash** (load-bearing) | `AppHelper::createVisitorHash()` | `SimplestatsClient::createVisitorHash()` | – | Must be **byte-identical**: `hash('sha256', $ip.$userAgent.$time->format('Y-m-d').config('app.key'))`, `substr(…,0,32)`. A regenerates it as a server-side fallback when a client omits it, so any divergence silently splits visitors. |
| **Bot classification** | `BotDetectionService::isBot()` | `CheckTracking::isBot()` | – | Same DeviceDetector sequence + headless-UA match + `!isBrowser() && !isMobileApp()`. Comments are copy-pasted. B adds request-header checks (Sec-Fetch/prefetch) A cannot do; A adds datacenter-IP/VPN B cannot do. |
| **DeviceDetector cache** | `app/Cache/DeviceDetectorCache.php` | `src/Cache/DeviceDetectorCache.php` | – | Near-identical fail-open `CacheInterface`. B is store-configurable (default `'file'`, must dodge the customer's often-`database` default that overflows on the 1.7 MB blob); A uses the app default store. |
| **Host extraction** | `HasStatsTracking::normalizeHost()` / `isOwnProjectDomain()` | `CheckTracking::extractHost()` / `isOwnDomain()` | `TrackingData::extractHost()` / `isOwnDomain()` | Strip scheme → bare host → drop leading `www.` → exact compare (subdomains kept). All three carry the "kept in sync" comment. |
| **Time format** (implicit contract) | `date_format:Y-m-d H:i:s P` rule + `->tz('UTC')` in `BaseStatsValidator` | `SimplestatsClient::TIME_FORMAT` + `->tz('UTC')` | same `TIME_FORMAT` | Clients emit UTC in `Y-m-d H:i:s P`; A hard-validates that literal. No comment ties them — the most fragile link. |
| **IP resolution / CIDR** | – (receives `ip` in payload) | `CheckTracking::resolveIp/normalizeIp/isPublicIp/ipInCidrRange` | `TrackingData::resolveIp/isPublicIp` | B is a superset of C (header priority `CF-Connecting-IP → True-Client-IP → X-Forwarded-For → X-Real-IP`, first public IP). The `FILTER_FLAG_NO_PRIV_RANGE \| FILTER_FLAG_NO_RES_RANGE` idiom also recurs in A. |
| **`SubscriptionInterval`** | `app/Enums/SubscriptionInterval.php` | `src/Enums/SubscriptionInterval.php` | – | Backing **string values** (`month`/`year`) must match; A validates `Rule::enum(...)` against what B sends. |
| **Payload key contract** | `HasStatsTrackingValidation` + `Stats*Validator` | `SimplestatsClient::trackVisitor/trackUser/…` | client payloads | Field names (`visitor_hash`, `track_source`, `page_entry`, …) are an unversioned wire contract across the API boundary. |
| **tracking_codes config** | `config/simplestats-client.php` (app's own dogfood copy) | `config/simplestats-client.php` (package default) | – | See also "Keep Client Config in Sync (Three Places)". |

**Known current drifts (fix opportunistically, don't let them grow):**
- **Host extraction:** B's `extractHost()` does **not** lowercase the host, while A/C do. Mixed-case referers can compare differently.
- **tracking_codes `source`:** the app copy still lists `['utm_source', 'ref', 'referer', 'referrer']`; the package (correct current design) is `['utm_source', 'ref']` (URL `?referer=` params are intentionally no longer tracked). The app dogfood copy is stale.

## KPIs Provided

The dashboard displays these key performance indicators:

| KPI | Description |
|-----|-------------|
| VISITORS | Unique visitors |
| REG | New registrations |
| CR | Conversion rate (visitors to registrations) |
| NET/GROSS | Revenue metrics |
| ARPU | Average Revenue Per User |
| ARPV | Average Revenue Per Visitor |
| ARPPU | Average Revenue Per Paying User |
| DAU/WAU/MAU | Active users (daily/weekly/monthly) |
| DAPU/WAPU/MAPU | Active paying users |
| REG2PU | Registration to paying user conversion |

## Multi-Tenancy

### Team Structure

- Teams can have multiple projects
- Each project has its own API token
- Team members have roles: Administrator, Editor, Viewer
- Stats can be viewed per-project or aggregated at team level

### Project Access (Members Limited to Selected Projects)

An administrator can limit an Editor or Viewer to selected projects (typically a client of an agency team). The owner and Administrators are never limited.

- **Storage:** `team_user.project_ids` (and `team_invitations.project_ids`, copied on accept): jsonb, `NULL` = all projects, an array of internal project ids = limited. `UserBase::accessibleProjectIds($team)` is the single source of truth (null = unrestricted, memoized per team on the user instance); `AppHelper::accessibleProjectIds()` resolves it for the authenticated user and current team. API/MCP auth is a `Project`, so it resolves to null there.
- **Eloquent:** `HasProjectAccessScope` (global scope `project_access`) filters `Project` (by `id`) and the project-owned models (`BaseStat`, `Campaign`, `DataImport`, custom event/property names and values, by `project_id`). Any new model with a `project_id` that is read in the web app needs it too. **Never** put it on `StatsAggregation`: invalidation must keep deleting the team-wide `project_id IS NULL` rows when a limited editor touches a project.
- **Raw SQL:** `StatsFilterConditionsBuilder` intersects `projectCondition` with the accessible ids (`IN (...)` for "all", `AND FALSE` for a foreign project). Raw stats queries that bypass the builder (e.g. `AdSpendService::cohortRevenueByUtm()`) must apply `AppHelper::accessibleProjectIds()` themselves.
- **Cache:** a limited member's "all" is never stored (`storeAggregatedData()` returns early) and gets its own `makeHash()`. Otherwise `StatsIncrementService` would keep incrementing those `project_id IS NULL` rows with every project's stats. The dashboard therefore shows limited members per-project views only (no "All" entry, `loadTeamStats()` / `StatsFilterRequest`).
- **Notifications:** report and alert recipients are the `user_notifications` rows, so `CreateUserNotification` skips projects the user cannot access, and `SyncTeamMemberNotifications` (on `TeamMemberUpdated`) adds/removes rows when access changes. Deleting a project prunes its id from all selections (`ProjectObserver::deleted`).
- **Policies:** limited members cannot create projects, and `ProjectPolicy` / `CampaignPolicy` / `AdSpendPolicy` check `canAccessProject()`. The team page only shows a limited member themselves (no other members, no invitations).
- Member management (`TeamMemberController`, `TeamMemberAccessRequest`, `UpdateTeamMember`) and invitation accept (`TeamInvitationController`, overriding the Jetstream route) resolve projects within the managed team via `withoutGlobalScopes()`, because it is not necessarily the admin's current team.

### The Team Owner Can Be Soft-Deleted (`billingOwner` vs `owner`)

`app:check-inactive-users` **soft-deletes** inactive owners, and `app:purge-pending-deleted-users` only hard-deletes them (cascading into teams, projects and stats) **two months later**. Throughout that grace window the team, its projects and its API tokens keep working normally, but `Team::owner()` is a plain `belongsTo(User::class)` and `User` uses `SoftDeletes`, so **`$team->owner` is `null` while the account is fully alive**. Never assume a live team has a non-null `owner`.

This is not a theoretical edge case: it took down every API request of an affected team for the entire grace window, because the `api` rate limiter resolved the plan through `$project->team->owner->sparkPlan()` (SIMPLESTATS-7F).

Rules:

- **Plan / limit / option lookups use `Team::billingOwner`**, never `owner`. It is the same relation `->withTrashed()`, so it keeps resolving during the grace window and a restored account never loses its plan. Being a real relation (not a helper method), it is memoized and eager-loadable.
- **Anything that touches the owner outside the web session must be null-safe anyway** and degrade instead of throwing. The API rate limiter falls back to `RouteServiceProvider::API_RATE_LIMIT_DEFAULT`; a scheduled command skips the row (see the `empty($team->owner)` guards in `ResetEventsCounter`, `UnifyTrackReferer`, ...). A 500 in a rate limiter or a queued job is always worse than a degraded value.
- The **web session path** is covered separately by the `EnsureValidTeamOwner` middleware, which switches the user to a team that still has an owner. That is why `ProjectPolicy` / `DataImportPolicy` / `HandleInertiaRequests` may use `currentTeam->owner` directly. Do not copy that pattern into API, console or queue code.
- The same dangling-reference class affects **any row pointing at a user**, not just teams (e.g. `UserNotification::user` in `SendProjectReports`). When a relation targets `User`, ask whether a soft-deleted user must still resolve.

### Deleting an Owner Deletes Their Whole Team

Deleting a user is never scoped to that user: `DeleteUser::deleteTeams()` runs `Team::purge()` on every team they own, which detaches all members and deletes the team, taking its projects and stats with it. Only the owner is ever warned by `CheckInactiveUsers`, so an owner who left the company would silently take a team down that colleagues use daily.

`CheckInactiveUsers::inactiveCandidates()` therefore excludes owners whose team still has a member that logged in within `INACTIVE_AFTER_DAYS`. Keep that guard in place, and apply the same reasoning to anything else that deletes a user.

A soft-deleted user is blocked from logging back in (`FortifyServiceProvider`), and the password broker cannot find them either. They are told so explicitly, but **only once they have proven ownership with the correct password** — the generic "credentials do not match" would otherwise read as a forgotten password and lose a still restorable account, while an unconditional message would be an account enumeration oracle. Keep both halves of that condition when touching the login closure.

Ongoing traffic is deliberately **not** part of that check. Paying customers are already excluded by the active-subscription clause, so what is left is free accounts, and a free account that ignores both warning mails is exactly the abandoned installation the cleanup is meant to remove, no matter how much data its client package still sends. Do not reintroduce an `events_count_monthly` based exemption here.

### Team Switching Redirect

`CurrentTeamController::update()` handles the redirect after a team switch. URLs with team-scoped ids must not redirect back to the same path, because the id belongs to the old team. Current rules (ids are public uuids, legacy numeric segments are matched too):

- `/teams/{uuid}...` redirects to `/teams/{newTeamUuid}...`
- `/setup/...` redirects to `RouteServiceProvider::HOME`
- `/{entity}/{uuid-or-numeric-id}...` (default) strips the id segment and redirects to `/{entity}`; non-id segments like `/dashboard/metric/{kpi}` stay untouched
- All other paths redirect back unchanged (safe, since `HasTeamScope` re-filters automatically)

### Multi-Currency

- Each team selects a default currency
- Foreign currency transactions are auto-converted using daily exchange rates

### Team Context Resolution

The app uses `AppHelper::getTeamId()` as the single source of truth for resolving the current team ID. It checks two sources in order:

1. `Context::get('team_id')` (set by `SetTeamContext` middleware via `AppHelper::setTeamContextFromAuth()`)
2. `auth()->user()?->team_id ?? auth()->user()?->current_team_id` (fallback for CLI/job contexts)

This matters because the app runs in two different contexts:

- **HTTP requests**: The `SetTeamContext` middleware sets `Context::add('team_id', ...)` from the authenticated user. Both Context and auth are available.
- **CLI / Queued jobs**: No middleware runs. Code that needs auth must call `auth()->setUser(...)` manually. Context is only available if explicitly set.

#### Rules for Team Context

- **Reading team ID**: Always use `AppHelper::getTeamId()`. Never call `Context::get('team_id')` directly.
- **In jobs/commands that need team-scoped data**: Call `auth()->setUser($user)` before any team-scoped operations. This enables both `AppHelper::getTeamId()` (via auth fallback) and `AppHelper::ensureTeamTimezone()` (which requires `auth()->user()->team->timezone`).
- **When the target team differs from the user's `current_team_id`**: Always set `Context::add('team_id', $targetTeamId)` BEFORE `auth()->setUser($user)`. Relying on the auth fallback (`current_team_id`) is unsafe whenever a user belongs to multiple teams: the user may have switched their selected team in the frontend, so `current_team_id` is not guaranteed to match the team owning the data being processed (e.g. an invited collaborator on someone else's team). Required in: scheduled reports, queued data imports, alert notifications, and anywhere a user is acting on data of a team they did not create. Without this, `HasTeamScope` filters by the wrong `team_id` and queries silently return empty results. Reference fixes: `ProjectReportNotification::toMail()`, `ProcessDataImportJob::handle()`.
- **`HasTeamScope` trait**: Uses `AppHelper::getTeamId()` for global scopes and saving hooks. When team ID is null, the scope is skipped (no filtering).
- **`AppHelper::ensureTeamTimezone()`**: Relies on `auth()->user()->team->timezone`. Only called when a team is resolved, so auth must be set beforehand.

#### Team Timezone (`ensureTeamTimezone`)

Dashboards aggregate in the team's local timezone, so `AppHelper::ensureTeamTimezone()` switches the timezone **globally** for the running process (`Config::set('app.timezone', ...)` plus `date_default_timezone_set(...)`). It is triggered from `HasTeamScope` (global scope and saving hooks), which means touching almost any team-scoped model can flip the default timezone mid-request, and in queue workers the switched timezone persists into everything that runs afterwards.

Consequences when working with datetimes:

- `now()` and parsing of timezone-naive datetime strings are context-dependent. Never assume the default timezone is UTC.
- The stats `time` columns are stored timezone-naive. When a stored time must be compared or written independently of team context (e.g. the out-of-order guard in `HasCustomProperties::recordCustomProperties()`), store and compare explicitly in UTC: write via `$time->utc()->format('Y-m-d H:i:s')`, read via `CarbonImmutable::parse($value, 'UTC')`.
- Do **not** add Eloquent `datetime` casts to these naive time columns. The cast re-parses the stored string in the *currently active* default timezone, so the same row reads as a different instant depending on which team context ran last (this caused an exactly-one-timezone-offset bug in the custom properties time guard).
- Incoming API `time` values are normalised to UTC by `BaseStatsValidator::prepareForValidation()` before they reach jobs.

#### Key files

- `App\Helpers\AppHelper` - `getTeamId()`, `setTeamContextFromAuth()`, `ensureTeamTimezone()`
- `App\Http\Middleware\SetTeamContext` - HTTP middleware that sets Context from auth
- `App\Concerns\HasTeamScope` - Eloquent trait for automatic team scoping

## Queue Configuration

The client package uses Laravel's defer helper (Laravel 11+) or queues:

```php
'queue' => env('SIMPLESTATS_QUEUE', 'default'),
```

Jobs include automatic retry logic for fault tolerance.

## Security Considerations

- API tokens must be kept secret
- IPs and User-Agents are processed but **never stored**
- Visitor hashes are anonymized (daily salt rotation)
- GDPR-compliant by design

### Passkeys (Fortify + `laravel/passkeys`)

Passkeys use Fortify's built-in feature (`Features::passkeys()`), backed by `laravel/passkeys` (a Fortify dependency, 0.x) and the `@laravel/passkeys` npm client. Chosen over `spatie/laravel-passkeys` because it ships the Fortify routes, throttling and password confirmation via passkey. Three app-side deviations from the package defaults, keep them:

- **Hex user handle:** `UserBase::getPasskeyUserHandle()` returns a hex HMAC instead of the package's raw binary one, because iOS Safari corrupts binary handles (laravel/passkeys-server#41). The handle is stored with every passkey: **never change it**, or all registered passkeys stop working.
- **404 binding:** the vendor `destroy` controller type-hints the package model (numeric id, 403 for foreign rows), so `RouteServiceProvider` binds `{passkey}` explicitly by uuid, scoped to the authenticated user. `App\Models\Passkey` adds `HasPublicUuid` and hides `id`/`credential`.
- **Soft-deleted users:** passkey logins bypass `Fortify::authenticateUsing()`, so `Passkeys::authorizeLoginUsing()` in `FortifyServiceProvider` repeats the soft-delete block (a verified passkey proves ownership, so the deactivation notice is shown). `Passkey::user()` is `withTrashed()` for that reason.

The table comes from our own migration (no vendor publish, no `down()`). Passkey management is only shown to users with a password (`socialstream.hasPassword`), because it sits behind `password.confirm`.

### Public UUIDs Instead of Internal IDs

Auto-increment ids are **internal only**. Anything a user can see (URLs, query-string filters, Inertia props / JSON payloads) uses a public uuid instead: customers read numeric ids as a security problem and they leak business intel (row counts, growth, enumeration).

**The design is deliberately hybrid, do NOT swap primary keys to uuid/ULID.** Bigint PKs and all FKs stay internal: `team_id`/`project_id` lead nearly every composite index on the huge stats tables, so 16-byte keys would permanently bloat every index, and ordered uuids (v7/ULID) would leak creation timestamps while random v4 PKs would wreck insert locality. The public key is a separate `uuid` column (native Postgres type, NOT NULL, unique) generated as **random v4** by `App\Concerns\HasPublicUuid`.

Checklist for making a model user-facing:

1. Migration: add `uuid` (nullable → backfill `gen_random_uuid()` → not null + unique).
2. `use HasPublicUuid;` on the model (uuid becomes the route key; non-uuid URL values 404 automatically).
3. `$hidden = ['id', ...internal FK columns]`.
4. Frontend: pass model objects to `route()` (Ziggy resolves the uuid via the `@routes` bindings). Column selects (`->get(['id', 'name'])`) and hand-built row arrays must include `uuid` wherever the frontend needs a reference.
5. Request bodies referencing the model send the uuid; map it back in the FormRequest's `prepareForValidation()` via `AppHelper::resolvePublicId()` (accepts legacy numeric values as-is).

Boundary rule: everything behind the request boundary (validation rules, DTOs, `StatsService` aggregation cache / `filter_hash`) keeps working on internal ids. `StatsService::formatFilters()` converts ids back to uuids toward the frontend; `teamStats.projects` is keyed by uuid (the "All" pseudo entry at key `0`). User-settings keys written per project (`selectedGroupTypes`, `propertyLifetime`) are uuid-keyed.

Gotchas:

- Bulk inserts (`Model::insert()`, `DB::table()->insert()`) skip model events, so the uuid must be set manually (see `CampaignSeeder::monthlySpendRows`).
- `$hidden` applies to `toArray()` serialization even for explicitly selected columns. Hand-built arrays and attribute access bypass it — that is how the AI/MCP tools keep their numeric `project_id` contract.
- Jetstream's vendor controllers `findOrFail()` the raw `{team}` parameter, which cannot resolve a uuid route key (`findOrFail($model)` is no workaround, its Arrayable cast breaks). All `{team}` routes are therefore overridden in `routes/web.php` with app controllers (`TeamController`, `TeamMemberController`) mirroring the vendor actions.
- The curated `auth.user` payload lives in `App\Http\Middleware\ShareInertiaData` (container-bound over the vendor class). Never share full user/team models to Inertia; whitelist fields.

Deliberate exceptions where numeric ids stay: the stats dimension filters (`track_referer`, `location_country`, ...), the `id` inside `linkedTrackCampaigns` (feeds the numeric `track_campaign` dimension link), AI tool responses, `preset=custom_<id>`, and the Filament admin (`/admin/users/{record}` resolves by primary key, admin-only). Users themselves are uuid-routed too (the `{user}` param of the Jetstream member routes binds `App\Models\User` by uuid).

Legacy compatibility: the `RedirectLegacyIdUrls` middleware 301-redirects old numeric GET urls (`/projects/5`) to the uuid url, team-scoped so foreign ids still 404. Numeric filter/body values keep working through `resolvePublicId()`.

## Coding Conventions

### Helpers

- Prefer Laravel helpers (`Str::`, `Arr::`, `collect()`, etc.) over native PHP functions where a Laravel equivalent exists.

### No Magic Strings for App Identity

- Never hardcode "SimpleStats" or similar brand names. Use `config('app.name')` instead so values stay consistent across environments and rebranding is painless.

### Enums for Fixed Value Sets

- When a feature has a fixed set of types/states (e.g., feedback types, import types, notification frequencies), define a PHP `enum` in `app/Enums/` backed by `string`.
- Use `LaracraftTech\LaravelUsefulAdditions\Traits\UsefulEnums` trait.
- Add a `title(): string` method for display labels and a `static toOptions(): array` method returning `[['id' => ..., 'name' => ...], ...]` for frontend select inputs.
- Use `Rule::enum(MyEnum::class)` (via `Illuminate\Validation\Rule`) in FormRequest validation instead of `new Enum()` or `Rule::in([...])`. This provides a fluent API with `->only()` and `->except()`.
- See `app/Enums/UserNotificationFrequency.php` as a reference implementation.

### Confirmation Dialogs

- Never use the browser's native `confirm()` dialog. Always use the `ConfirmationModal` component (`@/Components/ConfirmationModal.vue`) for delete or destructive action confirmations.

### Tooltips

- For hover tooltips always use the `v-tooltip` directive (floating-vue, registered globally in `resources/js/app.js`). Never use the native HTML `title` attribute for tooltips.
- Static text: `v-tooltip="'Label'"` (note the inner quotes, the directive takes a JS expression). Dynamic value: `v-tooltip="expr"`. With HTML/placement: `v-tooltip.left="{ content, html: true }"` (see `StatsFilter.vue` / `Components/InfoTooltip.vue`).
- The directive works on plain elements and on components (it attaches to the component's root element, e.g. `SecondaryButton`).
- **`v-tooltip` is not a full replacement for `title`'s accessibility role.** A tooltip is a *visual* hint only: floating-vue does not give the element an accessible name (it only adds a transient `aria-describedby` while open). The native `title` weakly did, so blindly swapping `title` → `v-tooltip` on an icon-only control silently removes its name for screen readers.
  - Element already has a **visible text** label (accessible name present) → `v-tooltip` alone is fine. No `aria-label`, no `title`.
  - **Icon-only** control (button/link whose content is only an SVG icon) → add `aria-label="…"` for accessibility, plus `v-tooltip` for the visual hint when a hover hint is wanted. Think of `aria-label` as the real replacement for `title`'s name role, and `v-tooltip` as the separate visual layer.
- **Never set `title` and `v-tooltip` on the same element** — the browser shows the native tooltip on top of the floating one (double tooltip).
- **Explanatory tooltips (full sentences) always use the shared InfoTooltip look:** a width-capped body (`max-w-64`) with an optional green title line. Never bind a long plain string directly to `v-tooltip`; without the width cap a multi-sentence tooltip spans the whole screen. Plain short labels (a few words, e.g. `v-tooltip="'Manage ad spend'"`) stay as plain strings. Two ways to get the look:
  - Standalone info icon next to a label: use `Components/InfoTooltip.vue` (`:title` green heading, `:text` body, optional `:html`), see the table headers in `Pages/Campaigns/Index.vue`.
  - Tooltip attached to an existing element (e.g. a sortable column header): build the content with `infoTooltipContent({title, text})` from `resources/js/Helpers/TooltipHelpers.js` and bind `v-tooltip="{content, html: true}"`, see the retention column headers in `StatsGroupTable.vue`. `InfoTooltip.vue` uses the same helper, so both anchors render identically.
- Before adding a tooltip at all, ask whether it earns its place: skip it when the icon is a universal convention (trash = delete, refresh = reload) or the meaning is otherwise obvious. Prefer a visible label or a dropdown header over a permanent hover-only hint where it fits.
- `title` may still be used where it is not a tooltip: genuine accessibility/semantic attributes (e.g. an `<iframe title>`), `title` props of custom components, and the `<Head :title>` page title.

### Icons

- Always use **Bootstrap Icons** (the `bootstrap-icons` package), imported as SVG components via `vite-svg-loader`. Import the icon SVG with the `?component` query and render it like any Vue component: `import ChartPieIcon from 'bootstrap-icons/icons/pie-chart.svg?component';` then `<ChartPieIcon class="size-6" />`. Never hand-write an inline `<svg>` path for a UI icon, and do not build custom/own icon SVGs when a Bootstrap Icon fits.
- Browse available icons at https://icons.getbootstrap.com or under `node_modules/bootstrap-icons/icons/*.svg`; the file name (kebab-case, e.g. `pie-chart`, `check-circle-fill`) is the import path. Many icons ship both an outline and a `-fill` variant: use the plain name for outline/standard UI and the `-fill` variant for filled/solid accents. Match whatever sibling components already use.
- Sizing and color work via Tailwind utilities exactly as before (`size-6`, `h-5 w-5`, `text-indigo-500`), because the SVGs carry `fill="currentColor"` and a `viewBox`. No `font-size` workaround is needed.
- For dynamic icons, store the imported component references in a map/array and render with `<component :is="...">` (e.g. `const roleIcons = { admin: KeyIcon, editor: PencilIcon };`), the same pattern as before.
- Exceptions that stay as custom SVG: the brand/app mark (`ApplicationIcon` / `ApplicationMark`), the Laravel logo (`CustomIcons/LaravelIcon`), the social-provider logos (`SocialstreamIcons/*`), loading spinners, and genuinely bespoke illustrations (e.g. decorative gradients) that have no Bootstrap Icon equivalent.

### Carbon / Dates

- Always use `CarbonImmutable` instead of mutable `Carbon`. The app registers `Date::use(CarbonImmutable::class)` globally, so `now()` and `Date::parse()` already return immutable instances. When importing Carbon directly, use `use Carbon\CarbonImmutable;` instead of `use Carbon\Carbon;`. Mutable Carbon causes subtle bugs when objects are passed to methods that call mutating operations like `startOfWeek()`, `endOfDay()`, etc.

### Translations (i18n)

- Only public (non-authenticated) pages are translated: Landing Page, Imprint, Auth pages. These use `$t()` in Vue templates and keys in `lang/{locale}.json`.
- In-app pages (behind auth) are English-only. Do not wrap in-app strings with `$t()` or add them to language files.
- Translations are managed via `php artisan app:translate` which uses DeepL. See `app/Console/Commands/TranslateLanguageFiles.php`.

### Config Files Must Be Serializable

- Never put closures (or object instances) into files under `config/`. Production deploys run `php artisan config:cache` (via `optimize`), which serializes the whole config with `var_export()`. Closures fail with `Call to undefined method Closure::__set_state()` and break the deployment, while it works fine locally where the config is not cached.
- When a config value needs callable behavior (e.g. Sentry's `before_send`), reference a static method as an array callable instead: `'before_send' => [\App\Sentry\BeforeSend::class, 'handle']`. An array of strings is serializable and Sentry accepts it via `is_callable()`. See `App\Sentry\BeforeSend`.
- After changing config that holds dynamic values, verify with `php artisan config:cache` (then `php artisan config:clear`) before considering the change done.

### Imports

- Always use `use` imports at the top of the file instead of inline fully-qualified class names (e.g., `use App\Models\User;` instead of `\App\Models\User::query()`).

### Comments

- Only write comments that add real value. A comment must explain a non-obvious **why**, a gotcha, or critical context that the code itself cannot convey.
- Never write comments that merely restate what the code does (e.g. `// loop over users` above a `foreach`). Delete them on sight, including in code you are editing.
- Do not add PHPDoc blocks just to describe a method/property whose name and signature already say everything. Skip boilerplate `@param`/`@return` that only repeats the type hints. PHPDoc is still expected where it carries extra information (array shapes, generics, thrown exceptions).
- When in doubt, leave the comment out. The bar is "would a competent reader be confused or surprised without it?"

### Controllers & Business Logic

- Controllers should be thin. Keep business logic out of controllers and move it into dedicated service classes (e.g., `App\Services\TrackingService`).
- Controllers should only handle HTTP concerns: receiving the request, delegating to a service, and returning a response.
- **Controllers must not contain private helper methods.** A private method on a controller is a sign that business logic leaked in. Controller methods should be public route actions only. Extract the helper into a service (for domain/query logic) or a `DataProvider` (for response assembly, see `App\Http\DataProviders\StatsIndexDataProvider`). Same rule for non-action `protected` helpers.
- **Controllers stay RESTful and resourceful.** Keep all actions for one entity in that entity's own controller and prefer the standard resource actions (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`). Non-standard actions are allowed when they don't fit a resource verb (e.g. `CampaignController::linkTrackCampaign`), but they must live in the entity's own controller, never bolted onto an unrelated one. Do **not** spread an entity's actions across multiple controllers (e.g. `index`/`show` on `StatsController` while `store` lives on `CampaignController`). Keeping them together is also what lets `authorizeResource()` map every action to the entity's policy automatically.

## Development Guidelines

### Git Workflow

- Do NOT stage changes automatically after making edits
- Do NOT ask if commits should be created - the user will commit when ready
- Only commit when explicitly requested by the user

### Local Browser Login

- On the local login page (`https://simplestats.test/login`) email and password are prefilled with the seeded test user. Just click "Sign in", do not type into the fields (that appends to the prefilled values).

### When Working on the Main App

- Prioritize query performance for high-volume data
- Test with large datasets
- Consider database partitioning for time-series data
- Ensure API responses are fast (async processing via queues)

### When Working on the Client Package

- Maintain backwards compatibility with Laravel 8-12
- Keep package lightweight (minimal dependencies)
- Never block customer requests - use defer/queues
- Graceful degradation when API is unreachable

## Testing

- Use factories for creating test data
- Test with realistic data volumes
- Verify queue job handling
- Test API rate limiting behavior
- Ensure tracking works across Laravel versions

## Announcements

Announcement drafts live in `notes/announcements/*.md` and are sent via the Filament Announcements page (`App\Mail\AnnouncementMail`). The mail already opens with "Hey {name}," (`resources/views/emails/announcement.blade.php`) and the title is sent as the mail subject. Therefore:

- No `#` heading at the top of the body: it would duplicate the subject and read awkwardly right after the greeting. Put the subject into an HTML comment on the first line instead (`<!-- Subject: ... -->`).
- The first sentence must continue naturally after "Hey {name}," (e.g. "good news: you can now ..."). `##` section headings further down are fine.

## Blog

- Blog posts are managed as markdown files with YAML front matter in `resources/markdown/blog/`, using the `jcfrane/laravel-md-blog` package.
- Posts are version-controlled in Git and require no database. The `MdBlog` facade provides querying (all, find, latest, whereTag, etc.).
- Author information is stored as `author_id` in the front matter `meta` field and resolved to a User model in the controller.

## Common Tasks

### Adding New KPIs

1. Define the calculation logic
2. Add to aggregation queries
3. Update dashboard components
4. Document in introduction.md

### Modifying API Endpoints

1. Consider backwards compatibility
2. Version new endpoints if breaking changes
3. Update `docs/ingest-api.md` (and `docs/query-api.md` for read endpoints)
4. Update client package if needed

### Client Package Updates

1. Test across all supported Laravel versions
2. Update composer.json version constraints
3. Document changes in CHANGELOG
4. Tag release on GitHub

**Never move or force-push a published tag** in any package repo. Packagist treats published stable versions as immutable: once it has read a tag, a changed reference is blocked and the version keeps pointing at the old commit. Fix forward with a new version instead.

### Releasing the Statamic Addon

The Statamic addon (`packages/simplestats-io/statamic-addon`) is the one package with a build step: its Control Panel assets (`resources/dist`) are gitignored on `main` and must be part of the tagged commit, otherwise customers install a version without JS/CSS. **Never release it with `gh release create`** (that tags plain `main`, without the dist). Release it through its `release.yml` workflow:

1. `gh workflow run release.yml -R simplestats-io/statamic-addon -f version=vX.Y.Z`: builds the assets, commits them on a detached commit, pushes the tag **once** on that commit and creates a **draft** release with generated notes.
2. Review the draft, then publish it yourself: `gh release edit vX.Y.Z -R simplestats-io/statamic-addon --notes-file notes.md --draft=false`. Publishing by hand matters: a release created by the workflow's `GITHUB_TOKEN` does not trigger `update-changelog.yml`, a release published with your own token does.

Why: the old `build-dist-on-tag.yml` built the dist after the tag push and then force-moved the tag onto the dist commit. Packagist had already read the original tag, so v1.2.0 was published without assets and the moved tag was rejected (fixed with v1.2.1, v1.2.0 deleted on Packagist). laravel-client and filament-plugin have no build step and are released normally.

### Modifying Subscription Plans

When changing plan features or limits in `config/spark.php`:

1. Update the plan configuration in `config/spark.php`
2. Update `docs/plans-and-limits.md` to reflect the changes
3. Update this guidelines file if rate limits change
