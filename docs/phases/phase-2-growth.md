# Phase 2 — Growth + Custom Domains — 🟡 Partial (2026-08-20)

The tractable Phase 2 items are done and live; the ones that need real
external accounts / real design work are honestly deferred with a note per
item. This is not "we ran out of time" — it's the lazy senior-dev call about
what fits one turn and what genuinely needs its own phase.

---

## ✅ Done

### 1. Bug fix — tenant preserved on client-side nav + hard refresh

**The user-reported bug**: with BrowserRouter, an internal `<Link to="/">` on
the storefront drops the `?store=slug` query param, and a hard refresh loses
the tenant entirely in local dev. Fix: `resolveSlug()` in
[`site/src/lib/storeApi.js`](../../site/src/lib/storeApi.js) now caches the
resolved slug in `sessionStorage` on every successful resolve, and reads it
back when no `?store=` is present on localhost. Verified live — navigating
from `?store=aqua-watch` to a bare `/` correctly keeps rendering as Aqua Watch.

### 2. Custom domain support — end-to-end

New columns `enrollments.custom_domain` + `enrollments.custom_domain_verified_at`
(migration [`portal/hosted_storefront_custom_domain.sql`](../../UltimateScrapperV2/portal/hosted_storefront_custom_domain.sql), applied live).

- **Server** — [`portal/resolveStore.js`](../../UltimateScrapperV2/portal/resolveStore.js) now matches the request `X-Store-Host` header against `custom_domain` FIRST, falling back to the URL-segment slug. Only **verified** custom domains resolve, so a vendor typing a domain they don't own doesn't accidentally serve someone else's storefront.
- **Storefront** — every `/store/*` request now includes `X-Store-Host: <window.location.hostname>`. Zero-config: one deployed SPA serves `slug.platform.com` AND `aquawatch.com` from the same code.
- **Portal** — `CustomDomainPanel` in the vendor storefront detail view: vendor enters their domain, sees a DNS-setup instruction card while it's unverified, sees a ✓-verified badge once the admin has confirmed.
- **Admin** — `AdminHostedSites` shows the domain + status inline; a "Verify domain" button probes `https://<domain>/` and confirms the storefront's `<div id="root">` marker is served. Setting `custom_domain_verified_at` unlocks the resolver.
- **CORS** — added `X-Store-Host`, `x-enrollment-key`, `x-site-domain` to the backend's allowed-headers list (the last two were a pre-existing latent bug for the plugin's cross-origin sync-feed calls, fixed by proximity).

### 3. Order emails — buyer confirmation + vendor notification

