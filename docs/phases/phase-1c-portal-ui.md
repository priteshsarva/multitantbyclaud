# Phase 1C — Portal UI (vendor + admin) — ✅ Done (2026-08-20)

## What it does

Turns the backend from Phase 1A into something a vendor or admin can actually
use — no more curl. Adds three screens to the existing portal SPA
(`portal-app-clean/`), following its established conventions exactly (inline
styles, the `C`/`Card`/`Btn`/`Badge`/`Field` kit in `ui.jsx`, flat `nav`-state
routing, one file per screen with subcomponents inline).

**Vendor** — [`screens/MyStorefronts.jsx`](../../portal-app-clean/src/screens/MyStorefronts.jsx), nav entry "My storefront":
- List of the vendor's hosted sites + a "New storefront" modal (store name, optional slug).
- Click into one for a detail view: a full **branding form** (name, logo, WhatsApp checkout number, brand colour, contact info, address, socials, hero, announcement bar, about, four policy blocks) that saves straight to `site_settings` — live on the storefront on next load, no deploy.
- An **orders panel** on the same page: status filter chips, expandable rows showing line items + delivery address, and a status dropdown (`pending → confirmed → shipped → delivered/cancelled`).

**Admin** — two new nav entries:
- [`screens/AdminHostedSites.jsx`](../../portal-app-clean/src/screens/AdminHostedSites.jsx) ("Storefronts") — every hosted site across every vendor, with owner email, order count, and status-appropriate actions (Approve/Reject while pending, Activate once approved, Pause/Resume once active, Delete always).
- [`screens/AdminOrders.jsx`](../../portal-app-clean/src/screens/AdminOrders.jsx) ("Storefront orders") — every order from every vendor, filterable by status, expandable to line items.

**Supporting additions:**
- `api.js` — 13 new client methods, following the file's existing `req()` wrapper pattern.
- `ui.jsx` — one new helper, `storeUrl(slug)`, builds the public link for a site from `VITE_STORE_BASE_URL` (a `{slug}`-templated env var, so dev — `?store=` query param — and production — real subdomain — need no code change, just an env swap).
- `.claude/launch.json` — lets `preview_start` boot the portal SPA by name for future sessions.
- One backend addition discovered while building this: `DELETE /portal/admin/hosted-sites/:id` and `GET /portal/admin/orders/:id` didn't exist yet — added both (small, symmetric with existing endpoints) since the admin screens genuinely needed them.

## What it needed (and got)

- **Zero changes to the existing approval queue.** `AdminQueue.jsx` already lists *all* pending enrollments generically — a pending hosted site shows up there and Approve/Activate/Reject already work on it, unmodified. Confirmed live in testing.
- **Proof, not just code review.** Ran the whole thing through an actual browser (not just reading the source): signed up a fresh test vendor, created a storefront through the UI, saved a full branding form and confirmed the PUT round-tripped every field correctly (including nested JSON — theme, address, socials, hero, policies), then switched to the admin account and drove a pending site through Approve → Activate → Pause, watching the status and available actions update correctly at each step. Test data (one user, two enrollments) was then deleted and the cascade was verified to leave no orphans.
- **A page-truthing bug in my own test process, not the product**: browser autofill silently substituted different values into the signup form on a retry, creating an unintended test account ("rishi@gmail.com") instead of the one I'd typed. Worth knowing for future automated browser testing in this app — clear the form or use unique/unlikely values to dodge autofill guesses.

## What it needs more

- **No "quick preview" for a vendor before the site is live.** A pending/paused site shows a banner explaining it isn't reachable yet, but there's no in-portal preview render — the vendor only sees it for real once Phase 1B's storefront exists and the site is active.
- **Branding form has no image upload** — logo/hero are URL fields only. Fine for a vendor who already has hosted images; a real upload flow is a later nicety, not blocking.
- **No pricing-bands editor** — deliberately deferred to Phase 2 per the blueprint; the form doesn't touch `site_settings.pricing` at all, so vendors get the platform default markup until that ships.
- **`AdminHostedSites` has no drill-in** — it's a flat list with lifecycle buttons, no way to view/edit a *vendor's* branding from the admin side. That's an intentional Phase 1 boundary (branding is the vendor's job), but worth revisiting if admins end up needing to fix a vendor's typo for them.
- **No toast/undo on destructive actions** — Delete uses a plain `confirm()`, matching the rest of this codebase's existing convention (see `MySites.jsx`), not a gap specific to this work.

## Depends on / feeds into

Pure client of the Phase 1A API — no backend changes were strictly required to build this (the two small additions above were incremental, not corrective). Phase 1B (the storefront SPA) is what actually makes the `storeUrl()` links in these screens resolve to something real; until then, "View site" is a preview of a page that doesn't exist yet.
