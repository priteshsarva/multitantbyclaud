# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Workspace layout

This directory is a workspace holding three parts of one product ("Server Products" / SPP), not a single package:

| Path | What it is |
|---|---|
| `UltimateScrapperV2/` | The Node/Express backend — scraper engine + multi-tenant product API + client portal API. Own git repo (`github.com/priteshsarva/UltimateScrapperV2`, branch `main`). |
| `portal-app-clean/` | React + Vite SPA for the portal (client + admin UI). Talks to the backend over HTTP; not in git. |
| `server-products-4.0.1.zip` | The WooCommerce plugin (PHP) that client stores install. It is the main consumer of `/product/sync-feed`. Unzip to a temp dir to read it; the source is not checked in here. |

Only `UltimateScrapperV2/` is under version control. Changes to the other two are not tracked.

## Commands

Backend (`UltimateScrapperV2/`) — no build, no test runner, no linter. `npm test` is a stub that exits 1.

```bash
node index.js
```
Starts the real server. **Port 3002 is hardcoded** in `index.js` (behind Cloudflare); `PORT` in `.env` is ignored.

```bash
node portal/createAdmin.js
```
Seeds the single admin from `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

```bash
bash smoke-test.sh
```
End-to-end API check (auth → sources → key status → sync-feed → domain lock → plans → invoices). Set `CLIENT_PASS`, `ADMIN_EMAIL`, `ADMIN_PASS`, `KEY` as env vars first. Read-only; it does not approve or charge. This is the closest thing to a test suite — run it after touching auth, enrollment, or sync-feed.

Portal SPA (`portal-app-clean/`):

```bash
npm run dev
```
Vite dev server on port 5174. `VITE_API_URL` (default `http://localhost:3002`) points it at the backend. `npm run build` / `npm run preview` also exist.

Postgres schema lives in `UltimateScrapperV2/db/portal-schema.sql` plus incremental files in `portal/*.sql` (`billing_engine.sql`, `enrollment_sources.sql`, `source_categories.sql`). There is no migration tool — apply them by hand in the Supabase SQL editor.

## Architecture

### Two datastores, deliberately split

- **SQLite, one file per category** (`databases/shoes.db`, `databases/watches.db`) — the scraped product catalogue. `models/dbManager.js` lazily opens a connection per category, auto-creates the schema (`PRODUCTS` + `CATEGORIES`/`BRAND`/`SIZES`/`TAGS` and their junction tables) on first open, and runs WAL mode. Because each `.db` is independent, `productId` is only unique *within* a category — every API that returns a product also carries `dbName`/`cat`, and callers must pass it back.
- **Postgres (Supabase), via `portal/db.js`** — people, access, and money: `users`, `sources`, `enrollments`, `enrollment_sources`, `category_map`, `scrape_requests`, `invoices`, `audit_log`. Never holds scraped products.

`sources` in Postgres is the live scrape registry and supersedes the static `config/sites.js` `SITES_REGISTRY`. The scraper resolves sources from Postgres (`portal/sources.js`), so an admin can approve a new source without a deploy. `SITES_REGISTRY` is still imported by legacy paths (`routes/devRoutes.js`, `utils/multiDbHandler.js`, `server.js`) — treat it as a fallback, not the source of truth.

### Two authentication paths coexist

1. **Legacy, origin-based.** `middleware/tenantIdentify.js` string-matches the `Origin`/`Referer`/`Host` header against hardcoded names and attaches `CLIENT_CONFIGS[domain]` (from `config/clients.js`) as `req.clientConfig`, which lists the databases that client may read. Used by `/product/*` browsing routes and `/dev/*`. Unmatched origins silently fall through to a default config.
2. **Current, key-based.** `portal/enrollmentKey.js` → `requireEnrollmentKey` validates the `x-enrollment-key` header (a `spp_live_…` token) against `enrollments`, enforces status/expiry and the `x-site-domain` lock, then attaches `req.enrollment` with every attached source, its category allow-list, and the store's canonical category map. This is what the WordPress plugin uses.

`tenantIdentify` short-circuits (`next()`) when `x-enrollment-key` is present, so the two paths can share a mount point.

### Route mounting order matters

`routes/productRoutes.js` ends in a `GET /:id` catch-all. Anything else under `/product` (`portal/statusRoute.js`, `portal/productRefreshRoute.js`) **must** be mounted before it in `index.js`, or it will be swallowed. `/product/status` deliberately does *not* use `requireEnrollmentKey` — the plugin must be able to read its own expiry while expired.

