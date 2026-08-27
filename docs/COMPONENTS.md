# Storefront Section Library — Component Reference

> **Default home = the original Aqua Watch layout, not the section builder.**
> [`StoreHome`](../site/src/pages/store/StoreHome.jsx) renders
> [`OriginalHome`](../site/src/pages/store/OriginalHome.jsx) (video/image hero →
> Browse Categories → per-category "Best Collection" rail + "New Arrivals" grid)
> whenever `site_settings.sections` is empty — which is the default for a new
> site. The section library below is an **optional override**: applying a preset
> or saving a `sections` array switches the home to the data-driven builder.

When `site_settings.sections` is set, the home page becomes **data-driven** —
it reads that array from the vendor's config and renders each entry through the
section registry in [`site/src/components/sections/registry.jsx`](../site/src/components/sections/registry.jsx).

Each entry is a plain JSON object with this shape:

```jsonc
{
  "type":  "banner",       // required — one of the registered types below
  "title": "New Arrivals", // optional — renders a SectionTitle above the section
  "wrap":  {               // optional — SectionWrap props (bg, padding, dark, overlay, bg_color)
    "bg_color": "#F7F8FB",
    "padding":  "md"
  },
  "props": {               // required for most types — the props for this section
    "height":     "md",
    "text_color": "light"
  }
}
```

A vendor's homepage is just an array of these:

```json
[
  { "type": "banner", "props": {"height": "lg"} },
  { "type": "categories", "title": "Shop by Category" },
  { "type": "products", "title": "Best Sellers" }
]
```

Presets that ship two ready-made layouts live in
[`site/src/components/sections/presets.js`](../site/src/components/sections/presets.js)
and its server mirror
[`UltimateScrapperV2/portal/storefrontPresets.js`](../UltimateScrapperV2/portal/storefrontPresets.js).
Vendors apply one with `POST /portal/hosted-sites/:id/presets/:preset` (also
exposed as a picker in the portal SettingsPanel).

---

## Section wrapper — `wrap` (applied to every section)

Every entry may include an optional `wrap` object controlling the surrounding
`<section>` element. Renders through [`SectionWrap.jsx`](../site/src/components/sections/SectionWrap.jsx).

| Prop | Type | Default | What it does |
|---|---|---|---|
| `bg` | string (url) | — | Full-cover background image |
| `bg_color` | string (css color) | — | Background color |
| `dark` | boolean | `false` | Switches text to light for dark backgrounds |
| `padding` | `"none"` \| `"sm"` \| `"md"` \| `"lg"` | `"md"` | Vertical padding (6/12/20 in Tailwind units) |
| `overlay` | number 0–100 | `0` | Dark scrim opacity over `bg` image |
| `contained` | boolean | `true` | Constrains content to `max-w-screen-xl` — set false for full-bleed |

Banners already occupy the full viewport width, so the registry skips
`SectionWrap` on `banner` entries (`wrap` on a banner entry is ignored).

---

## Section title — `title` on the entry

If the entry has a `title` string, the registry renders a
[`SectionTitle`](../site/src/components/sections/SectionTitle.jsx) above the
section — a centered, uppercase, letter-spaced heading with dash flourishes on
either side (Flatsome `[title style="center"]` equivalent). Add
`subtitle` to the entry for a small caption above the title.

---

## Registered section types

### 1. `banner` — hero / promo banner

Flatsome `[ux_banner]` equivalent. Full-bleed banner with bg image or looping
video, dark scrim, centered title + subtitle + CTA button.

