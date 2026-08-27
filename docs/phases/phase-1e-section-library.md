# Phase 1E — Flatsome-inspired Section Library + Preset Layouts — ✅ Done (2026-08-20)

## What it does

Adds an **optional** data-driven page builder whose vocabulary mirrors
WooCommerce Flatsome's shortcode system. When a vendor applies a preset (or
saves a `sections` array), their home page becomes an ordered array of
`{ type, props }` sections stored in `site_settings.sections`, rendered through
a component registry — layout change live on next reload, no deploy.

> **Not the default.** As of the "original layout" work, a new site's home
> renders [`OriginalHome`](../../site/src/pages/store/OriginalHome.jsx) (the
> original Aqua Watch layout) whenever `sections` is empty. The section builder
> below only takes over once a preset/sections is applied. Both test stores
> currently use the original layout (empty `sections`).

**New reusable components** ([site/src/components/sections/](../../site/src/components/sections)):
`SectionWrap`, `SectionTitle`, `Banner`, `BannerGrid`, `CategoriesSection`,
`ProductsSection`, `FeatureIcons`, `Testimonials`, `RichText`, `CTABanner`,
`Countdown`, plus `registry.jsx` (dispatcher) and `presets.js` (shipped layouts).
Every component is tenant-generic — no hardcoded brand, no fixed category set;
`Banner` even falls back to `site_settings.hero` when its own content props
are empty, which is what lets one preset work for every vendor.

**Two shipped presets**, mirrored server-side so the "apply preset" API
doesn't need to bundle the storefront code:
- **Commerce classic** (6 sections) — Banner → Categories → Best sellers rail → Features strip → Testimonials → CTA. The everything-you-need home.
- **Showcase** (5 sections) — Big banner → Text intro → Product grid → Banner pair → Features. Gallery-style for a curated storefront.

**Backend**: new `site_settings.sections jsonb` column (`portal/hosted_storefront_sections.sql`, applied live), extended the settings PUT endpoint to accept it, extended `/store/:slug/config` to return it, and added two new endpoints — `GET /portal/hosted-sites/presets` and `POST /portal/hosted-sites/:id/presets/:preset` — so the portal can list and apply presets.

**Portal**: added a `HomepagePresetPanel` above the branding form. Vendor sees the shipped presets as cards and applies one with a click. Applied preset is visibly highlighted.

**Two live test sites** created and verified in a real browser (see [TEST_CREDENTIALS.md](../TEST_CREDENTIALS.md)):
- **Aqua Watch** — green theme, commerce preset, source `awwaltime11` (Casio/Rolex/Seiko).
- **Timeless & Co** — brass theme, showcase preset, two sources (`awwaltime11` + `watch-enterprise17`) demonstrating multi-source (Michael Kors / Swarovski / Fossil).

Cross-tenant isolation confirmed: buyer1's token is rejected on timeless-co, and the two stores show different product catalogues even though they share a source.

## What it needed (and got)

- **A way to make the storefront look like real ecommerce without hardcoding it** — solved by the section registry + two shipped presets. The `<Banner>` fallback-to-`hero` pattern is what makes presets tenant-generic instead of "you must configure 15 props before your homepage looks like anything."
- **Reusable shortcode-with-settings feel** — every registered type has typed props with sensible defaults, documented in [docs/COMPONENTS.md](../COMPONENTS.md) shortcode-style (props table + JSON example). Adding a section = new component file + one line in the registry.
- **Proof, live** — two real vendor accounts + two shopper accounts, provisioned and verified in an actual browser (both stores loaded, both showed distinct branding and distinct product catalogues, both showed the right sections rendered from real config, cross-tenant token isolation confirmed).

## Real bugs found and fixed while building

- **Star-import bloat**: `FeatureIcons` originally did `import * as Icons from "lucide-react"` so icon names could resolve dynamically from vendor JSON. That pulled ~800KB of unused SVG icons into the storefront bundle (`index.js` went from 858KB → 1.7MB). Fixed with a named whitelist of 15 common icons at the top of the file — a vendor unknown-icon falls back to `Package`. Bundle is back to 875KB.
- **Preset JSON couldn't reach templated fields**: a preset shipped with `{ props: { title: "${config.store_name}" } }` would just print the literal string. Solved without a templating engine: `Banner`, `CategoriesSection`, `ProductsSection` fall back to store config when their content props are blank — presets ship *empty* those fields and each vendor's own settings fill them in. No template language, no injection risk, and the same preset genuinely works for every vendor.
- **Non-ASCII characters mangled through shell/JSON in the provisioning script**: `₹` became `?` in the announcement bar of both test sites. Not a product bug — the portal SPA saves correctly through the browser — but noted in [TEST_CREDENTIALS.md](../TEST_CREDENTIALS.md) as a caveat for anyone provisioning via `curl` on Git Bash. Fixed the stored values directly.

## Deliberate scope decisions

- **Two presets, not ten.** Enough to demo the picker + prove the pattern. Adding more is one file each; nothing structural to build.
- **~11 section types, not all 40 of Flatsome's.** The remaining ones (`ux_slider`/`accordion`/`ux_gallery`/`price_table`) fit the same registry pattern — noted as future extension points at the bottom of [COMPONENTS.md](../COMPONENTS.md).
- **No visual drag-and-drop editor.** Vendor UI is a preset picker + the existing branding form; the sections JSON itself is edited via the API for now. A visual editor is a Phase 2/3 project on its own — noted below.

## What it needs more

- **Visual section editor in the portal** — currently vendors pick from shipped presets or edit `sections` via the API. A drag-drop reorder + per-section props form is the natural next feature. All the wiring's ready (settings PUT already accepts a `sections` array).
- **More preset variety** — three or four more shipped layouts (magazine-style, single-product-focus, marketplace-style) would cover most real vendor personas.
- **The shipped Testimonials preset uses stock quotes** ("Priya S., Bengaluru") — a vendor should be able to edit those, either through the visual editor above or a dedicated "Testimonials" panel in the branding form.
- **The Countdown section takes an ISO date** — no date picker in the portal yet; vendors would type it. Small UI addition when the visual editor lands.
- **Bundle is 875KB (263KB gzipped)** — fine for now, but code-splitting per route (`React.lazy` on `StoreProductPage`/`CheckoutPage`/`AccountPage`) would nearly halve first-paint.

## Depends on / feeds into

Client of Phase 1A (`/store/:slug/*` config now includes `sections`; Phase 1A's PUT accepts it via one new field in the whitelist). Portal UI (Phase 1C) got one new panel. Nothing about buyer checkout / accounts / orders changed — Phase 1E is purely presentation. The visual section editor mentioned above is the natural Phase 2 extension.
