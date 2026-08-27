# Phase 1D — Deploy — 🟡 Code ready; hosting/DNS is the owner's action

## What it does

Prepares `site/` to actually be reachable at `<slug>.yourplatform.com` in
production. Two things had to be true for that: real paths (not `#/...`) so a
wildcard host can route by hostname and search engines can index pages, and
every path served by the host falling back to `index.html` so React Router can
take over client-side.

**Done:**
- [`src/main.tsx`](../../site/src/main.tsx) — `HashRouter` → `BrowserRouter`. `resolveSlug()` in `storeApi.js` needed no change: `window.location.search`/`hostname` work identically under either router.
- [`public/_redirects`](../../site/public/_redirects) — SPA fallback for **Netlify or Cloudflare Pages** (same file format, either works with zero extra config).
- [`vercel.json`](../../site/vercel.json) — the equivalent rewrite for **Vercel**.
- Confirmed the backend needs **no CORS change**: `index.js` already sets `origin: '*'` with `credentials: false`, and the customer auth (Bearer token, not cookies) is unaffected by the credentials flag either way.

**Cannot be done by me:** actually provisioning a host or DNS record — that
needs the owner's own hosting account and domain registrar access, not
something achievable from this environment. What follows is the exact,
minimal set of steps to do it.

## What the owner needs to do

1. **Pick a host** — Netlify, Cloudflare Pages, or Vercel all support wildcard
   subdomains and the config above is ready for any of the three. Cloudflare
   Pages is free and has straightforward wildcard DNS if the domain is already
   on Cloudflare.
2. **Connect the `site/` repo**, build command `npm run build`, output dir `dist`.
3. **Set the env var** `VITE_BASE_URL` to the production backend URL (the same
   one already in `site/.env` — `https://ultimatescrapperv2.onrender.com`, or
   wherever the backend is deployed once it includes the Phase 1A routes).
   Do **not** set `VITE_DEV_STORE` in production — the subdomain carries the
   tenant.
4. **DNS**: add a wildcard record — `*.yourplatform.com` → the host's target
   (a CNAME to the host's edge, per that host's own instructions). Add the
   custom domain in the host's dashboard with wildcard enabled.
5. **Stop using `npm run deploy` (gh-pages)** for this app going forward —
   gh-pages cannot SPA-fallback, so any deep link (`/p/watches/123`) would
   404 on refresh under `BrowserRouter`. The `public/CNAME` file
   (`www.theaquawatch.com`) is a gh-pages artifact; the new host's dashboard
   is where a custom domain gets configured instead — it can be deleted once
   the migration is confirmed working, or left harmlessly unused until then.
6. Once one storefront is live at its real subdomain, re-run the Phase 1A/1B
   manual test flow against it: `<slug>.yourplatform.com` → browse → cart →
   checkout → WhatsApp handoff — this is the first time it'll be end-to-end
   real (no `?store=` query param, no localhost).

## What it needs more

- **Custom domains per vendor** (a vendor's own `aquawatch.com`, not just
  `aquawatch.yourplatform.com`) — `enrollments.domain` already stores it, but
  nothing resolves by it yet. Phase 2, per the blueprint.
- **SSL for wildcard + any future custom domains** — handled automatically by
  Netlify/Vercel/Cloudflare Pages for their own subdomains; custom domains need
  their own certificate step when Phase 2 adds them.
- **A local-dev quirk worth knowing**: under `BrowserRouter`, client-side
  `<Link>` navigation doesn't carry the `?store=` query param forward (the
  slug is resolved once into React state on load, so this doesn't break
  browsing) — but a **hard refresh on a deep link** during local dev
  (`localhost:5175/p/watches/123`) loses the tenant and needs `?store=slug`
  re-added manually, or `VITE_DEV_STORE` set in `.env.local`. Not an issue in
  production, where the subdomain carries the tenant on every request.

## Depends on / feeds into

This is the last Phase-1 piece — once live, a vendor's storefront is reachable
by real people, not just `localhost`. Phase 2 (payments, custom domains,
pricing UI) builds on top of a deployed, working Phase 1.
