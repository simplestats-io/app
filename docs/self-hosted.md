# Overview (Self-hosted)

_**SimpleStats**_ is open source. The code is available on [GitHub](https://github.com/simplestats-io/app) under the [AGPL-3.0 license](https://github.com/simplestats-io/app/blob/main/LICENSE), and technical teams can run it on their own infrastructure.

## SaaS or Self‑hosted?

Both run the same code with the same features. The difference is who takes care of running it.

| | SaaS | Self‑hosted |
|---|---|---|
| **Setup** | Sign up and add your API token, done in minutes | Server, PHP, PostgreSQL, Redis/queue workers, scheduler, web server and TLS |
| **Updates** | Automatic, new features as soon as they ship | You update to each tagged release and deploy it yourself |
| **Backups** | Daily, handled by us | Your responsibility |
| **Uptime & scaling** | Monitored and scaled by us | Your responsibility, including queue sizing under load |
| **Security** | Patches applied by us | You keep PHP, the OS and all dependencies up to date |
| **Support** | Dedicated support by the team | Community support via [GitHub issues](https://github.com/simplestats-io/app/issues) |
| **Cost** | Free plan, paid plans by monthly events | Free license, you pay for and run the infrastructure |

For most teams, including larger organizations, the **SaaS version** is the better fit: it's the fastest way to reliable numbers, your data is hosted in the EU, and you can focus on your product instead of your analytics servers. If you need full control over your infrastructure and have the experience to run a production Laravel application, you can self-host SimpleStats instead.

## Installation

SimpleStats is a regular Laravel application. The easiest way to run it is the official Docker image; if you prefer a classic server setup, follow the installation steps in the [README](https://github.com/simplestats-io/app#manual-installation).

### Docker (recommended)

The image `ghcr.io/simplestats-io/app` contains everything the app needs (PHP, extensions, built assets). The [docker-compose.yml](https://github.com/simplestats-io/app/blob/main/docker-compose.yml) starts the web app, Horizon, the scheduler, PostgreSQL and Redis. Database migrations run automatically on every start.

1. Download the compose file, the env template and the Caddyfile:

```bash
mkdir simplestats && cd simplestats
curl -fsSLO https://raw.githubusercontent.com/simplestats-io/app/main/docker-compose.yml
curl -fsSL -o .env https://raw.githubusercontent.com/simplestats-io/app/main/.env.docker.example
mkdir docker && curl -fsSL -o docker/Caddyfile https://raw.githubusercontent.com/simplestats-io/app/main/docker/Caddyfile
```

2. Fill in `.env`. Only `APP_URL`, `APP_KEY`, `DB_PASSWORD` and the mail settings are required. Generate the key once and never change it afterwards:

```bash
echo "base64:$(openssl rand -base64 32)"
```

3. Start the stack. Either let the bundled [Caddy](https://caddyserver.com) handle HTTPS (set `DOMAIN` in `.env`, ports 80 and 443 must be free):

```bash
docker compose --profile https up -d
```

or run it behind your own reverse proxy (Traefik, nginx, ...), which forwards to `127.0.0.1:8080`:

```bash
docker compose up -d
```

4. Create the first user (it becomes the admin):

```bash
docker compose exec app php artisan app:create-new-user "Jane Doe" jane@example.com "a-secure-password"
```

All other settings from [.env.example](https://github.com/simplestats-io/app/blob/main/.env.example) (backups, AI assistant, GeoIP, Horizon tuning, ...) can be added to the same `.env`, it is passed to every container. The data lives in Docker volumes: `pgdata` (database), `storage` (uploads, imports, local backups) and `redisdata`.

To use an external PostgreSQL or Redis, set `DB_HOST` / `REDIS_HOST` in `.env` and drop the bundled service in a `docker-compose.override.yml`.

## Updating

Self-hosted installations should always run a tagged [release](https://github.com/simplestats-io/app/releases). The `main` branch is the rolling state of the hosted version and can change at any time.

Releases follow [semantic versioning](https://semver.org):

- **Major** (`7.0.0`): you have to act before or after updating, e.g. a new required environment variable or a raised PHP or PostgreSQL requirement. The release notes describe what to do.
- **Minor** (`6.1.0`): new features, may include database migrations.
- **Patch** (`6.0.1`): bug fixes.

To update, read the release notes of every version between yours and the target.

**Docker:** pin the release in `.env` (e.g. `SIMPLESTATS_VERSION=6`, which follows all 6.x releases), then pull and restart. Migrations run automatically:

```bash
docker compose pull
docker compose up -d
```

**Classic setup:**

```bash
git fetch --tags
git checkout v6.1.0

composer install --no-dev --optimize-autoloader
npm ci
npm run build

php artisan migrate --force
php artisan optimize --except=views
php artisan queue:restart # or: php artisan horizon:terminate
```

## Requirements

_**SimpleStats**_ Self-hosted requires:

- **PHP:** 8.3 or higher
- **Laravel:** 12 or higher
- **Database:** PostgreSQL 14 or higher
- **PHP Extensions:** ext-pdo, ext‑zip
- **Node.js** 21.6 (lower versions might work)

Plus the default [Laravel requirements](https://laravel.com/docs/12.x/deployment#server-requirements). With Docker, you only need Docker Engine with the Compose plugin.

Behind a reverse proxy, set `TRUSTED_PROXIES` (`*` or the proxy IPs) so HTTPS and the client IP are detected correctly. The Docker setup does this already.

Passkey sign-in only works over HTTPS, and `APP_URL` must be the exact URL your users open (e.g. `https://stats.example.com`). Passkeys are bound to that domain, so changing it later invalidates all registered passkeys.

## Architecture

SimpleStats self-hosted is a standard Laravel application. The ingest API is intentionally thin: every endpoint validates the payload and immediately dispatches a queued job, the actual database write happens in the queue worker. This separation keeps request latency low and lets you scale ingestion throughput independently of web capacity. Beyond that, deployment is up to you, run it however you run other Laravel apps.

## Horizontal scaling

The ingest API is stateless and follows standard Laravel scaling patterns: multiple web replicas and queue workers behind the same database work out of the box, with no special configuration. Race-safe writes are guaranteed at the database level, so concurrent workers across replicas do not produce duplicates or lost updates.

A data import is uploaded by a web request and read later by a queue worker, so when those run on separate machines or containers, set `FILESYSTEM_DISK` to a disk all of them can reach, for example `s3`.

## Queue workers

All ingest endpoints push a job onto the `ingest` queue. The job code is queue-driver agnostic and works with any driver Laravel supports: Redis, Amazon SQS, database, Beanstalkd, etc. Pick the driver that fits your stack via the standard `QUEUE_CONNECTION` env var.

### With Laravel Horizon (Redis driver)

When running on Redis, we ship a Horizon configuration with three supervisor groups (`ingest`, `default`, `low`). The relevant supervisor settings are configurable via environment variables:

| Variable | Default | Purpose |
|---|---|---|
| `HORIZON_INGEST_MIN_PROCESSES` | `3` | Minimum number of workers always running on the ingest queue. |
| `HORIZON_INGEST_MAX_PROCESSES` | `10` | Maximum number of workers Horizon may scale up to under load. |
| `HORIZON_DEFAULT_MIN_PROCESSES` | `2` | Minimum workers for the default (non-ingest) queue. |
| `HORIZON_DEFAULT_MAX_PROCESSES` | `5` | Maximum workers for the default queue. |
| `HORIZON_LOW_MIN_PROCESSES` | `1` | Minimum workers for the low-priority queue. |
| `HORIZON_LOW_MAX_PROCESSES` | `1` | Maximum workers for the low-priority queue. |

The defaults are a sensible starting point. Right-sizing depends on your expected events per day and your database's write capacity. Set conservative values, watch the Horizon dashboard for wait time on `redis:ingest`, and bump `HORIZON_INGEST_MAX_PROCESSES` if jobs queue up.

### With other drivers (SQS, database, etc.)

Horizon is Redis-only, so the env vars above do not apply when you pick a different driver. You manage worker processes yourself via your platform (Supervisor, systemd, ECS task definitions, Kubernetes deployments, ...) by running `php artisan queue:work <driver> --queue=ingest,default,low` with the desired concurrency.

A few things worth keeping in mind if you go this route:

- The ingest job timeout is 60 seconds with a 90 second retry-after window. Make sure your driver's visibility timeout (for SQS) or job reserve time is at least as long, otherwise jobs may be redelivered while still being processed.
- Scale your worker count based on observed queue depth and job processing latency, the same way you would for any Laravel queue worker.
- The health report leaves the Horizon check out, because there is no Horizon to look at. The queue itself is covered by your driver's own monitoring.

## Backups

In production, the scheduler creates a daily backup of the database and the app directory with [spatie/laravel-backup](https://spatie.be/docs/laravel-backup). Failures are mailed to `ADMIN_MAIL`.

- By default, backups are stored on the server only (`storage/app`). To keep a copy off the server, configure the `s3` disk (`S3_*` variables) and set `BACKUP_DISKS=local,s3`.
- The archive contains your `.env`. To encrypt it with AES-256, set `BACKUP_ARCHIVE_PASSWORD` and keep the password somewhere outside the server, otherwise the backups are useless when the server is lost.
- `BACKUP_ENABLED=false` stops the scheduler creating and cleaning up backups.

On a platform that replaces the local disk on every deploy, an archive written to `storage/app` is gone by the next one, so point `BACKUP_DISKS` at a bucket instead, or set `BACKUP_ENABLED=false` if the platform already backs the database up for you.

## Database growth

Stats rows are small (a few hundred bytes including indexes). For typical use cases PostgreSQL handles tens of millions of rows on these tables without trouble on commodity hardware.

## Integration in non-classic Laravel setups

If you run a headless SPA in front of your Laravel API, or use stateless auth (JWT, Bearer tokens, Sanctum personal access tokens), the frontend needs to forward UTMs to the API explicitly. See [Headless & SPA Backends](/headless-stateless-spa).

## License

_**SimpleStats**_ is free and open source software, licensed under the [GNU Affero General Public License v3.0](https://github.com/simplestats-io/app/blob/main/LICENSE). You can use it for free, including commercially. If you modify SimpleStats and make it available to others over a network, you have to publish your changes under the same license.

## Contributing

Bug fixes, features and docs improvements are welcome. See the [contribution guide](https://github.com/simplestats-io/app/blob/main/CONTRIBUTING.md) to get started.
