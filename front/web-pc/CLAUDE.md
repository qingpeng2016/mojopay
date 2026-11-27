# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack & framework

- **Framework**: ThinkPHP 6 + ThinkAdmin (`zoujingli/think-plugs-admin`) with `topthink/think-multi-app` (auto multi-app mode). PHP ≥ 8.0.
- **Web entry**: `public/index.php` → `RuntimeService::doWebsiteInit()`. URL convention is `controller_suffix=false`, `url_html_suffix=html`, default app `index`. Admin panel is served at `/admin.html`.
- **CLI entry**: `think` (e.g. `php think <command>`) → `RuntimeService::doConsoleInit()`.
- **Frontend**: Server-rendered templates extending `app/admin/view/main`, layui-based UI, ECharts loaded from CDN on the PNL page.
- **Admin URLs are hash-routed SPA**: backend pages are reached at `/admin.html#/<app>/<controller_snake>/<action>.html` (e.g. `/admin.html#/rmpd/market_pair/index.html`). When constructing `paginate()` the `path` option must hardcode this hash URL — otherwise pagination links break out of the SPA.

## Common commands

```bash
# Install/update dependencies
composer install                                  # local dev
composer update --profile --prefer-dist --no-dev --optimize-autoloader   # prod (build.cmd)

# Local PHP dev server (alternative to Docker)
php -S localhost:8080 public/router.php

# Console commands (registered in config/console.php)
php think resolve:market         # ResolveMarket
php think resolve:sports         # ResolveSports
php think outcome:login          # OutcomeLogin
php think outcomes:login         # OutcomesLogin
php think fetch:outcomes         # FetchOutcomes
php think fetch:mmapi            # FetchMMApi

# Database migrations (Phinx, via think-library)
php think migrate:run
php think migrate:create <Name>

# Docker local (preferred for onboarding — see docs/LOCAL_SETUP.md)
docker build -f docker/Dockerfile --build-arg APP_ENV=local -t fg-marketmaker-mis:local .
docker run -d --name fg-mis-local -p 8080:80 \
  -v "${PWD}/conf:/var/www/html/conf" \
  -v "${PWD}/runtime:/var/www/html/runtime" \
  fg-marketmaker-mis:local
```

There is no test suite in this repo. Verify changes by running the dev server and exercising the affected admin pages.

## Multi-app architecture

Each subdir of `app/` is an isolated ThinkPHP "app". `app/service.php` registers the boot services that ThinkAdmin auto-discovers:

- `app/admin/` — ThinkAdmin core backend (auth, menu, users, config, oplog). Not modified for product work.
- `app/rmpd/` — **the primary product module** (Polymarket arbitrage strategy review / PNL / risk). See `app/rmpd/docs/TECH.md` and `PRD.md` for the full data model and per-controller spec — read TECH.md before changing anything in this module.
- `app/api/`, `app/index/` — public/API endpoints (light).
- `app/cool_pay/`, `app/cool_pay2/`, `app/cool_pay3/` — payment plugins.
- `app/wechat/` — WeChat integration.
- `app/command/` — console command implementations (NOT a runnable app; just a namespace for classes referenced from `config/console.php`).
- `extend/` is on the PSR-0 autoload path; `extend/think-admin-support-middleware/` is registered as PSR-4.

## Environment & database configuration (critical)

`config/database.php` is dynamic — it determines the environment, loads the right YAML, and overrides values from env vars. **Do not "fix" or simplify this file without understanding the precedence rules**:

1. **Environment selection**: `APP_ENV` env var (if set) → otherwise the git branch read from `.git/HEAD` → defaults to `local`. Branch names are mapped via `normalizeEnvironment()` (e.g. `main`/`master`/`production`/`prod` → `prod`; `develop`/`dev`/`development` → `develop`; everything else → `local`).
2. **YAML config loaded**: `conf/database-{env}.yaml`. The `local` file is gitignored (template at `conf/database-local.yaml.template`); `develop` and `prod` files have empty credentials and rely on env vars.
3. **Env-var overrides (non-local only)**: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — applied across `mysql`, `db_pay2`, `db_pay3`, `db_rmpd`, and `db_bitfinex` **except**: `db_bitfinex` can use optional **`DB_USERNAME_BITFINEX` / `DB_PASSWORD_BITFINEX`** when the strategy DB login differs from the main `DB_USERNAME`/`DB_PASSWORD`. `db_pay3` database name comes from `DB_DATABASE_UAT_SELF_MM` (default `fgmm_self_mm`); `db_rmpd` from `DB_DATABASE_RMPD` (default `fgmm_strategy_rmpd`). `db_bitfinex` database name from `DB_DATABASE_BITFINEX` (default `fgmm_strategy_bitfinex`). `db_fgmm_runtime` keeps its schema name in YAML; host/port/default credentials come from env; optional **`DB_USERNAME_FGMM_RUNTIME` / `DB_PASSWORD_FGMM_RUNTIME`** independently per-field if needed, otherwise uses `DB_USERNAME`/`DB_PASSWORD`.
4. **Debug logging**: every request writes the resolved config to `runtime/log/database_config_YYYYMMDD.log` (and `error_log` with prefix `[DB-Config]`). When debugging connection issues, read this log first — it is the source of truth for which YAML and env vars were used.

