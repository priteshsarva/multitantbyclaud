# Phase 1A — Backend — ✅ Done (2026-08-20)

## What it does

Gives every vendor a hosted, branded storefront backed by the existing scraper
catalogue, with WhatsApp checkout and full order tracking — all on the portal's
existing Postgres, reusing the plugin's enrollment lifecycle instead of building
a parallel one.

**Data model** ([portal/hosted_storefront.sql](../../UltimateScrapperV2/portal/hosted_storefront.sql), applied):
- `enrollments.type`/`slug` — a hosted site *is* an enrollment (`type='hosted'`); approval, expiry, source selection (`enrollment_sources`), category mapping all reused as-is.
- `site_settings` — the branding pack (store name, logo, theme, WhatsApp, address, hero, policies, pricing bands). One row per site; editing it is live on next page load, no deploy.
- `customers` / `customer_addresses` — shopper accounts, scoped per-vendor (same email can register independently on different vendor sites).
- `orders` / `order_items` — WhatsApp-checkout orders, server-priced, with a status lifecycle (`pending → confirmed → shipped → delivered/cancelled`).

**API** (all new, mounted in [index.js](../../UltimateScrapperV2/index.js)):
- Public — `portal/storeRoutes.js` → `/store/:slug/*`: config, product listing/detail (tenant-filtered from the scraper SQLite, same WHERE-builder sync-feed uses), customer signup/login, addresses, order creation (guest or logged-in), order history.
- Vendor — `portal/hostedSiteRoutes.js` (client router) → `/portal/hosted-sites/*`: request a site, list mine, edit branding, list/update my orders.
- Admin — same file (admin router) → `/portal/admin/hosted-sites/*`, `/portal/admin/orders`: see every site and every order across all vendors, pause/rename/delete a site.
- Supporting modules: `portal/resolveStore.js` (slug → enrollment), `portal/customerAuth.js` (shopper JWT, **separate secret** from portal JWTs), `portal/pricing.js` (server-side markup — moved off the browser, same bands the old `data.jsx` used).

## What it needed (and got)

- A way to trust prices at checkout — solved by never accepting a price from the client; every order re-fetches and re-prices each item from SQLite.
- Tenant isolation for both data *and* auth — solved by scoping every query to `enrollment_id`, and signing customer JWTs with `enrollmentId` baked in, checked against the requested `:slug` on every call.
- Proof it actually works — ran the full loop against the **live** database (not mocks): create → approve → activate → brand → attach source → browse → tenant-isolation 404 check → guest order → customer signup/login/address → logged-in order → vendor order management → admin cross-vendor view → status update → cleanup. All passed; verified the DB was left clean afterward.

## What it needs more

- **`smoke-test.sh` §9** exists but needs `HOSTED_SOURCE_ID` / `HOSTED_TEST_PRODUCT_ID` set to a real source/product before it runs — nobody's plugged those in yet.
- **No payment gateway** — orders are a lead captured server-side, confirmed by a human over WhatsApp. This is intentional for Phase 1 (per the owner's decision), but it means `pending` orders don't self-confirm; Phase 2 wires Pay0 into the same order row.
- **No custom domains** — sites are reached by `slug` only right now; there's no HTTP-level tenant resolution by hostname yet (that's Phase 1D/1B's job — the backend already stores `enrollments.slug` and could add `enrollments.domain` resolution later without a schema change).
- **No rate limiting / abuse guards** on `/store/:slug/auth/signup` or `/store/:slug/orders` — fine for a handful of pilot vendors, worth adding before opening signups publicly.
- **Admin approval is still generic** — a hosted site shows up in the same `/portal/admin/enrollments` queue as plugin sites. That's deliberate reuse, but the admin has no dedicated hosted-sites *approval* UI yet — only the overview list. That's what Phase 1C builds next.

## Depends on / feeds into

Everything after this reads from `/store/:slug/*` and writes through `/portal/hosted-sites/*` — Phase 1B (storefront SPA) and Phase 1C (portal admin/vendor UI) are pure clients of this API and shouldn't need backend changes to ship.
