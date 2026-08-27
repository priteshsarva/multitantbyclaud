# SPP Storefront Platform — Roadmap (zero to hero)

A multi-tenant, themeable e-commerce storefront for Server Products clients.
One hosted app. Every store owner gets their own look, own catalog slice, own
customers, own orders — driven entirely by config.

---

## Hard constraints

1. **`UltimateScrapperV2/` is READ-ONLY.** Not one file is edited. We talk to it
   over HTTP only, as a client, using enrollment keys (`x-enrollment-key` +
   `x-site-domain`) — exactly like the WordPress plugin.
2. **No `ALTER TABLE` on existing Postgres tables.** New tables only. Existing
   tables (`users`, `enrollments`, `enrollment_sources`, `sources`, `invoices`,
   `plans`) are read-only to us. Anything we need to add goes in a side table.
3. **Same Supabase database.** Required for real foreign keys to
   `enrollments(id)` and `users(id)`.
4. **SPP never holds shopper money.** Store owners connect their own payment
   gateway. SPP's Pay0 stays for SPP→owner renewal invoices only.
5. **Implement → test → next.** No task advances until its test is green.

---

## Architecture decisions (locked)

| Decision | Choice | Why |
|---|---|---|
| Deployment | SPP-hosted multi-tenant SPA | one deploy serves all tenants; custom domains via CNAME |
| Tenant resolution | by request domain → `enrollments.domain` / `store_domains` | no build-time config |
| Setup UI | portal (`portal-app-clean`), not storefront | owner already logs in there; storefront stays shopper-only |
| Catalog | **mirrored** into our Postgres by a sync worker | sync-feed can't serve faceted storefront queries |
| Catalog storage | once per `(source_id, external_id)`, shared across tenants | no N× duplication |
| Per-tenant price | `base_price × (1 + markup)` resolved at query time | solves the per-tenant-pricing blocker |
| Shopper accounts | scoped to `enrollment_id` | same email can exist on two different stores |
| Look & feel | **template + theme override** | template = layout/structure, theme = colors/fonts/radius |
| Shopper payments | owner's own gateway | SPP avoids PCI/refund/chargeback liability |
| Renewal enforcement | block checkout only, never browsing | shopper shouldn't be punished for owner's lapsed plan |

---

## Phase map

| # | Phase | Ships | Status |
|---|---|---|---|
| 0 | Backend foundations | schema, config API, catalog mirror, shopper auth, cart/order API | ⬜ in progress |
| 1 | Storefront skeleton + theme engine | boots from config, template #1, live theming | ⬜ |
| 2 | Catalog & search | listing, facets, search, PDP | ⬜ |
| 3 | Cart & checkout (guest) | cart, address, gateway, confirmation | ⬜ |
| 4 | Shopper accounts | signup/login/orders/addresses | ⬜ |
| 5 | Portal setup wizard | owner UI for all config + theme picker | ⬜ |
| 6 | Templates #2 and #3 | prove the template system | ⬜ |
| 7 | Owner order admin | orders, status, dashboard | ⬜ |
| 8 | Renewal enforcement | banner, checkout block, renew flow | ⬜ |
| 9 | Custom domains | CNAME + auto-TLS | ⬜ |
| 10 | Growth features | coupons, reviews, wishlist, SEO, abandoned cart | ⬜ |

---

# PHASE 0 — Backend foundations

**Goal:** every table and endpoint the storefront and portal will need, with nothing
depending on UI. **Done when** the Phase 0 smoke test exits 0.

## 0.1 — Database schema
- [ ] **0.1.1** Draft `db/001_init.sql` — all new tables. Do not apply. ← *current*
- [ ] **0.1.2** Review together; adjust names/types/constraints.
- [ ] **0.1.3** Apply to Supabase.
- [ ] **0.1.4** Seed two test stores on different domains; verify FKs + tenant isolation.
- **Test:** insert/select each table; same email registers on both stores; RLS deny-all holds for the anon key.