**MySQL connections** in `conf/database-*.yaml` include: `mysql` (default ThinkAdmin main), `db_pay2`, `db_pay3`, `db_rmpd`, `db_bitfinex`, and `db_fgmm_runtime`. The RMPd module overrides default to `db_rmpd` via `app/rmpd/config/database.php`; Bitfinex overrides default to `db_bitfinex` via `app/bitfinex/config/database.php`. Module controllers typically still call `Db::connect('…')` explicitly. When adding cross-database queries, always pick the connection explicitly.

## RMPD module conventions

The RMPD module is under active development and has stricter conventions than the rest of the codebase:

- **Controller base class & auth**: every controller in `app/rmpd/controller/` extends `think\admin\Controller` and pulls in `use SafeQueryTrait;` at the class top. Each action carries PHPDoc annotations — `@auth true` on every action (admin login required); add `@menu true` only on the index action that should be the menu entry. Write actions like `approve` / `reject` only get `@auth true`.
- **Unified single+batch action shape**: write actions accept `ids` (comma-separated string from POST) or a single `id` (from GET) — `MarketPair::resolveIds()` is the reference helper. Do NOT create separate single-record endpoints; single record is just `ids` of length 1.
- **JSON responses**: `$this->error('msg')` / `$this->success('msg')` produce the ThinkAdmin JSON envelope the frontend expects (code=0/1).
- **Menu registration is SQL, not UI**: after adding a controller, edit `scripts/rmpd_menu_init.sql` (idempotent — designed to be re-run):
  1. Add the new `node` to the `Step 1` `DELETE FROM system_menu WHERE node IN (...)` list
  2. Add an INSERT row in `Step 4` for the submenu (node = `rmpd/<x>/index`, icon = a `layui-icon ...` class, larger `sort` = higher in menu)
  3. Add the index node AND each write-action node (e.g. `rmpd/<x>/approve`) to both `Step 5` cleanup list and `Step 6` `system_auth_node` insert
  After running the SQL, clear backend cache (`php think clear` — note the framework command is `clear`, not `xadmin:clear`) and hard-refresh the browser. Note: the menu/permission tables live in the **main `mysql` connection**, not `db_rmpd`.
- **Input sanitization**: every user-supplied filter goes through `app\rmpd\trait\SafeQueryTrait` (`sanitizeLike`, `sanitizeId`, `sanitizeEnum`, `sanitizeDateRange`) before being used in a query. Do not interpolate request values into `where()` calls directly.
- **Enums**: status/dependency/action values live in `app\rmpd\const\Enum` with `getAllMpStatuses()` / `getAllDepTypes()` / `getAllArbStatuses()` helpers used as `sanitizeEnum` whitelists.
- **Concurrency-safe writes**: review approve/reject uses `WHERE ... AND status='review'` so concurrent operators can't double-process a row; the response message reports `affected` vs `count(ids)` so users see skipped rows.
- **Full-table-scan guards**: list endpoints with no filter (`arb_record`, `ai_call_log`) inject a default `created_at >= NOW() - INTERVAL 30 DAY`.
- **risk_config is read-only** in this admin — changes are made directly in the DB. Don't add write endpoints without explicit ask.
- **View layout**: every page extends `../../admin/view/main`, uses blocks `button` / `content` / `script`, and pulls search forms via `{include file="index_search"}`. Pagination uses ThinkPHP's `paginate()` with `$pagehtml|raw`.

## Python scripts (scripts/)

`scripts/` contains Python utilities invoked by the PHP console commands (e.g. `market_resolver.py`, `mmapi.py`, `slug_resolver_6.py`, `sports_resolver.py`). The Docker image installs `python3` + `python3-requests` + `python3-dateutil` so these run inside the container. `setup_cron.sh` configures the scheduled runs. The SQL files (`market_maker_menu_full.sql`, `rmpd_menu_init.sql`) initialize ThinkAdmin menu rows for the modules.

## Deployment

- `docker/Dockerfile` builds a single image running both Nginx and PHP-FPM under supervisord. `APP_ENV` is the only required build/runtime arg in `local`; `develop`/`prod` additionally require the five `DB_*` env vars.
- `deployment.yaml`, `service.yaml`, `ingress.yaml` are sample K8s manifests; see `K8S_DEPLOYMENT.md` for the recommended Secret-based credential pattern.
- `runtime/` must be writable (mode 777 in the image). The Dockerfile does not copy `vendor/` separately — `composer install --no-dev --optimize-autoloader` runs inside the build.

## Reference docs in the repo

- `docs/LOCAL_SETUP.md` — step-by-step onboarding (Docker + manual PHP paths)
- `DOCKER_DEPLOY.md`, `K8S_DEPLOYMENT.md`, `SECURITY.md` — deployment & credential handling
- `app/rmpd/docs/TECH.md` — authoritative RMPD module spec (tables, columns, controllers, view variables, enum colors). Treat this as the source of truth when touching `app/rmpd/`.
- `app/rmpd/docs/PRD.md` — product requirements behind RMPD