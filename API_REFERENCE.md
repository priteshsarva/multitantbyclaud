# Server API Reference — UltimateScrapperV2

The single Express app in [`index.js`](index.js) (port **3002**, hardcoded, behind Cloudflare). This document lists **every mounted endpoint**, its auth model, what it's for, its inputs, and its output — so new features can be built on a known surface.

> Generated from the route files, not guessed. If you add/rename a route, update this file.

---

## 1. Authentication models

There are **four** ways a request is authorized. Each endpoint below is tagged with one.

| Tag | Mechanism | Header(s) | Who uses it | Middleware |
|---|---|---|---|---|
| 🔓 **Public** | none | — | signup/login pages, the plugin's status read, gateway callbacks | — |
| 🟦 **Client JWT** | Bearer token from login/signup | `Authorization: Bearer <jwt>` | the portal SPA (logged-in client) | `requireAuth` |
| 🟥 **Admin JWT** | Bearer token of an admin user | `Authorization: Bearer <jwt>` | the portal SPA (admin) | `requireAuth, requireAdmin` |
| 🔑 **Enrollment key** | per-site `spp_live_…` token | `x-enrollment-key`, `x-site-domain` | the **WordPress plugin** | `requireEnrollmentKey` |
| 🌐 **Origin (legacy)** | `Origin`/`Referer`/`Host` match | (browser sends automatically) | old React storefronts, `/dev` | `tenantIdentify` |

Notes:
- `requireEnrollmentKey` validates the key against `enrollments`, enforces status/expiry and the `x-site-domain` lock, and attaches `req.enrollment = { id, userId, domain, sources[], catMaps }`.
- `tenantIdentify` **short-circuits** (calls `next()`) if `x-enrollment-key` is present, so origin and key paths can share the `/product` mount.
- JWT payload: `req.user = { sub: userId, role, email }`.

---

## 2. Auth & account — `/auth/*`  🔓 / 🟦

Source: [`portal/authRoutes.js`](portal/authRoutes.js)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/auth/signup` | 🔓 | Register a client **and** their first shop (pending approval); emails a welcome. |
| POST | `/auth/login` | 🔓 | Exchange email+password for a JWT. |
| GET | `/auth/plans` | 🔓 | Active plans for the signup plan-picker (needed before login). |
| GET | `/auth/me` | 🟦 | The current user record. |

**POST `/auth/signup`** body:
```json
{ "email":"", "password":"", "name":"", "mobile":"", "shop_url":"",
  "plan_id":"", "social_urls":[], "whatsapp_number":"", "whatsapp_community_url":"" }