**All content fields are optional** — when empty, they fall back to the
vendor's own `site_settings.hero` and `store_name`, so the same preset works
for every vendor.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `bg` | url string | *→ `hero.image_url`* | Background image |
| `video` | url string | *→ `hero.video_url`* | Looping mp4 (takes over from `bg`) |
| `overlay` | 0–100 | `30` | Dark scrim opacity |
| `height` | `"sm"` \| `"md"` \| `"lg"` | `"md"` | 280 / 420 / 560 px minimum |
| `text_align` | `"left"` \| `"center"` \| `"right"` | `"center"` | |
| `text_color` | `"light"` \| `"dark"` | `"light"` | |
| `title` | string | *→ `hero.title` / `store_name`* | |
| `subtitle` | string | *→ `hero.subtitle`* | |
| `cta_text` | string | *→ `hero.cta_text` / "Shop now"* | |
| `cta_link` | string | *→ `hero.cta_link` / first category* | React Router `to` path |

```json
{ "type": "banner", "props": { "height": "lg", "overlay": 40, "text_color": "light" } }
```

---

### 2. `banner_grid` — 2- or 3-tile promo grid

Flatsome `[ux_banner_grid]` — a row of overlapping-image promo tiles, each
clickable. Good for "New / Best sellers / Sale" split.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `tiles` | array | `[]` | Each `{ image, title, subtitle, cta_text, cta_link, overlay }` |
| `spacing` | `"collapse"` \| `"small"` \| `"normal"` | `"small"` | Gap between tiles |

```json
{
  "type": "banner_grid",
  "wrap": {"padding": "md"},
  "props": {
    "tiles": [
      {"title": "New Arrivals", "subtitle": "Fresh in", "cta_text": "Shop", "cta_link": "/c/watches"},
      {"title": "Best Sellers", "subtitle": "Customer favourites", "cta_text": "Explore", "cta_link": "/"}
    ]
  }
}
```

---

### 3. `categories` — category tile grid

Flatsome `[ux_product_categories]`. Renders one tile per vendor category with
a thumbnail auto-sourced from that category's first product (one API call per
category, cached in component state).

| Prop | Type | Default | Notes |
|---|---|---|---|
| `categories` | string[] | *→ `config.categories`* | Restrict which categories to show |
| `thumbnail_from` | `"first_product"` \| `"placeholder"` | `"first_product"` | |

```json
{ "type": "categories", "title": "Shop by Category", "wrap": {"padding": "lg"} }
```

---

### 4. `products` — product carousel or grid

Flatsome `[ux_products]`. Fetches from `/store/:slug/products` and renders
either a swipeable rail (default) or a static grid.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `category` | string | *→ first category* | Restrict to one category |
| `limit` | number | `8` | Product count |
| `style` | `"rail"` \| `"grid"` | `"rail"` | Carousel vs static grid |
| `view_all` | boolean | `true` | Show "View all →" link |

```json
{ "type": "products", "title": "Best Sellers", "wrap": {"padding": "md"}, "props": { "style": "rail", "limit": 8 } }
```

---

### 5. `features` — icon + title + text row

Flatsome `[featured_box]` row. The "Free shipping / Secure / Returns / Support"
strip. Icons come from a whitelist of Lucide icons — see the imported list at
the top of [`FeatureIcons.jsx`](../site/src/components/sections/FeatureIcons.jsx).

Available icon names: `Truck`, `ShieldCheck`, `RotateCcw`, `Headphones`,
`Package`, `Award`, `Gift`, `Heart`, `Star`, `Clock`, `MessageCircle`,
`Lock`, `ThumbsUp`, `Zap`, `Tag`. Unknown names fall back to `Package`.
(Whitelisted deliberately — a `import * as` from Lucide would pull ~800KB of
unused icons into the storefront bundle.)

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | array | `[]` | Each `{ icon: string, title: string, text?: string }` |
| `columns` | `2` \| `3` \| `4` | `4` | Columns on desktop |

```json
{
  "type": "features",
  "wrap": {"padding": "md", "bg_color": "#F7F8FB"},
  "props": {
    "items": [
      {"icon": "Truck", "title": "Fast Shipping", "text": "Delivered in 3–5 days"},
      {"icon": "ShieldCheck", "title": "Secure Checkout"},
      {"icon": "RotateCcw", "title": "Easy Returns"},
      {"icon": "Headphones", "title": "Real Support"}
    ],
    "columns": 4
  }
}
```

