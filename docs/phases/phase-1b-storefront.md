# Phase 1B — Storefront SPA — ✅ Done (2026-08-20)

## What it does

Turns `site/` from a single hardcoded "Aqua Watch" shop into a **multi-tenant
storefront**: one deploy, and whichever vendor's slug is in the URL decides the
branding, the products, and where the WhatsApp checkout goes. Buyers can browse,
search, add to cart, check out (as a guest or logged in), and see their order
history — all against the Phase 1A backend.

**New infrastructure** ([src/context](../../site/src/context), [src/lib/storeApi.js](../../site/src/lib/storeApi.js)):
- `StoreContext` — resolves the vendor slug (subdomain in prod; `?store=slug` in local dev), loads `/store/:slug/config`, exposes it everywhere, and sets `--store-primary` as a CSS variable so the vendor's brand colour reaches every component without prop-drilling.
- `CartContext` — cart in `localStorage`, keyed `spp_cart:<slug>` so two vendor sites never share a cart.
- `CustomerAuthContext` — shopper JWT in `localStorage`, keyed per slug, using the customer auth endpoints from Phase 1A.

**New pages** ([src/pages/store](../../site/src/pages/store)): `StoreHome`, `StoreCategoryPage` (category browse + search, keyset "load more"), `StoreProductPage` (gallery, size picker, add-to-cart, **Buy now**), `CartPage`, `CheckoutPage` (guest form or saved address, WhatsApp handoff), `AccountPage` (login/signup, addresses, order history), `PolicyPage` (renders the vendor's own policy text), `StoreNotFound`.

**New components** ([src/components/store](../../site/src/components/store)): `StoreNavBar`, `StoreFooter`, `StoreAnnouncementBar`, `ProductCard` — all read branding from `StoreContext`, none hardcode a brand.

`App.tsx` was rewired around these: it now gates every route behind store
resolution (`loading` → blank, `not-found`/`error` → `StoreNotFound`, `ready` →
the app). `HashRouter` stays for this phase — the subdomain/`BrowserRouter`
switch is Phase 1D's job, once real hosting is in place.

## A real bug found and fixed by testing, not by reading the code

`CheckoutPage` had two early-return guards in the wrong order: "cart is empty →
show *nothing to check out*" ran **before** "an order was just placed → show
the success screen". A successful cart checkout calls `clear()`, which empties
the cart on the next render — so the empty-cart guard fired instead of the
success screen, even though the order had gone through correctly. Confirmed via
the admin API that the order was created fine; the bug was purely which screen
displayed. Fixed by guarding the empty-cart check with `!result`. Re-tested
after the fix — including the **quick-buy** path, which bypasses the cart
entirely — and both now correctly land on the "Order placed, complete on
WhatsApp" screen with a working `wa.me` link.

## Two API contract bugs found and fixed *before* the frontend was built

While shaping the response for `ProductCard`/`StoreProductPage`, found that
Phase 1A's `/store/:slug/products*` endpoints were handing back `row.imageUrl`
verbatim — which is scraped as a **JSON-stringified array**
(`'["url1","url2"]'`), not a usable `<img src>`. The same bug existed in the
order-creation snapshot (`order_items.image_url`). Fixed server-side: the API
now returns a real `images: string[]` array and a single `thumbnail` string,
parsed defensively (handles malformed/plain-string rows too). Same treatment
for `sizeName` → `sizes: string[]`. This was worth catching before the
frontend leaned on the broken shape — see the updated
[`portal/storeRoutes.js`](../../UltimateScrapperV2/portal/storeRoutes.js).

## Size handling (a real gap, not decorative)

The catalogue has no per-size SKU — `sizes` is informational only, scraped
alongside the listing. A size picker that didn't *do* anything would mislead a
shoe/watch vendor about what was ordered. Instead: selecting a size is
**required** before add-to-cart/buy-now when a product has sizes; the choice is
carried on the cart line (not folded into the product name, since the order API
re-fetches `product_name` from the server and would discard it) and surfaces to
the vendor as the order's `note` field at checkout time.

## Deliberate scope decision — not every legacy component was ported

`site/`'s original marketing components (`ShopbyBrand`, `ProductCategory`,
`ShoeCarousel`, `SingleCollection`, `ReadyToDispatch`, `Testimonials`,
`HappyCustomer`, `BoxSelector`, `VideoModal`) assume a fixed two-category
"watches + shoes" catalogue and pull hardcoded brand/category image assets from
`data.jsx`. Making them genuinely multi-tenant (unknown category sets, no
hardcoded brand list) is a materially different job from wiring up cart/
checkout/accounts, which is what was actually asked for this phase. They were
**left in place, unused** — not deleted, matching this codebase's existing
convention of keeping superseded files around (`AllProducts.jsx`,
`OriginalProductDetailPageWithprice.jsx`). The old `Home`, `AllProductPage`,
`ProductDetailPage`, `NavBarWithSubmenu`, `Footers1`, and all five legal pages
are similarly unused now, replaced by the `Store*` equivalents in the route
table. `/faq` was dropped rather than kept: its content was generic
Aqua-Watch-specific copy that would be actively wrong shown under another
vendor's name.

This is the reusable part of "`site/` becomes the template": the build
tooling, Tailwind setup, and asset pipeline — not literally every existing
watch-specific component.