```
→ `{ token, user, shop }`. Errors: `409` email/domain taken, `400` missing email/password/shop_url/plan_id/invalid plan.

**POST `/auth/login`** `{ email, password }` → `{ token, user }`. `401` bad creds, `403` suspended.

---

## 3. Client portal — `/portal/*`  🟦

The logged-in client (store owner). All require a client JWT.

### 3.1 Enrollments / sites — [`portal/enrollmentRoutes.js`](portal/enrollmentRoutes.js)
| Method | Path | Purpose | Body |
|---|---|---|---|
| GET | `/portal/enrollments` | My sites (with key, status, expiry). | — |
| POST | `/portal/enrollments` | Create a site → issues a key, status `pending`. | `{ domain, source_id }` |
| PATCH | `/portal/enrollments/:id/categories` | Set the category allow-list for a site. | `{ categories: [] }` |

### 3.2 Extra shops — [`portal/clientShopRoutes.js`](portal/clientShopRoutes.js)
| Method | Path | Purpose | Body |
|---|---|---|---|
| POST | `/portal/shops` | Add another shop (own key, pending). | `{ shop_url, plan_id }` |

### 3.3 Sources attached to a site (multi-source) — [`portal/enrollmentSourceRoutes.js`](portal/enrollmentSourceRoutes.js)
| Method | Path | Purpose | Body |
|---|---|---|---|
| GET | `/portal/enrollments/:id/sources` | Sources feeding this site. | — |
| POST | `/portal/enrollments/:id/sources` | Attach another source (cross-category allowed). | `{ source_id, categories? }` |
| PATCH | `/portal/enrollments/:id/sources/:sourceId` | Change that source's category list. | `{ categories }` |
| DELETE | `/portal/enrollments/:id/sources/:sourceId` | Detach a source. | — |

### 3.4 Category map (per store) — [`portal/categoryMapRoutes.js`](portal/categoryMapRoutes.js)
| Method | Path | Purpose | Body |
|---|---|---|---|
| GET | `/portal/enrollments/:id/category-map` | Attached sources + their categories + current canonical names. | — |
| POST | `/portal/enrollments/:id/category-map` | Rename/group categories the plugin will show. | `{ mappings:[{source_id,cat_name,canonical}] }` |

### 3.5 Scrape requests (ask for a new site) — [`portal/scrapeRequestRoutes.js`](portal/scrapeRequestRoutes.js)
| Method | Path | Purpose | Body |
|---|---|---|---|
| POST | `/portal/scrape-requests` | Request a new source be scraped. | `{ site_url, category, enrollment_id? }` |
| GET | `/portal/scrape-requests` | My requests + their status. | — |

### 3.6 Source category browse — [`portal/categoryRoutes.js`](portal/categoryRoutes.js) (client router, mounted at `/portal/sources`)
| Method | Path | Purpose |
|---|---|---|
| GET | `/portal/sources` | List sources (client view). |
| GET | `/portal/sources/:id/categories` | Categories discovered for a source (for the picker). |

### 3.7 Plans — [`portal/plansRoutes.js`](portal/plansRoutes.js) (client router at `/portal/plans`)
| Method | Path | Purpose |
|---|---|---|
| GET | `/portal/plans` | Active plans for pricing/picker. |

### 3.8 Billing / invoices — [`portal/paymentRoutes.js`](portal/paymentRoutes.js)
| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/portal/invoices` | 🟦 | My invoices. |
| POST | `/portal/invoices/:id/pay` | 🟦 | Create a gateway order → returns `{ payment_url }` to redirect to. |
| GET | `/portal/invoices/:id/verify` | 🟦 | Poll after returning from the gateway; finalizes + emails receipt if paid. |
| GET | `/portal/pay/callback` | 🔓 | Gateway redirect target; verifies then bounces to `APP_URL/billing?paid=<id>`. |

---

## 4. Admin portal — `/portal/admin/*`  🟥

Requires an admin JWT.

### 4.1 Approval queue (scrape requests) — [`portal/scrapeRequestRoutes.js`](portal/scrapeRequestRoutes.js) (admin router)
| Method | Path | Purpose | Body |
|---|---|---|---|
| GET | `/portal/admin/scrape-requests?status=pending` | The queue. | — |
| POST | `/portal/admin/scrape-requests/:id/approve` | Create the source, scrape its category list, auto-attach to the requesting store. | `{ source_id, name, method, base_url, search_key }` |
| POST | `/portal/admin/scrape-requests/:id/reject` | Reject a request. | — |
| POST | `/portal/admin/scrape-requests/:id/resolve` | Mark a request resolved. | — |

### 4.2 Sources registry — [`portal/sourceRoutes.js`](portal/sourceRoutes.js)
| Method | Path | Purpose | Body |
|---|---|---|---|
| GET | `/portal/admin/sources?status=active` | List sources. | — |
| POST | `/portal/admin/sources` | Create/upsert a source. | `{ id, name, category, method, base_url, search_key }` |
| PATCH | `/portal/admin/sources/:id` | Edit any field, or pause/activate. | `{ …fields, status?:'paused'|'active' }` |

### 4.3 Source categories (admin) — [`portal/categoryRoutes.js`](portal/categoryRoutes.js) (admin router at `/portal/admin/sources`)
| Method | Path | Purpose | Body |
|---|---|---|---|
| POST | `/portal/admin/sources/preview` | Ad-hoc: scrape a raw URL's category list (no source needed). | `{ url, method? }` |
| GET | `/portal/admin/sources/:id/categories` | Stored categories for a source. | — |
| PATCH | `/portal/admin/sources/:id/categories` | Edit a source's category list. | `{ categories }` |
| POST | `/portal/admin/sources/:id/categories/refresh` | Re-scrape the category list. | — |

### 4.4 Enrollments (admin) — [`portal/adminRoutes.js`](portal/adminRoutes.js) + [`portal/adminEnrollmentOverview.js`](portal/adminEnrollmentOverview.js)
| Method | Path | Purpose |
|---|---|---|
| GET | `/portal/admin/enrollments` | All enrollments. |
| POST | `/portal/admin/enrollments/:id/approve` | Approve a pending enrollment. |
| POST | `/portal/admin/enrollments/:id/reject` | Reject one. |
| POST | `/portal/admin/enrollments/:id/activate` | Activate → sets `expiry_date` +1 month. |
| GET | `/portal/admin/enrollment-overview` | Rich overview: domain, owner, status, days-left, attached sources. |

### 4.5 Shops lifecycle & billing — [`portal/shopAdminRoutes.js`](portal/shopAdminRoutes.js)
| Method | Path | Purpose | Body |
|---|---|---|---|
| PATCH | `/portal/admin/shops/:id/plan` | Assign/change a shop's plan. | `{ plan_id }` |
| POST | `/portal/admin/shops/:id/approve` | Approve → generate first invoice → email owner. | — |
| POST | `/portal/admin/shops/run-billing-tick` | Manually run the daily billing job. | — |

### 4.6 Plans (admin) — [`portal/plansRoutes.js`](portal/plansRoutes.js) (admin router)
| Method | Path | Purpose | Body |
|---|---|---|---|
| GET | `/portal/admin/plans` | All plans (incl. inactive). | — |
| POST | `/portal/admin/plans` | Create a plan/tier. | `{ name, price, … }` |
| PATCH | `/portal/admin/plans/:id` | Edit a plan. | any fields |

### 4.7 Clients directory — [`portal/adminUsersRoute.js`](portal/adminUsersRoute.js)
| Method | Path | Purpose |
|---|---|---|
| GET | `/portal/admin/users?q=` | Every client with all signup info (mobile/whatsapp/socials) + shop count + paid_total + unpaid count. |

### 4.8 Domain verification — [`portal/domainVerifyRoutes.js`](portal/domainVerifyRoutes.js)
| Method | Path | Purpose |
|---|---|---|
| POST | `/portal/admin/enrollments/:id/verify-domain` | Server fetches `https://<domain>/wp-json/spp/v1/verify` and confirms the key-hash → spoof-proof domain proof. |

### 4.9 Settings — SMTP + Payment gateway — [`portal/settingsRoutes.js`](portal/settingsRoutes.js)
| Method | Path | Purpose | Body |
|---|---|---|---|
| GET | `/portal/admin/settings/smtp` | SMTP config (password masked). | — |
| PUT | `/portal/admin/settings/smtp` | Save SMTP (blank/masked pass = keep). | `{ host, port, secure, user, pass, from }` |
| POST | `/portal/admin/settings/smtp/test` | Send a test email. | `{ to? }` |
| GET | `/portal/admin/settings/payment` | Whole **provider registry**, keys masked, which is active. | — |
| PUT | `/portal/admin/settings/payment/provider/:id` | Save one gateway's fields (blank/masked secrets kept). | `{ label, title, description, base_url, webhook_url, enabled, api_key, secret }` |
| PUT | `/portal/admin/settings/payment/active` | Choose the live gateway. | `{ id }` |

### 4.10 Public payment info — [`portal/settingsRoutes.js`](portal/settingsRoutes.js) (public router)
| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/portal/payment-info` | 🔓 | Non-secret: `{ enabled, provider, title, description }` for the active gateway. Safe for the SPA/plugin. |

> The `/preview` body is `{ url, method? }` where `method` is `METHOD_A` \| `METHOD_B` (auto-detected from the URL when omitted).

---

## 5. Plugin-facing (keyed) — `/product/*`  🔑

Consumed by the WordPress plugin. All need `x-enrollment-key` (+ `x-site-domain`), except where noted.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/product/sync-feed` | 🔑 | **The main product pull.** Keyset-paginated catalogue for this site. |
| GET | `/product/status` | 🔑\* | Site status/expiry/days-left + attached sources. *Reads even while expired (no hard block).* |
| POST | `/product/renew-demo` | 🔑 | DEMO: extend expiry +1 month, reactivate. |
| GET | `/product/refresh-one` | 🔑 | Re-scrape a single product (background); returns immediately. |
| GET | `/product/pay-config` | 🔑 | Live gateway name/title + this site's due invoice (no secrets). |
| POST | `/product/pay-start` | 🔑 | Server builds a pay link for the due invoice with the active gateway → `{ pay_url }`. |

**GET `/product/sync-feed`** query params ([`routes/productRoutes.js:266`](routes/productRoutes.js)):
| Param | Default | Meaning |
|---|---|---|
| `by` | `id` | Cursor column: `id` (productId) or `ts` (productLastUpdated, epoch-ms). |
| `after` | `0` | Keyset cursor — return rows after this value. |
| `limit` | `100` (max 200) | Page size. |
| `category` | first source's category | Which `.db` file to read (each category = its own SQLite DB). |
| `stock` | any | `in` \| `out` \| (omit = both). |
| `updated_days` | `0` | Only rows updated within N days. |
| `created_days` | `0` | Only rows created within N days. |

Response: `{ by, after, count, results:[ …product rows tagged with dbName, catName rewritten via the store's category_map ] }`. The WHERE is built per source from `productFetchedFrom LIKE searchKey` + that source's category allow-list.

**GET `/product/status`** → `{ status, remote_status, domain_ok, registered_domain, expiry_date, renewal_date, days_left, sources[] }`.

---

## 6. Legacy storefront (origin-based) — `/product/*`  🌐

Older React storefronts via [`routes/productRoutes.js`](routes/productRoutes.js) + `utils/multiDbHandler.js` (fans one SQL across every DB in `req.clientConfig.access`). Reached only when **no** `x-enrollment-key` is sent.

| Method | Path | Purpose |
|---|---|---|
| GET | `/product/search` | Search across the client's allowed DBs. |
| GET | `/product/allresults` | Bulk listing. |
| GET | `/product/firstdata` | Initial storefront payload. |
| GET | `/product/:id` | Single product (**catch-all** — must stay mounted last). |

> ⚠️ `/product/:id` is a catch-all. Any new `/product/...` route MUST be mounted before `productRoutes` in `index.js` (that's why `productRefreshRoute`, `pluginPayRoutes`, `statusRoute` are mounted first).

---

## 7. Dev / ops — `/dev/*` and top-level  🌐 / 🔓

Maintenance endpoints — hit manually or by cron. Origin-based (`tenantIdentify`) for `/dev`; top-level ones are open.

Source: [`routes/devRoutes.js`](routes/devRoutes.js), [`index.js`](index.js)

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Health check → `{ status:200, server:"Runnnig" }`. |
| GET | `/updateserver` | Checkpoints WAL, then `git add/commit/push` the server's DBs. |
| GET | `/devproductupdates` | Runs N rotator ticks (picks least-recently-scraped source from Postgres). |
| GET | `/dev/update-stale-sizes` | Backfill/repair size data. |
| GET | `/dev/getProductBydetails` | Lookup a product by details (debug). |
| GET | `/dev/update-single-product` | Re-sync one product to WooCommerce. |
| GET | `/dev/retry-failed-syncs` | Retry failed WP pushes. |
| GET | `/dev/clean-old-oos-products` | Remove stale out-of-stock rows. |
| GET | `/dev/outofstock5days` | Mark items OOS for 5+ days. |
| GET | `/dev/checkpoint` | SQLite WAL checkpoint. |
| GET | `/dev/bulkSafeSyncProducts` | Chunked bulk push into WooCommerce (`core/wpBulkSafeSync.js`). |
| GET | `/dev/bulkProductOutOfStock` | Chunked bulk OOS update in WooCommerce. |

---

## 8. Background jobs (not HTTP)

Not endpoints, but part of the runtime surface:
- **Scrape queue** — [`portal/scrapeQueue.js`](portal/scrapeQueue.js): `p-queue` concurrency **1**; stamps `last_scraped_at`; rotator advances by least-recently-scraped.
- **Billing scheduler** — [`portal/scheduler.js`](portal/scheduler.js): daily 08:00 `node-cron` → issues renewals, emails reminders, expires unpaid. Also reachable via `POST /portal/admin/shops/run-billing-tick`.

---

## 9. Not mounted / legacy (do not build on)

- [`routes/routes.js`](routes/routes.js) — `/allresults`, `/add`, `/update`, `/delete`: **not imported** in `index.js`. Dead.
- [`portal/sync-feed-handler.js`](portal/sync-feed-handler.js) — a copy of the sync-feed route, **not wired up**. The live one is `routes/productRoutes.js:266`.
- [`server.js`](server.js) — abandoned "Phase 4" API on port 3000. Not production.
- `config/sites.js` `SITES_REGISTRY` — superseded by the Postgres `sources` table; still imported by some legacy paths as a fallback.

---

## 10. Quick auth cheat-sheet for new work

- Building **portal (client) UI** → 🟦 `Authorization: Bearer <jwt>`, routes under `/portal/*`.
- Building **admin UI** → 🟥 same header (admin user), routes under `/portal/admin/*`.
- Building **plugin features** → 🔑 `x-enrollment-key` + `x-site-domain`, routes under `/product/*`, mounted **before** `/product/:id`.
- Anything the plugin/public reads without login → 🔓 keep it secret-free (see `/portal/payment-info`, `/product/status`).
