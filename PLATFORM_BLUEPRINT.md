# SPP Hosted Storefronts — Blueprint & Phased Plan

> Multi-tenant hosted e-commerce sites for vendors, built on the existing SPP stack.
> One codebase, one database, many vendor sites — each with its own branding, products,
> customers, and orders. Checkout via WhatsApp (payment gateway deferred to Phase 2).
> **The portal is the admin dashboard** — for vendors and for the super-admin.

Last updated: 2026-08-20 · Decisions confirmed with owner (see §9)

---

## 1. What we're building (one paragraph)

Today, `site/` (the Aqua Watch React SPA) is a single hardcoded storefront that pulls
scraped products from `UltimateScrapperV2` and sends buyers to WhatsApp. We are turning
it into a **template that serves many vendors**: a vendor signs up in the portal,
the admin approves, and `<slug>.yourplatform.com` goes live — showing *that vendor's*
name, logo, colors, WhatsApp number, address, and *that vendor's* selected products
(sources + categories, exactly like the WooCommerce plugin's enrollment feature).
Buyers browse, fill a cart, check out with their address, and the order is saved to the
portal's Supabase **and** handed off to the vendor's WhatsApp. Vendors manage their
site, products, and orders from the portal; the super-admin sees and controls everything.

---

## 2. Current-state analysis

### What exists and is reused

| Piece | Where | Reused as |
|---|---|---|
| Vendor signup + JWT auth | `portal/authRoutes.js`, `portal/auth.js` | Vendor login — unchanged |
| Enrollment lifecycle: request → `pending` → admin approve → activate → expiry | `portal/enrollmentRoutes.js`, `portal/adminRoutes.js`, `portal/scheduler.js` | **A hosted site IS an enrollment** (`type='hosted'`) — approval, expiry, billing all reused |
| Product selection per site (sources + per-source category allow-list) | `enrollment_sources`, `portal/enrollmentSourceRoutes.js` | The "plugin feature" — reused untouched |
| Category renaming per store | `category_map`, `portal/categoryMapRoutes.js` | Reused untouched |
| Product catalogue + per-source filtered feed | SQLite dbs + `routes/productRoutes.js` (sync-feed WHERE-builder) | New `/store/:slug/products` endpoint reuses the same WHERE-builder |
| Portal SPA (admin + client screens) | `portal-app-clean/src/screens/*` | New screens slot in beside existing ones |
| Storefront UI (pages, carousels, product grid, detail page) | `site/src` | Becomes the multi-tenant template |
| Email | `portal/mailer.js` | Order/approval notifications |

### What's missing (the build)

- Tenant resolution (subdomain → vendor) and vendor-branded rendering
- `site_settings` (branding pack), `customers`, `customer_addresses`, `orders`, `order_items` tables → **`portal/hosted_storefront.sql` (written, ready to apply)**
- Public storefront API (`/store/:slug/*`) on the backend
- Cart, checkout, buyer auth, account pages in the storefront SPA
- Vendor store-management + order screens, admin all-orders screen in the portal SPA
- Hosting that supports wildcard subdomains (gh-pages cannot)

### Problems in the current storefront this plan fixes

| Problem | Fix |
|---|---|
| Branding hardcoded (`brand`, `Brandphone`, logo in `data.jsx`) | All branding from `site_settings` via `/store/:slug/config` |
| Prices computed **in the browser** (`calculateDiscountedPrice` in `data.jsx:6511`) — tamperable, markup formula public | Server computes sell price in the feed + at order creation; browser only displays |
| No record of orders (WhatsApp text only) | `orders` + `order_items` rows, then WhatsApp handoff |
| 292KB `data.jsx` bundled static data | Vendor config from API; static content trimmed per-tenant |
| `.env` ships live server IP; `VITE_LIVE_URL` refresh endpoint public | Storefront talks only to the public `/store/*` API |

---

## 3. Target architecture

```
                        ┌──────────────────────────────────────────────┐
                        │        UltimateScrapperV2 (Node, :3002)      │
 Buyer ──► storefront   │                                              │
 <slug>.platform.com ──►│  /store/:slug/*  (public, tenant-scoped)     │
 (site/ SPA,            │     config · products · auth · orders        │
  one deploy)           │                                              │
                        │  /portal/*  (vendor JWT)   ← portal SPA      │
 Vendor ──► portal ────►│     my sites · settings · my orders          │
 admin.platform.com     │  /portal/admin/*  (admin JWT)                │
 (portal-app-clean)     │     approve sites · all orders · sources     │
                        │                                              │
                        │  Scraper (p-queue) ──► SQLite catalogue      │
                        └───────────────┬──────────────────────────────┘
                                        │ pg (DATABASE_URL)
                        ┌───────────────▼──────────────────────────────┐
                        │   Portal Supabase  (bpctkco…, ap-south-1)    │
                        │   users · enrollments(+type,slug) ·          │
                        │   enrollment_sources · category_map ·        │
                        │   site_settings · customers ·                │
                        │   customer_addresses · orders · order_items  │
                        └──────────────────────────────────────────────┘
```

**Decisions baked into this diagram:**

1. **Everything goes through the Node backend.** The portal's Supabase is reached only
   via the backend's existing `pg` pool (`portal/db.js`). The storefront never holds a
   DB credential; tenant isolation is enforced in SQL (`where enrollment_id = $1`) and
   route guards. (This supersedes the earlier "supabase-js + RLS" idea — with the portal
   as the admin and everything in the portal's DB, the backend already owns access.)
2. **A hosted site is an enrollment** (`enrollments.type='hosted'`, `slug` = subdomain).
   Signup → pending → admin approve → active, expiry/billing, source selection,
   category mapping: zero new lifecycle code.
3. **Products stay in the scraper's SQLite.** `/store/:slug/products` resolves the slug
   → enrollment → its `enrollment_sources`, then runs the same per-source
   `productFetchedFrom LIKE` + category filter the plugin sync-feed uses.
4. **Two JWT worlds, kept apart.** Portal users (vendors/admin) keep the existing
   `JWT_SECRET`. Shoppers get tokens signed with a **separate `CUSTOMER_JWT_SECRET`**
   and scoped `{ customerId, enrollmentId }` — a shopper token can never pass
   `requireAuth` on portal routes, and a customer of vendor A can never read vendor B.
5. **WhatsApp checkout** (Phase 1): order is saved first (status `pending`), then the
   browser opens `https://api.whatsapp.com/send?phone=<vendor's number>&text=<order summary>`.
   Same for Quick Buy on the product page — one item, same endpoint, same handoff.
   The vendor's number comes from `site_settings.whatsapp` — per vendor, everywhere.

### Tenant resolution

- Production: `watchhouse.platform.com` → SPA reads `location.hostname`, slug = first label.
- Local dev: `?store=<slug>` query param or `VITE_DEV_STORE` fallback.
- Custom domains (`aquawatch.com` → vendor): Phase 2 — `enrollments.domain` already
  stores it; resolution adds a domain → slug lookup.

### Per-vendor pricing

`site_settings.pricing` holds markup bands (empty = platform default = today's bands in
`data.jsx`). Server applies bands when serving products **and** when creating orders.
The browser never computes a price.

---

## 4. Schema (Phase 1) — `UltimateScrapperV2/portal/hosted_storefront.sql`

Written and ready. Apply by hand in the **portal** Supabase SQL editor (project
`bpctkco…` — the one `DATABASE_URL` points at), same convention as the other
`portal/*.sql` files. Additive only.

| Change | Purpose |
|---|---|
| `enrollments.type` (`'plugin'`\|`'hosted'`) + `enrollments.slug` (unique) | A hosted site is an enrollment; slug = subdomain |
| `site_settings` (PK = enrollment_id) | Branding pack: store_name, logo, theme, whatsapp, email, phone, address, socials, hero, announcement, about, policies, pricing bands |
| `customers` (unique per enrollment+email) | Per-vendor shopper accounts; same email may exist on many vendor sites |
| `customer_addresses` | Saved addresses, default flag |
| `orders` (order_no `ORD-000123`, customer nullable = guest) | WhatsApp-checkout orders; status pending→confirmed→shipped→delivered/cancelled |
| `order_items` (product_id + db_name) | db_name required because productId is only unique per category DB |

> ⚠️ Cleanup already done: the six tables mistakenly created in the unrelated
> `clientportal` Supabase project were dropped; that DB is back to its original state.

---

## 5. API surface (Phase 1 additions)

### 5.1 Public storefront — `/store/:slug/*` (new file `portal/storeRoutes.js`)

Mounted **before** `/product` in `index.js`. Every route first resolves
slug → active hosted enrollment (404 if missing/paused/expired).

| Method | Path | Purpose |
|---|---|---|
| GET | `/store/:slug/config` | Branding pack + canonical category list. The SPA's first call; everything vendor-varying renders from this. |
| GET | `/store/:slug/products` | Tenant-filtered catalogue: this enrollment's sources+categories, keyset-paginated, **server-priced** (`price`, `mrp`, `savings_pct`). Params: `category`, `q`, `after`, `limit`, `sort`. |
| GET | `/store/:slug/products/:dbName/:id` | One product + similar items, server-priced. |
| POST | `/store/:slug/auth/signup` | Shopper account `{email,password,name,phone}` → customer JWT (separate secret). |
| POST | `/store/:slug/auth/login` | → customer JWT. |
| GET | `/store/:slug/me` | 🧑‍🦱 Profile + addresses. |
| POST/PUT/DELETE | `/store/:slug/me/addresses[/:id]` | 🧑‍🦱 Manage saved addresses. |
| POST | `/store/:slug/orders` | Create order (guest or 🧑‍🦱). Body: items `[{product_id, db_name, qty}]` + address (or address_id). Server re-fetches each product, computes prices/totals, writes `orders`+`order_items`, returns `{order_no, wa_url}` — `wa_url` is the prefilled WhatsApp link to the **vendor's** number. |
| GET | `/store/:slug/me/orders[/:orderNo]` | 🧑‍🦱 Order history / detail + status. |

🧑‍🦱 = requires customer JWT. Guest checkout allowed (no token → `customer_id` null).

### 5.2 Vendor (portal, existing client JWT)

| Method | Path | Purpose |
|---|---|---|
| POST | `/portal/hosted-sites` | Request a storefront `{store_name, slug?}` → creates enrollment `type='hosted'` (pending) + draft `site_settings`. Slug auto-generated from name, admin-editable. |
| GET | `/portal/hosted-sites` | My storefronts + status/expiry (MySites analogue). |
| GET/PUT | `/portal/hosted-sites/:id/settings` | Read/save the branding pack. Live on next storefront load — no deploy. |
| GET | `/portal/hosted-sites/:id/orders?status=` | My orders list (+ counts by status). |
| GET/PATCH | `/portal/hosted-sites/:id/orders/:orderId` | Detail / change status (confirm, ship, deliver, cancel). |
| — | source & category selection | **Reuse existing** `/portal/enrollments/:id/sources` + `/portal/enrollments/:id/category-map` untouched. |

### 5.3 Admin (portal, admin JWT)

| Method | Path | Purpose |
|---|---|---|
| — | approve / activate | **Reuse existing** enrollment approve/activate. One addition: approving a hosted enrollment ensures its `site_settings` row exists → site is live at its slug. |
| GET | `/portal/admin/hosted-sites` | All storefronts: owner, slug, status, days-left, order counts. |
| GET | `/portal/admin/orders?enrollment_id=&status=` | Every order across every vendor. |
| PATCH | `/portal/admin/hosted-sites/:id` | Edit slug/status (pause site). |

---

## 6. Storefront SPA changes (`site/`)

The template keeps its look; every vendor-specific value becomes data.

1. **`StoreContext`** — boot: resolve slug → `GET /store/:slug/config` → provide
   `{store_name, logo, theme, whatsapp, address, socials, hero, announcement, policies, categories}`
   to the whole tree. Theme colors applied as CSS variables. Unknown slug → "store not found" page.
2. **De-hardcode** — replace every import of `brand`, `Brandphone`, `brandlogo`,
   `announcements`, hero images, footer address/socials from `data.jsx` with StoreContext
   values (NavBar, Footer, Hero, AnnouncementBar, PromoBar, product pages, FAQ/policy pages).
3. **`CartContext`** — localStorage key `cart:<slug>` (carts don't leak between vendor
   sites). Line items `{product_id, db_name, name, image, qty, price}` (price display-only).
   Add-to-cart on `Card` + `ProductDetailPage`; cart drawer + `/cart` page.
4. **Checkout page** — contact + address form (logged-in: pick saved address) →
   `POST /store/:slug/orders` → success page with `order_no` → **"Complete on WhatsApp"**
   button opens the returned `wa_url` (vendor's number, order summary prefilled).
   **Quick Buy** on the product page = same endpoint with a single item, same handoff.
5. **Buyer auth pages** — login/signup, `/account` (profile, addresses, orders with status).
6. **Products via the new API** — swap `/product/firstdata`, `/product/search`,
   `/product/:id` calls to `/store/:slug/products…`; delete client-side price functions.
7. **Hosting move** — gh-pages → static host with wildcard subdomain support
   (Netlify/Vercel/Cloudflare Pages) + `*.yourplatform.com` DNS. Then `BrowserRouter`
   replaces `HashRouter` (also fixes SEO-hostile `#/` URLs).

## 7. Portal SPA changes (`portal-app-clean/`)

New screens beside the existing ones (same `api.js` pattern):

**Vendor:** `MyStorefronts` (list + request new), `StoreSettings` (branding form),
`StoreOrders` (list, filters, status buttons), source/category pickers reused from MySites.

**Admin:** `AdminHostedSites` (approval + overview; approve = existing enrollment approve),
`AdminOrders` (all vendors, filterable).

---

## 8. Phased plan & to-do

### ✅ Phase 0 — groundwork (done this session)
- [x] Architecture + decisions confirmed (§9)
- [x] Stray tables dropped from the unrelated `clientportal` project
- [x] Phase-1 schema written: `UltimateScrapperV2/portal/hosted_storefront.sql`
- [x] This blueprint

### 🔨 Phase 1 — vendor sites live with WhatsApp checkout
*Goal: vendor signs up → admin approves → branded site at `<slug>.platform.com` with that vendor's products; buyers get cart + login + addresses + orders; vendor manages orders in the portal.*

**1A — Database & backend** ✅ built, boot-tested, and end-to-end verified against the live portal DB (2026-08-20)
- [x] Applied `portal/hosted_storefront.sql` in the portal Supabase SQL editor
- [x] `CUSTOMER_JWT_SECRET` in backend `.env` + `.env.example`; `portal/customerAuth.js` (separate secret from portal JWTs, by design — see §3 decision 9)
- [x] `portal/resolveStore.js` — `:slug` → active hosted enrollment, 404 otherwise
- [x] `portal/storeRoutes.js` — config, products (reuses the sync-feed per-source WHERE-builder), product detail with tenant-ownership check, customer auth, addresses, orders
- [x] `portal/pricing.js` — server-side markup bands (parity with the old `data.jsx` formula), per-vendor override via `site_settings.pricing`
- [x] Orders route: re-fetches + re-prices every item server-side, snapshots `order_items`, builds the `wa_url` WhatsApp handoff
- [x] `portal/hostedSiteRoutes.js` — vendor (`request site, settings, orders`) + admin (`all sites, all orders`) routers
- [x] Site creation is eager, not approval-triggered: `site_settings` row is created at `POST /portal/hosted-sites` time, so approve/activate (existing, untouched `adminRoutes.js`) needs no hook
- [x] Mounted in `index.js` under the new `/store` prefix (no collision with the `/product/:id` catch-all — different prefix entirely)
- [x] Full manual smoke test run against the live DB: signup→approve→activate→brand→attach source→browse→detail→tenant-isolation 404→guest checkout→customer signup/login/addresses→logged-in checkout→order history→vendor order mgmt→admin cross-vendor view→status update. Test rows (`test-watch-co`) created and then deleted (cascade) — production DB confirmed clean afterward.
- [ ] Extend `smoke-test.sh` with a scripted version of the above (manual run done; not yet codified into the repo's test script)

**1B — Storefront SPA** ✅ built and browser-tested end-to-end (2026-08-20) — see `docs/phases/phase-1b-storefront.md`
- [x] StoreContext + tenant resolution (hostname / `?store=`) + theme CSS vars
- [x] De-hardcode all branding to StoreContext (nav, footer, hero, announcement, policies, WhatsApp numbers) — new `Store*` components; legacy watch-specific marketing components (ShopbyBrand, ReadyToDispatch, etc.) deliberately left unused, not ported — see phase doc
- [x] CartContext + cart UI
- [x] Switch product fetching to `/store/:slug/*`; remove client-side price functions (also fixed `images`/`sizes` field shapes in the API — were raw JSON-string, not usable)
- [x] Checkout + order-success (WhatsApp handoff); Quick Buy through the same flow — found+fixed a real display-ordering bug in the success screen
- [x] Buyer login/signup + account (orders, addresses)
- [x] "Store not found / paused" page

**1C — Portal SPA** ✅ built and browser-tested end-to-end (2026-08-20) — see `docs/phases/phase-1c-portal-ui.md`
- [x] Vendor: `MyStorefronts` (list, create, branding form, orders panel — combined into one detail view)
- [x] Admin: `AdminHostedSites` (approve → activate → live, verified via real browser test) + `AdminOrders`

**1D — Deploy** 🟡 code ready (2026-08-20); hosting/DNS is the owner's action — see `docs/phases/phase-1d-deploy.md`
- [x] `BrowserRouter` switch + host rewrite rules ready (`site/public/_redirects` for Netlify/Cloudflare Pages, `site/vercel.json` for Vercel) — verified with a full `npm run build`
- [x] Backend CORS confirmed already sufficient (`origin: '*'`, `credentials: false` — no change needed)
- [ ] **Owner action**: connect `site/` to a host, set wildcard DNS `*.yourplatform.com`, set `VITE_BASE_URL` — cannot be done from this environment, needs the owner's hosting/registrar accounts
- [ ] End-to-end run against a real deployed subdomain (the local/`?store=` version of this is already done — see 1A/1B — this is the same flow once truly live)

**1E — Section library + preset layouts** ✅ built and browser-verified (2026-08-20) — see `docs/phases/phase-1e-section-library.md` and `docs/COMPONENTS.md`
- [x] Flatsome-inspired section library (~11 reusable, tenant-generic shortcode-style components) with typed props + defaults
- [x] `site_settings.sections` (jsonb) + data-driven `StoreHome` (renders from vendor's array; falls back to shipped preset)
- [x] Two shipped presets (Commerce classic + Showcase) mirrored server-side for the "apply preset" endpoint
- [x] Preset picker in the vendor portal Settings screen
- [x] Two live test sites (Aqua Watch + Timeless & Co) with distinct themes, sources, presets — see `docs/TEST_CREDENTIALS.md`
- [x] `docs/COMPONENTS.md` — every section documented shortcode-style (props table, JSON example, extension points)

### 💳 Phase 2 — growth 🟡 partial (2026-08-20) — see `docs/phases/phase-2-growth.md`
- [ ] Online payment at checkout — deferred (needs Pay0 credential test + per-vendor gateway config)
- [x] Vendor pricing UI (markup bands editor in Branding form)
- [x] **Custom domains per vendor** — `enrollments.custom_domain` + `X-Store-Host` header + verify flow + vendor/admin UI
- [x] Order emails (buyer confirmation + vendor notification) via `mailer.js`
- [ ] Homepage section builder — deferred (visual drag-drop editor is its own phase; preset picker already ships in Phase 1E)
- [ ] Coupons + announcement scheduling — deferred (whole coherent feature)
- [ ] Storefront billing plans — deferred (business decision, then code is trivial)

### 🚀 Phase 3 — full commerce polish
- [x] **Wishlist** (localStorage-backed, per-tenant) — shipped in Phase 2 pass
- [x] **Analytics per vendor** (GA4 + Meta pixel injection from settings) — shipped in Phase 2 pass
- [x] **Tenant preservation bug fix** — sessionStorage-cached slug survives hard-refresh + client-nav
- [ ] Reviews/ratings — deferred (needs moderation UI + verified-buyer flow)
- [ ] Full content editor (rich text pages per vendor) — deferred
- [ ] SEO: SSR/prerender, per-vendor meta + product schema.org, sitemaps — deferred (real infra change)
- [ ] Shipping integration (Shiprocket/Delhivery), pincode check, tracking timeline — deferred (needs real account setup per vendor)
- [ ] GST invoice generation — deferred (real accounting/compliance work)

---

## 9. Decision log (confirmed with owner)

| # | Decision | Choice |
|---|---|---|
| 1 | Payment | **WhatsApp checkout in Phase 1** (checkout + quick buy); gateway in Phase 2 |
| 2 | Database | **Portal's Supabase only** (`bpctkco…`); no new project; stray tables in `clientportal` dropped |
| 3 | Site record | **A hosted site = an enrollment** (`type='hosted'`) — reuse approval/expiry/sources/category-map |
| 4 | Products | **Fetched live from the scraper backend** (SQLite); never copied into Supabase |
| 5 | Admin | **The portal is the admin dashboard** (vendor self-serve + super-admin) |
| 6 | Buyer accounts | **Per-vendor scoped; guest checkout allowed** |
| 7 | Customization (Ph1) | **Branding pack** (logo, name, colors, WhatsApp, address, email, socials, hero, announcement, about, policies) |
| 8 | Tenancy routing | **Subdomain per vendor** on one deploy; custom domains Phase 2 |
| 9 | Data access | **Everything through the Node backend** (no supabase-js in browsers; isolation by `enrollment_id` in SQL + separate customer JWT secret) |

## 10. Risks & guardrails

- **`/product/:id` catch-all**: every new route must mount before `productRoutes` — same rule the portal already lives by.
- **JWT bleed**: customer tokens must never verify against the portal secret (separate `CUSTOMER_JWT_SECRET`; guard asserts `enrollmentId` matches the `:slug` enrollment on every customer route).
- **Price trust**: order totals computed only server-side; items snapshot into `order_items` so later price changes don't rewrite history.
- **Backend load**: storefront traffic now hits the scraper box; SQLite reads are cheap, but watch for scrape-vs-read contention (WAL helps; p-queue already serializes writes). If it hurts: cache `/store/:slug/config` and first product page in memory.
- **WhatsApp is a handoff, not a confirmation**: an order row exists even if the buyer never sends the WhatsApp message — vendors should treat `pending` as "unconfirmed lead" until they confirm. (This is also the upgrade path: Phase 2 payment flips the same order to `confirmed` automatically.)
- **Live DB migration discipline**: `hosted_storefront.sql` is additive; still, apply during a quiet window and re-run `smoke-test.sh` after.
- **Secrets**: keep new config in `.env` (never committed); the storefront build must contain only the public API base URL.