### Scrape pipeline

`portal/scrapeQueue.js` is a `p-queue` with **concurrency 1** — every scrape (rotator and on-demand) goes through it so two runs never write the same SQLite file. It stamps `last_scraped_at` on completion, which is how the rotator advances: `nextSourceToScrape()` just picks the least-recently-scraped active source.

`core/scraperManager.js` dispatches on `source.method` to one of two Puppeteer strategies in `core/strategies/`:
- `methodA.js` / `methodB.js` — full-catalogue crawls for different storefront layouts (cartpe vs jdwebnship templates, lazy-loaded Next.js images, out-of-stock states).
- `liveMethodA.js` / `LiveMethodB.js` — single-product re-scrapes for the live refresh routes. They merge scraped fields over the existing SQLite row so an out-of-stock page can't null out a product.

Both use `puppeteer-extra` + the stealth plugin. `PUPPETEER_EXECUTABLE_PATH` must point at Chrome on Linux deploys. The manager closes the category DB after each run to release the WAL lock.

### Product delivery

- `GET /product/sync-feed` (keyed) is the plugin's pull endpoint and the most load-bearing route in the codebase. Keyset pagination by `productId` or `productLastUpdated`; `?category=` selects which `.db` to read; the WHERE clause is built per-source from `productFetchedFrom LIKE searchKey` plus that source's category allow-list. Rows are tagged with `dbName` and their `catName` rewritten through the store's `category_map`. Backward compatible: no `category` means the first source's category.
- `GET /product/search`, `/firstdata`, `/:id` (origin-based) serve the older React storefronts through `utils/multiDbHandler.js`, which fans a single SQL string across every DB in `req.clientConfig.access` and interleaves the results.
- `core/wpBulkSafeSync.js` pushes products *into* WooCommerce over the REST API for the older direct-sync sites listed in `WP_SITES` (keyed by domain, must match `CLIENT_CONFIGS`). All bulk operations are chunked (~10 items with a ~1.5s delay) specifically to avoid 502/504s from the WordPress host. Note `services/wpBulkSafeSync.js` is an older, smaller copy — `routes/devRoutes.js` and the scraper strategies both import the `core/` one; prefer it.

### Portal business flow

Signup → create enrollment for a domain (issues a key, `pending`) → admin approves → activate (sets `expiry_date` one month out) → plugin syncs with the key. `portal/scheduler.js` arms a daily 08:00 `node-cron` job that issues renewal invoices, emails reminders, and expires unpaid enrollments; it can also be triggered via `POST /portal/admin/shops/run-billing-tick`. Payments go through Pay0 (`portal/pay0.js`).

## Things that will surprise you

- **`index.js` is the server; `server.js` is not.** `server.js` is an abandoned "Phase 4" API on port 3000 that reads `SITES_REGISTRY` directly. Don't edit it expecting production behaviour.
- **The server git-commits its own databases.** `/updateserver` and `/devproductupdates` run `git add . && git commit && git push` from inside the request handler, checkpointing the WAL first. That's why the history is full of `DB updated on …` commits and why `.db` files are tracked. Be careful with anything that changes what's in the working tree on the deployed box.
- **`/devproductupdates` iterates `SITES_REGISTRY` but calls `runRotator()` each pass**, which ignores the loop variable and picks from Postgres. The effect is "run N rotator ticks", not "scrape these N sites".
- `config/clients.js`, `config/sites.js`, and `WP_SITES` must agree on domain keys and source ids for routing to work; a mismatch fails silently by filtering everything out.
- Category and size values from scrapers are wildly inconsistent. `routes/productRoutes.js` carries large hand-maintained `categoriesMap` / `sizeMap` tables that expand one clean filter value into 40+ `LIKE` variants. Add new scraper spellings there, not in the frontend.
- Several files are dead or superseded: `controller/` (old scraper), `routes/product - Old without any sort by productupdatetime.js`, `portal/sync-feed-handler.js` (a copy-paste snippet of the live route, not wired up), `models/connect.js`, `wpProduct.js`, `wpTest.js`, `migrate_data.js`.
- ES modules throughout (`"type": "module"`) — use `import`, and recreate `__dirname` via `fileURLToPath` where needed.

## Secrets

Real credentials are committed in `UltimateScrapperV2/portal/.env.example` (Supabase URL, JWT secret, admin password) and `.env` is not gitignored — the repo's `.gitignore` only covers `node_modules` and `controller/images`. Don't add more, and don't echo these values into new files.