## A build quirk worth knowing

`site/`'s `declarations.d.ts` has `declare module '*.jsx'` to let TypeScript
import plain JSX files, but that ambient declaration only matches import
specifiers that literally end in `.jsx` — it doesn't cover extension-less
imports resolved by bundler-mode module resolution. Every new file is
imported with an explicit `.jsx` extension in `App.tsx`, matching this
codebase's existing convention (`from './pages/Home.jsx'`, etc.) — the first
draft omitted it and `npm run build` (`tsc -b`) failed with `TS7016` on every
new import until fixed. Confirmed via a full `npm run build` afterward.

## Enrichment pass (2026-08-20, same day) — richer homepage, reusing legacy UX patterns

Added three components that borrow the *visual patterns* of the legacy
components without their hardcoded content: [`ProductRail`](../../site/src/components/store/ProductRail.jsx)
(a `react-slick` horizontal carousel — same UX as the old `ShoeCarousel`,
rebuilt on `ProductCard`; falls back to a static grid for short lists where a
carousel would look empty), [`CategoryTiles`](../../site/src/components/store/CategoryTiles.jsx)
("Browse by Category" grid inspired by the old `ProductCategory` — but the
thumbnail comes from each category's own first product instead of a
hand-picked hardcoded image, so it works for any category set with zero extra
API calls), and [`WhatsAppPromoBar`](../../site/src/components/store/WhatsAppPromoBar.jsx)
(dismissible strip, same look as the old `PromoBar`, but links to the vendor's
own WhatsApp number instead of one hardcoded community link). `StoreHome` and
`StoreProductPage`'s "you may also like" section both now use `ProductRail`.
Verified with a full `npm run build` and a live browser pass.

Still deliberately not ported: `ShopbyBrand`, `ReadyToDispatch`, `Testimonials`,
`HappyCustomer`, `BoxSelector`, `VideoModal` — see the original reasoning above,
unchanged by this pass.

## Full purchase journey — verified live (2026-08-20)

Ran one complete, realistic shopper journey end-to-end against a real hosted
site with real scraped products, through the actual running app (not code
review): browsed the home page (hero, WhatsApp bar, category tiles, carousel
rails) → searched ("casio", via the `/search?q=` route directly, confirming
results correctly excluded/labelled out-of-stock items) → opened 3 different
products and added all 3 to the cart (one with quantity 2, exercising the qty
stepper) → opened the cart, confirmed all 3 line items and the total, removed
one → signed up a new shopper account → saved a delivery address → went to
checkout, which correctly showed the 2 remaining line items and auto-selected
the saved address → placed the order → landed on the WhatsApp success screen
with both items correctly itemized in the message → confirmed the order in
the shopper's own order history *and* in the vendor's order list via the
backend, with the real `customer_id` attached (not a guest order this time).
All test data deleted and the database confirmed clean afterward.

**One environment-only finding, not a product bug**: pressing Enter inside
the nav search box didn't trigger navigation in this sandboxed browser
automation tool specifically. Isolated by calling `form.requestSubmit()`
directly, which worked correctly and navigated to the right URL with the
right query — proving the React state, the submit handler, and the
navigation are all correct; only the automation tool's synthetic Enter
keypress doesn't trigger native implicit form submission here. A real browser
handles Enter-to-submit for a single-text-field form natively; nothing in the
app needs to change.

## What it needs more

- **Homepage is generic** — hero + one product rail per category. The
  richer marketing sections above could come back later as an **opt-in,
  per-vendor homepage builder** (already anticipated in the blueprint's Phase 2
  scope), reusing their existing code once made category-agnostic.
- **No image upload anywhere in the buyer-facing flow** (matches the portal's
  branding form — URLs only for now).
- **Still `HashRouter`** — `#/` URLs, fine for Phase 1B verification, blocking
  for real SEO and for true per-vendor subdomains. That's explicitly Phase 1D.
- **No favicon/tab-title per vendor** — `index.html`'s `<title>Aqua Watch</title>`
  and `/fevicon.png` are still static; a vendor's tab identity doesn't update.
  Small, deferred — needs either per-route `document.title` writes or a
  server-rendered `<head>` (ties into the Phase 3 SEO/SSR item).
- **No coupons, reviews, wishlist** — unchanged from the original gap analysis; still Phase 2/3.

## Testing note

Verified end-to-end against the **live** local backend with a real hosted site
(created → approved → activated → branded → source attached), through an
actual running Vite dev server driven by a real browser — not just code
review: Home (branding/hero/announcement/dynamic categories/product grid),
product detail (gallery/price/size gate/add-to-cart/buy-now/similar items),
cart (qty/remove/total), checkout (guest form *and* quick-buy, both landing on
a correct WhatsApp-linked success screen), account/login page, a vendor policy
page, and the "store not found" page for an unknown slug. Confirmed the two
resulting orders existed correctly in the database via the admin API, then
deleted all test data and confirmed the database was left clean.

## Depends on / feeds into

Pure client of the Phase 1A API — no further backend changes were needed.
Phase 1D (hosting + `BrowserRouter` + wildcard DNS) is what turns
`?store=slug` into a real `slug.yourplatform.com` in production.
