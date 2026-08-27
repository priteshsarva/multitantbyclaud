# Test Credentials & Manual Verification Guide

Everything you need to log in, drive both storefronts, and verify the whole
Phase 1 flow yourself. All accounts are real, live in the portal Supabase, and
provisioned as of **2026-08-20**.

> Delete these accounts before going live — use them for verification and
> demos only.

---

## 1. Start everything locally

```bash
cd UltimateScrapperV2 && node index.js
```

```bash
cd portal-app-clean && npm run dev
```

```bash
cd site && npm run dev
```

- Backend: <http://localhost:3002>
- Portal (admin + vendor): <http://localhost:5174>
- Storefront (buyer): <http://localhost:5175>

Both dev servers are pre-configured in `.claude/launch.json`, so
`preview_start` works too. The storefront `.env` already points at the deployed
backend for production; add a `site/.env.local` with
`VITE_BASE_URL=http://localhost:3002` when running fully local. See
[`site/.env.example`](../site/.env.example) for every documented env var —
`VITE_BASE_URL` is the one you switch to test local vs deployed backend.

---

## 2. Accounts

### Super-admin (portal + all sites)

| Field | Value |
|---|---|
| URL | <http://localhost:5174> |
| Email | `priteshsarva9825@gmail.com` |
| Password | `Sarva17@@940` |

You see the whole platform: every enrollment, every hosted storefront, every
order across every vendor. Nav shows: Approval queue, Sources, Enrollments,
**Storefronts**, **Storefront orders**, Clients, Email, Payments.

### Vendor 1 — Aqua Watch (Commerce preset)

| Field | Value |
|---|---|
| Portal URL | <http://localhost:5174> |
| Vendor email | `aqua.vendor@test.com` |
| Vendor password | `AquaPass123` |
| Storefront URL (local) | <http://localhost:5175/?store=aqua-watch> |
| Brand colour | Green — `#0E7A5F` |
| Preset | Commerce classic (6 sections: banner → categories → best sellers → features → testimonials → CTA) |
| Product source | `awwaltime11` (1627 watches locally) |
| WhatsApp checkout number | `+919000000101` (fake — WhatsApp handoff still works, just goes nowhere) |
| Contact address | Shop 12, MG Road, Bengaluru 560001 |

### Vendor 2 — Timeless & Co (Showcase preset)

| Field | Value |
|---|---|
| Portal URL | <http://localhost:5174> |
| Vendor email | `timeless.vendor@test.com` |
| Vendor password | `TimelessPass123` |
| Storefront URL (local) | <http://localhost:5175/?store=timeless-co> |
| Brand colour | Brass — `#A87A2E` |
| Preset | Showcase (5 sections: banner → text → picks grid → banner pair → features) |
| Product sources | `awwaltime11` + `watch-enterprise17` (multi-source demo) |
| WhatsApp checkout number | `+919000000202` (fake) |
| Contact address | Studio 4B, Bandra West, Mumbai 400050 |

### Shopper accounts (one on each storefront)

Shopper accounts are **scoped per vendor** — the same email may exist on both
stores independently, and a token from one store is rejected on the other
(verified live). Both have a saved default address.

| Store | Email | Password | Name |
|---|---|---|---|
| aqua-watch | `buyer1@test.com` | `BuyerPass123` | Ananya Kumar |
| timeless-co | `buyer2@test.com` | `BuyerPass123` | Vikram Rao |

Log in via the person icon in the storefront nav (`/account`).

---

## 3. Manual verification checklist

### 3a. Super-admin flow

1. Log in as super-admin → nav shows **Storefronts** and **Storefront orders**.
2. **Storefronts** → both vendor sites are listed as **Active**, with owner email, order count, `expires` date, and action buttons (Pause / Delete).
3. Click the slug link on either row → opens the live storefront in a new tab.
4. **Storefront orders** → shows every order across both vendors, filterable by status. (Empty right now — place one below.)

### 3b. Vendor flow (Aqua Watch)