---

### 6. `testimonials` — 3-across quote cards

Flatsome `[testimonials]`.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `items` | array | `[]` | Each `{ quote, author, role?, avatar?, stars? (1-5, default 5) }` |

```json
{
  "type": "testimonials",
  "title": "Loved by our customers",
  "wrap": {"padding": "lg"},
  "props": {
    "items": [
      {"quote": "Genuinely felt like a boutique experience.", "author": "Priya S.", "role": "Bengaluru", "stars": 5}
    ]
  }
}
```

---

### 7. `text` — plain rich-text block

Flatsome `[text_box]`. Paragraphs split on double newline, single newlines
become `<br>`. **No HTML injection** — vendor content is rendered as text only.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `text` | string | — | Body text (multi-paragraph via `\n\n`) |
| `align` | `"left"` \| `"center"` \| `"right"` | `"center"` | |
| `max_width` | css string | `"640px"` | |

```json
{ "type": "text", "wrap": {"padding": "lg"}, "props": { "text": "A curated selection of pieces we personally love." } }
```

---

### 8. `cta` — call-to-action strip

Bold single-color banner with title + button. Uses `--store-primary` unless
`bg_color` is set.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | string | — | |
| `subtitle` | string | — | |
| `cta_text` | string | — | |
| `cta_link` | string | — | React Router path |
| `bg_color` | css color | *→ `--store-primary`* | |

```json
{
  "type": "cta",
  "wrap": {"padding": "md"},
  "props": {
    "title": "Not sure what to pick?",
    "subtitle": "Chat with us on WhatsApp — we'll help you find the right piece.",
    "cta_text": "Talk to us", "cta_link": "/"
  }
}
```

---

### 9. `countdown` — days/hours/minutes/seconds to a target date

Flatsome `[ux_countdown]`. Renders nothing if the date is in the past or missing.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `until` | ISO date string | — | e.g. `"2026-12-31T23:59:59"` |
| `title` | string | — | Optional heading |
| `subtitle` | string | — | Optional small caption |

```json
{ "type": "countdown", "wrap": {"padding": "md"}, "props": { "until": "2026-12-31T23:59:59", "title": "New Year Sale ends in" } }
```

---

## Extending — adding a new section type

Three files touched, in this order:

1. **Create the component**: `site/src/components/sections/YourSection.jsx`. Take
   props and render. Read from `useStore()` if you need vendor config (e.g. slug, categories).
2. **Register it**: add an entry to the `REGISTRY` object in
   [`registry.jsx`](../site/src/components/sections/registry.jsx). Set
   `wrap: true` unless it's a full-bleed banner.
3. **Optional — add to presets**: update
   [`presets.js`](../site/src/components/sections/presets.js) AND its server
   mirror [`storefrontPresets.js`](../UltimateScrapperV2/portal/storefrontPresets.js)
   if you want it in a shipped preset. The mirror exists so the "apply preset"
   API doesn't need to bundle the storefront code — keep them in sync when
   editing preset content.
4. **Optional — document it**: add a section here.

No backend change is needed unless the section pulls data from a NEW endpoint —
existing sections (`products`, `categories`) already reuse `/store/:slug/*`.

---

## Not (yet) built — future extension points

Sections from Flatsome that would be genuinely useful but need real design
work, not deferred here for laziness:

- **`ux_slider`** — image/banner carousel (not the product carousel, which is `products`). Would use Swiper (already installed).
- **`accordion`** — FAQ-style expandable list. Small.
- **`price_table`** — pricing plan cards. For B2B-flavoured stores.
- **`ux_gallery`** — lightbox photo grid. For a lookbook-style store.
- **`ux_instagram_feed`** — vendor's Instagram feed. Needs an Instagram token flow — Phase 2+.
- **`google_maps`** — embedded map for physical stores. Skip until requested; needs a maps key.

Each would fit the same registry pattern — new entry, new component, no
schema change needed.