Two new templates in [`portal/mailer.js`](../../UltimateScrapperV2/portal/mailer.js):
`sendOrderConfirmationEmail` (to the shopper if they gave an email) and
`sendOrderNotificationEmail` (to the vendor's account email). Both include
itemized totals, delivery address, and a big green "Send order on WhatsApp"
button for the buyer receipt. Fire-and-forget from the order-creation flow —
mailer failures never break the checkout response. Reuses the existing SMTP
transport configured in the portal admin's Email settings page, so it Just
Works with the admin's existing Gmail/whatever setup.

### 4. Vendor pricing UI — markup bands editor

New "Pricing markup" panel in the vendor's branding form. Vendor sees the four
default bands (`0-500 → +₹750`, `500-4500 → +₹1000`, `4500-6000 → +₹1250`,
`6000+ → +₹1500`) as editable rows. Editing any row switches from "platform
default" to "custom bands" mode; "Reset to platform default" reverts. Empty
bands array in the DB = server falls back to the built-in defaults in
[`portal/pricing.js`](../../UltimateScrapperV2/portal/pricing.js), which is
what all Phase 1 sites use.

### 5. Analytics pixel injection (GA4 + Meta) per vendor

New `site_settings.analytics` jsonb column (migration
[`portal/hosted_storefront_settings_extras.sql`](../../UltimateScrapperV2/portal/hosted_storefront_settings_extras.sql), applied live).
Vendor enters their GA4 measurement ID and/or Meta Pixel ID in the branding form;
[`StoreContext`](../../site/src/context/StoreContext.jsx) injects the standard
loader + init snippets into `<head>` once the config resolves, guarded with
element-id lookups so React StrictMode's double-mount doesn't double-inject.
Each vendor gets their own tracking; no shared analytics leakage between
tenants.

### 6. Deep-link URL sharing (Phase 2.1 pass, 2026-08-20)

The Phase 2 sessionStorage cache fixed the "hard-refresh loses the tenant"
bug for the same-tab shopper — but a URL *copied from that tab and pasted
into a fresh browser* still 404'd, because the recipient's browser has no
sessionStorage from your session. Fix: a `useShareableTenantUrl` hook in
[`site/src/App.tsx`](../../site/src/App.tsx) that runs on every route change
and, on localhost, uses `history.replaceState` to keep `?store=<slug>` in the
address bar. Verified live — clicked from the homepage to a product, the URL
became `/p/watches/6777?store=aqua-watch` automatically. Copying and pasting
that URL into a fresh browser resolves to the correct store and product.
Skipped in production because the subdomain (`aquawatch.platform.com`) already
carries the tenant.

### 7. Live product refresh on view

Ported the original `site/`'s fire-and-forget `/dev/update-single-product`
call. On every product page view, the storefront kicks off a backend re-scrape
of that product's stock + price (`${VITE_BASE_URL}/dev/update-single-product?productId=X&productDb=Y`).
Non-blocking, silent on failure — the current shopper sees the cached row,
the next shopper sees the fresh one. Verified via network tab (200 OK on
every product open).

### 8. `.env.example` documented for one-flag environment switching

New [`site/.env.example`](../../site/.env.example) documents every
`VITE_*` env var with sample values for **local backend**, **Render**, and
**AWS sslip.io** so the owner can flip between backends by editing one line
in `.env.local`.

### 9. Wishlist — localStorage-backed, per-tenant

New [`WishlistContext`](../../site/src/context/WishlistContext.jsx) mirrors
the cart's shape and lifecycle: keyed `spp_wishlist:<slug>`, so items don't
leak between vendor sites. Heart icon on every `ProductCard` (bottom-right of
the image) and on the product detail page next to Add-to-Cart. Nav shows the
wishlist count with a rose badge. `/wishlist` page lists saved items with
"Add to cart" and "Remove" per row. Move to DB when a shopper starts having
multi-device sync as a real ask.

---

## 🟠 Honestly deferred — each is real half-day+ work, not a quick add

### Payment gateway at checkout

**Why deferred**: the *code wiring* is a couple of hours (the backend already
has Pay0 `createOrder` / `checkStatus` from the invoice flow). The *hard
part* is testing it end-to-end with real Pay0 credentials, per-vendor gateway
config (each vendor's own merchant account, not the platform's), refund/void
handling, and the reconciliation logic. That's a genuine phase on its own.

**Extension point when ready**: modify `storeRoutes.js`'s `POST /orders`
to also accept `{ pay_method: 'online' }`, call `createOrder` when it does,
return a `payment_url` alongside `wa_url`; write a webhook route at
`/store/pay/callback` that flips order status from `pending` to `confirmed`
on the gateway's success ping. Same pattern as `paymentRoutes.js` uses for
invoice payments.

### Coupons + per-vendor announcement scheduling

**Why deferred**: neither is complex individually, but "correct enough" needs
usage-count limits, expiry, minimum-cart-total, per-customer limits, and a
vendor UI for creating them. All of that is a coherent little feature on its
own — pretending it's a five-minute add would ship a broken one.

**Extension point when ready**: new `coupons` table
(`enrollment_id, code, discount_pct, discount_flat, min_total, max_uses,
used, expires_at`); vendor edits in the portal; `POST /store/:slug/orders`
takes a `coupon_code` and validates + increments `used` in the same transaction.

### Reviews + ratings

**Why deferred**: to be real, reviews need moderation (approve/reject in the
portal), a "verified buyer" mark, aggregate rating on the product card, and
someone actually to write a review. That's easily a week to do properly.

**Extension point when ready**: new `product_reviews` table
(`enrollment_id, product_id, db_name, customer_id, stars, text, status,
created_at`); `POST /store/:slug/products/:dbName/:id/reviews` (customer JWT
required), `GET` for public reads; portal moderation queue for the vendor.

### Storefront billing plans

**Why deferred**: the plans table already has "Standard" at ₹1000/mo. Adding
hosted-specific tiers (a free trial, a self-hosted-priced tier, a "with online
payments" tier) is a business-decision question, not a code question — I don't
know what tiers you want, and the code change is trivial once you do.

### Full content editor (Phase 3, deferred correctly per blueprint)

Not in Phase 2 — the visual drag-and-drop section editor is Phase 2's/3's
biggest single job. Presets picker (already shipped in Phase 1E) is the
lazy 90%-solution; the full editor is a separate build.

### SEO / SSR

**Why deferred**: real infra change. Vite → Next.js or Vite + a SSR wrapper,
per-vendor meta injection, product schema.org, sitemaps, canonical URLs. Not
"a small addition" — a project on its own. Analytics pixels ✅ are shipped
in Phase 2 above, which is the smaller-but-real growth win.

### Shipping integration (Shiprocket / Delhivery)

**Why deferred**: needs a real Shiprocket account with pickup addresses set
up per vendor, AWB generation on order confirm, tracking webhooks, and a
vendor UI for choosing a shipping partner. That's a real subsystem, not a
line of code.

### GST invoice generation

**Why deferred**: legal/compliance work — GSTIN entry per vendor, per-line
tax breakdown, invoice-number sequence per financial year per vendor, PDF
generation, HSN codes. Real accounting-feature scope, not a pixel-injection.

---

## Verification

New pieces were verified live through the real running app (backend on 3002 +
storefront dev server on 5175):

- **Tenant preservation** — navigated from `?store=aqua-watch` to bare `/`,
  confirmed the site still renders as Aqua Watch (sessionStorage-cached slug).
- **Wishlist** — clicked hearts on product cards, confirmed the item persisted
  to `localStorage['spp_wishlist:aqua-watch']` with the right shape (name,
  image, price, mrp), then navigated to `/wishlist` and confirmed the item
  appears with "Add to cart" and "Remove" actions.
- **Bundle** — `npm run build` clean, 880KB gzip 270KB (up ~5KB from Phase 1E
  for wishlist + hearts). No regressions.

Custom domain, order emails, and analytics pixels weren't verified against
real external systems (no live public domain / real SMTP hit / real GA4 account
in this run) but the flow is code-complete and syntax-verified. Test via the
[updated TEST_CREDENTIALS.md](../TEST_CREDENTIALS.md).

## Depends on / feeds into

Everything in this phase is layered on top of Phase 1A–1E and needs no changes
to any earlier piece. The four honestly-deferred items above (payments,
coupons, reviews, SSR/shipping/GST) each get their own phase whenever the
business need is real.