1. Sign out of admin. Sign in as `aqua.vendor@test.com` / `AquaPass123`.
2. **My storefront** → click "Aqua Watch" card → detail view opens. (You can create more than one storefront per vendor with **New storefront** — each gets its own slug/URL.)
3. **Products** panel (top) — pick which product sources feed this store from the full active-source catalogue (filterable, grouped by shoes/watches). "1 selected" for Aqua Watch (`awwaltime11`). Tick/untick and Save → the storefront's products change on next reload.
4. **Navigation & front page** panel — choose which categories appear in the storefront header menu and which show on the home page, with a custom menu label each. Leave everything unticked to auto-show all categories (the default).
5. **Homepage layout** panel shows the two presets. By default **no preset is applied** — the store uses the original Aqua Watch layout (video hero → categories → collections). Applying "Commerce classic" or "Showcase" switches the home to the section builder; clearing it (empty sections) returns to the original layout.
4. Below it: **Custom domain** panel — type any domain (e.g. `yourbrand.com`) and Save; the DNS-setup instructions appear. Only admins can verify; unverified domains don't resolve. (Phase 2)
5. **Branding** form: name, logo, WhatsApp, colours, address, socials, hero, announcement, about, policy blocks. Edit anything, hit Save → reload the storefront → change is live.
6. **Pricing markup** section (new in Phase 2): edit the four bands OR "Reset to platform default" to fall back to the built-ins.
7. **Analytics pixels** (new in Phase 2): enter a GA4 ID or Meta Pixel ID; the loader scripts get injected into every storefront page for that vendor.
8. **Orders** panel with status filters; click an order to expand line items + address; change status via the dropdown.

### 3b′. Super-admin custom-domain verification (Phase 2)

1. As super-admin, open **Storefronts**.
2. Each site row now shows its custom domain (with a ✓ verified badge or an "unverified" chip) when set.
3. If a vendor has added a custom domain but it's unverified, a **Verify domain** button appears. Clicking it fetches `https://<domain>/` and confirms the storefront's HTML marker is served. If DNS isn't pointed yet, you'll get a clear error.

### 3c. Buyer journey (Aqua Watch, ~5 minutes)

Follow this and every buyer-side feature gets exercised:

1. Open <http://localhost:5175/?store=aqua-watch>.
2. Confirm the storefront shows (the **original Aqua Watch layout**, the default): header nav with **Home · Categories ▾ · Login · Wishlist · Cart**, announcement bar ("Free shipping…"), green "Chat with Aqua Watch" promo bar, hero ("Timeless. Effortless." + Shop Now), "Browse Our Categories" (only when >1 category), per-category "Our Best … Collection" carousel with strike-through prices + −% badges, "New Arrivals" grid, and "View All Products". (Apply a preset in the portal to see the section-builder layout instead.)
3. Click **Watches** in the nav → category page loads with 24 products + "Load more".
4. Open the search icon top right → type "casio" → results filter.
5. Click any in-stock product → detail page with image gallery + qty stepper + Add to cart + Buy now + Wishlist heart button + similar-items rail.
6. **Wishlist**: tap the ♡ on any product card OR the heart button on the detail page — the wishlist count badge on the nav updates. Visit `/wishlist` to see the saved items. (Phase 2)
7. **Tenant preservation** (Phase 2 bug fix): from any subpage, manually type `http://localhost:5175/` (no `?store=`) and hit Enter — the site keeps rendering as Aqua Watch because the slug is cached in sessionStorage.
7a. **Deep-link URL sharing** (Phase 2.1): browse to any product, copy the URL from the address bar — you'll see it now contains `?store=aqua-watch` automatically. Paste that URL into a fresh browser (or incognito window) — the product loads correctly. Right-click a product card → "Copy link address" also gives you a fully-scoped, shareable URL. Same holds for `/cart`, `/wishlist`, `/checkout`, any category, etc.
7b. **Live product refresh** (Phase 2.1): open any product page → the backend's `/dev/update-single-product` endpoint fires in the background (visible in the network tab). Stock/price re-scrapes into SQLite for the next visitor.
8. Add 2–3 different products to the cart (`/cart` shows the total).
9. Click Cart icon → adjust qty, remove one → **Proceed to checkout**.
10. On the checkout page: log in as `buyer1@test.com` / `BuyerPass123` in `/account` first for the "saved address" experience — OR check out as guest and fill the address form.
11. Click **Place order** → the success screen shows an `ORD-######` number + "Complete on WhatsApp" button linking to the vendor's number with a prefilled itemized message. If you entered a buyer email, a confirmation email fires (Phase 2, needs SMTP configured in the admin's Email settings).
12. Go to `/account` → your order shows in **Order history**.
13. Sign out, log back into the portal as `aqua.vendor@test.com` → the order appears in the vendor's Orders panel with your address + line items; change status to Confirmed. The vendor notification email fires on order creation (Phase 2).
14. Log in as super-admin → **Storefront orders** shows the same order with `store_name: "Aqua Watch"` attached.