## 0.2 — API skeleton
- [ ] **0.2.1** Scaffold `api/` — Express, ES modules, `.env`, Postgres pool, health route.
- [ ] **0.2.2** Domain resolver middleware: request host → enrollment + store_config (60s cache).
- [ ] **0.2.3** Error handler, request logging, CORS for tenant domains.
- [ ] **0.2.4** Config module reading UltimateScrapperV2 base URL from env.
- **Test:** `GET /health` returns 200; unknown domain returns 404 with a clean JSON error.

## 0.3 — Catalog mirror (sync worker)
- [ ] **0.3.1** UltimateScrapperV2 HTTP client: keyset-paginated `/product/sync-feed` pull using a stored enrollment key.
- [ ] **0.3.2** Upsert into `catalog_products` keyed on `(source_id, external_id)`.
- [ ] **0.3.3** Persist cursor in `catalog_sync_state`; resume from last position.
- [ ] **0.3.4** Normalize brand/category/size (reuse the mapping tables' *logic*, re-implemented here — not imported).
- [ ] **0.3.5** Scheduled run (node-cron) + manual trigger endpoint.
- [ ] **0.3.6** Mark rows missing from a full pass as `in_stock=false`, never delete.
- **Test:** run sync against a real source; row count > 0; re-run is idempotent (no dupes, cursor advances); killing mid-run and resuming loses nothing.

## 0.4 — Store config API
- [ ] **0.4.1** `GET /portal/site-config` (owner JWT) — read own store's config.
- [ ] **0.4.2** `PUT /portal/site-config` (owner JWT) — validated upsert; reject unknown keys.
- [ ] **0.4.3** `GET /public/site-config` (public, by domain) — non-secret fields only.
- [ ] **0.4.4** Gateway credential encryption (AES-256-GCM), masked in all reads.
- [ ] **0.4.5** Default config row auto-created on first read.
- **Test:** public endpoint never returns any key matching `*secret*`/`*credential*`; PUT with an unknown key returns 400.

## 0.5 — Per-tenant pricing
- [ ] **0.5.1** `store_source_markup` CRUD (owner JWT).
- [ ] **0.5.2** Price resolver: `base_price × (1 + markup)` + rounding rule from config.
- [ ] **0.5.3** Expose both `price` and `compare_at_price` for strike-through display.
- **Test:** one product, two stores, markups 0% and 30% — prices differ by exactly 30%, rounding respected.

## 0.6 — Public catalog API (what the storefront reads)
- [ ] **0.6.1** `GET /public/products` — pagination, sort, filters (category, brand, price, stock), scoped to the domain's allowed sources+categories.
- [ ] **0.6.2** `GET /public/products/:slug` — single product.
- [ ] **0.6.3** `GET /public/facets` — available brands/categories/price range for the current store.
- [ ] **0.6.4** `GET /public/search?q=` — Postgres full-text with trigram fallback.
- **Test:** store A cannot see a product from a source it isn't enrolled in; facet counts match filtered result counts.

## 0.7 — Shopper auth
- [ ] **0.7.1** `POST /shop/auth/signup` (scoped to domain's enrollment).
- [ ] **0.7.2** `POST /shop/auth/login` → JWT `{ sub, enrollment_id, role:'shopper' }`.
- [ ] **0.7.3** `GET /shop/auth/me`.
- [ ] **0.7.4** Forgot/reset password via email.
- [ ] **0.7.5** `requireShopperAuth` middleware — token's `enrollment_id` must match the resolved domain.
- **Test:** same email signs up on store A and store B (both succeed); A's token rejected with 403 when sent to B.

## 0.8 — Cart & orders
- [ ] **0.8.1** Cart endpoints (server-side, anon token or shopper JWT).
- [ ] **0.8.2** `POST /shop/orders` — snapshots product title/image/price at write time.
- [ ] **0.8.3** Payment start via the owner's gateway → `{ order_id, pay_url }`.
- [ ] **0.8.4** `POST /shop/orders/:id/webhook` — verify signature, mark paid, email receipt.
- [ ] **0.8.5** `GET /shop/orders` + `GET /shop/orders/:id` (shopper or guest token).
- [ ] **0.8.6** `GET /portal/shop/orders` + `PATCH /portal/shop/orders/:id` (owner).
- **Test:** full curl run — signup → cart → order → sandbox webhook → status `paid` + receipt sent. Price is recomputed server-side; a tampered client price is ignored.

## 0.9 — Phase 0 test harness
- [ ] **0.9.1** Write `api/smoke-test.sh` covering 0.2–0.8.
- [ ] **0.9.2** Document required env vars.
- **Test:** `bash api/smoke-test.sh` exits 0 from clean.

---

# PHASE 1 — Storefront skeleton + theme engine

- [ ] **1.1** Scaffold `web/` — Vite + React + TS + Tailwind v4 + React Router.
- [ ] **1.2** Boot sequence: resolve domain → `GET /public/site-config` → render (with skeleton + error state).
- [ ] **1.3** Theme engine: config → CSS custom properties on `:root`.
- [ ] **1.4** Design-token contract (colors, fonts, radius, spacing, shadows) — every template must consume only these.
- [ ] **1.5** Template registry + resolver (`template_id` → layout component set).
- [ ] **1.6** Template #1 "Classic Grid": header, nav, footer, home sections.
- [ ] **1.7** Config-driven homepage sections (order + on/off from `homepage_sections` jsonb).
- [ ] **1.8** Logo/favicon/title/meta injection from config.
- [ ] **1.9** Live theme preview mode (`?preview_config=` for the portal's picker).
- **Test:** two tenants on localhost via hosts-file domains render visibly different stores from the same build, with no rebuild between them.

# PHASE 2 — Catalog & search
- [ ] **2.1** Product listing page + pagination.
- [ ] **2.2** Faceted filter sidebar (category, brand, price, stock, size).
- [ ] **2.3** Sort control.
- [ ] **2.4** Product card (image, title, price, compare-at, badges).
- [ ] **2.5** PDP: gallery, variants/sizes, description, stock, add-to-cart.
- [ ] **2.6** Search with autocomplete.
- [ ] **2.7** Breadcrumbs + category landing pages.
- [ ] **2.8** Empty/loading/error states everywhere.
- [ ] **2.9** Image optimization + lazy loading.
- **Test:** filters and facet counts agree; deep links restore filter state; Lighthouse performance ≥ 85 mobile.

# PHASE 3 — Cart & checkout (guest)
- [ ] **3.1** Cart drawer + cart page; qty, remove, persist.
- [ ] **3.2** Checkout: contact, shipping address, pincode validation.
- [ ] **3.3** Shipping method selection + cost from config.
- [ ] **3.4** Tax/GST calculation from config.
- [ ] **3.5** Order summary + server-side price recompute.
- [ ] **3.6** Gateway integration #1 (Razorpay), owner's credentials.
- [ ] **3.7** COD option (config-gated, min order value).
- [ ] **3.8** Order confirmation page + email.
- [ ] **3.9** Guest order lookup by order number + email.
- **Test:** sandbox payment completes end-to-end; a client-side price edit does not change the charged amount; abandoning at gateway leaves the order `pending_payment`, not `paid`.

# PHASE 4 — Shopper accounts
- [ ] **4.1** Signup / login / logout UI.
- [ ] **4.2** Forgot + reset password.
- [ ] **4.3** Account dashboard.
- [ ] **4.4** Order history + order detail + tracking link.
- [ ] **4.5** Address book CRUD.
- [ ] **4.6** Profile edit.
- [ ] **4.7** Merge guest cart into account on login.
- **Test:** cross-tenant isolation holds in the UI; guest cart survives login without losing items.

# PHASE 5 — Portal setup wizard
- [ ] **5.1** "Storefront" section in `portal-app-clean`.
- [ ] **5.2** Setup wizard: store identity → theme → catalog → payments → shipping → legal → go live.
- [ ] **5.3** Branding form (logo/favicon/name/tagline) with upload.
- [ ] **5.4** Template picker with thumbnails.
- [ ] **5.5** Theme editor (colors, fonts, radius) with live preview iframe.
- [ ] **5.6** Homepage section builder (toggle + reorder).
- [ ] **5.7** Contact + socials form.
- [ ] **5.8** Legal pages editor, pre-filled from templates.
- [ ] **5.9** Payment gateway connect form (masked secrets).
- [ ] **5.10** Shipping + tax settings.
- [ ] **5.11** Markup settings per source.
- [ ] **5.12** SEO settings.
- [ ] **5.13** Completion checklist + "go live" gate.
- **Test:** a brand-new owner reaches a live, visually distinct store without anyone touching code or the database.

# PHASE 6 — Templates #2 and #3
- [ ] **6.1** Template #2 "Editorial" (large hero, story-led).
- [ ] **6.2** Template #3 "Dark Luxury".
- [ ] **6.3** Extract anything duplicated into shared primitives.
- [ ] **6.4** Template preview thumbnails for the picker.
- [ ] **6.5** Document how to add a template.
- **Test:** switching template on a live store changes layout with zero data loss and no rebuild.

# PHASE 7 — Owner order admin
- [ ] **7.1** Orders list with filters.
- [ ] **7.2** Order detail + status transitions.
- [ ] **7.3** Courier/tracking entry → shipped email.
- [ ] **7.4** Refund note + cancellation.
- [ ] **7.5** Customer directory.
- [ ] **7.6** Sales dashboard (revenue, AOV, top products).
- [ ] **7.7** CSV export.
- **Test:** status change fires exactly one email; an owner cannot see another tenant's orders.

# PHASE 8 — Renewal enforcement
- [ ] **8.1** Poll `/product/status` (10-min cache) on the storefront.
- [ ] **8.2** Expiry warning banner for owner at ≤7 days.
- [ ] **8.3** Expired → disable checkout, keep browsing alive.
- [ ] **8.4** Owner renew flow reusing `/product/pay-start`.
- [ ] **8.5** Grace period config.
- [ ] **8.6** Email/WhatsApp reminders.
- **Test:** expire a test enrollment — browsing works, checkout is blocked, renewing restores checkout within the cache window.

# PHASE 9 — Custom domains
- [ ] **9.1** `store_domains` management UI.
- [ ] **9.2** CNAME instructions + verification.
- [ ] **9.3** Automatic TLS (Caddy on-demand or Cloudflare for SaaS).
- [ ] **9.4** Subdomain fallback (`<slug>.spp.store`).
- [ ] **9.5** Redirect rules + canonical host.
- **Test:** a real domain resolves, gets a valid certificate, and serves the right tenant.

# PHASE 10 — Growth features
- [ ] **10.1** Coupons (percent, flat, first-order, free-shipping threshold).
- [ ] **10.2** Reviews + ratings with moderation.
- [ ] **10.3** Wishlist.
- [ ] **10.4** Related / recently viewed.
- [ ] **10.5** Abandoned cart emails.
- [ ] **10.6** SEO: sitemap, robots, canonical, JSON-LD product schema, OG images.
- [ ] **10.7** Analytics: GA4 + Meta Pixel IDs from config.
- [ ] **10.8** Newsletter capture.
- [ ] **10.9** Blog / CMS pages.
- [ ] **10.10** Accessibility pass (WCAG 2.2 AA).
- **Test:** per-feature; SEO validated with Google Rich Results Test.

---

## Known blockers carried from `ECOMMERCE-PLATFORM-FEATURE-RESEARCH.md`

| Blocker | Status in this plan |
|---|---|
| SQLite-per-category can't hold per-tenant pricing | **Solved** — catalog mirrored to Postgres, markup at query time (0.3, 0.5) |
| Scrape queue concurrency 1 | **Out of scope** — belongs to UltimateScrapperV2, which we don't touch |
| Duplicate-content SEO across tenants | **Partially addressed** in 10.6; needs owner-authored copy, not just code |
| Supplier order routing | **Out of scope for now** — separate backend project, must be solved before real GMV |