### 3d. Two-tenant isolation checks (fast)

- Buyer 1's token on store 2 → open <http://localhost:5175/?store=timeless-co> after logging into aqua-watch → `/account` prompts a fresh login (customer token is per-slug, verified).
- Vendor 1 sees only her own storefront in the portal, not Timeless & Co's.
- Products on Aqua Watch are Casio/Rolex/Seiko; on Timeless & Co are Michael Kors / Swarovski / Fossil — different sources, different catalogues.

### 3e. Preset picker

- Default (no preset) = the original Aqua Watch layout. Applying a preset overrides it with the section builder.
- In vendor 1's portal, apply the "Showcase" preset → reload the storefront → same store, different layout (video-style hero, single grid instead of category tiles, promo pair, no testimonials/CTA).
- Switch to "Commerce classic" → the 6-section commerce layout (banner → categories → best sellers → features → testimonials → CTA). Both are section-builder presets, distinct from the original default.

---

## 4. Known caveats

- **`databases/shoes.db` reads fine** (~83k products, ~12k in stock) — an earlier "SQLITE_CORRUPT" note was a transient read while the DB was mid-write/locked with the server down, not real corruption. A store shows **no shoes only when its attached shoe source has no in-stock products** (the storefront hides out-of-stock by design). Check in-stock counts per source with a `productFetchedFrom LIKE '%<search_key>%' AND availability IN ('1','true')` query before assuming a data problem. Note the legacy `/product/:id` route ignores source + stock rules, so it can show OOS products a storefront correctly won't.
- **The WhatsApp checkout numbers are fake**: `+91 90000 00101` / `+91 90000 00202`. The order-creation flow still runs correctly and the WhatsApp handoff URL is built — clicking "Complete on WhatsApp" opens WhatsApp Web with the message pre-filled, it just doesn't reach a real number. Change either vendor's WhatsApp in the portal Branding form to test with your own number.
- **A "?" in an announcement bar** means a non-ASCII character (₹, ·, curly quote) was mangled on its way in through the JSON body. Re-save from the portal Branding form via the browser — the portal encodes correctly.
- **Local dev tenant is via `?store=` query param**, not subdomain. Production uses wildcard DNS (see `docs/phases/phase-1d-deploy.md`).
- **HashRouter → BrowserRouter switch is done in code**, so deep links like `/p/watches/28440` load correctly, but need the hosting SPA-fallback config (already provided in `site/public/_redirects` + `site/vercel.json`) once deployed.

---

## 5. Full teardown (when you're done)

Removes both vendor accounts (cascades everything they own — enrollments,
site_settings, orders, customers, addresses, sources attachments):

```bash
cd UltimateScrapperV2 && node -e "
import('dotenv/config').then(async () => {
  const { Pool } = await import('pg');
  const pool = new Pool({ connectionString: process.env.DATABASE_URL, ssl: { rejectUnauthorized: false } });
  const r = await pool.query(\"delete from users where email in ('aqua.vendor@test.com','timeless.vendor@test.com') returning email\");
  console.log('deleted:', r.rows.map(x => x.email));
  await pool.end();
});
"
```
