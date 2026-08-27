# E‑Commerce Platform Feature Research & Gap Analysis

**Prepared:** 2026-07-28
**Purpose:** Feature baseline for building an SPP-owned, multi-tenant e-commerce platform to replace/complement the WooCommerce + "Server Products" plugin delivery model.
**Scope:** What makes WooCommerce and Shopify good; what neither does well; what *we* additionally need given the scraper catalogue, the Indian market, and the superadmin model.

Every feature has a stable ID (`WC-###`, `SH-###`, `GAP-###`). Use these IDs in the build backlog so we can track coverage instead of re-arguing scope.

**Counts:** WooCommerce 124 · Shopify 131 · Gap/new 78 · **333 total**

---

## 0. Read this first — the strategic framing

Three roles must be designed *simultaneously*, not bolted on:

| Role | Who | Surface |
|---|---|---|
| **Shopper** | end customer of a client store | Public storefront (fast, SEO'd, mobile-first) |
| **Store owner** | our client (reseller/dropshipper) | Tenant admin — catalogue picking, pricing rules, orders, payouts |
| **Superadmin** | us (SPP) | Control plane — every tenant, billing, catalogue health, scraper ops |

WooCommerce and Shopify each solve **one** of these. WooCommerce gives the owner total control and zero platform oversight. Shopify gives a polished owner+shopper experience and *no* multi-tenant layer at all (Plus "organizations" is the closest, and it's one company's stores, not independent tenants). **The superadmin layer is our differentiator and it has no good reference implementation** — that's where most of the novel design work sits.

Second framing point: **we already own the hardest part.** ~96 scraped supplier sites, per-category SQLite catalogues, a keyed sync API, a Supabase control plane, billing, and enrollment. Shopify merchants pay for products; ours arrive free. The platform is the missing shell around an engine that already runs.

---

# PART A — WooCommerce / WordPress
### What makes it a good choice (124 features)

Woo's real advantage is not any single feature — it's **ownership + an escape hatch at every layer**. Anything the platform doesn't do, a hook lets you do yourself.

## A1. Product & catalogue model (WC-001 → WC-022)

| ID | Feature | Why it matters |
|---|---|---|
| WC-001 | Simple / variable / grouped / external product types | One model covers most retail |
| WC-002 | Unlimited variations via attribute combinations | No hard variant ceiling like Shopify's 100 |
| WC-003 | Global vs per-product attributes | Reusable size/colour taxonomies |
| WC-004 | Attribute terms with custom order + archives | Browsable "all red shoes" pages |
| WC-005 | Hierarchical product categories (unlimited depth) | Shopify has no true nested collections |
| WC-006 | Product tags (flat taxonomy) | Cross-cutting merchandising |
| WC-007 | Custom taxonomies via `register_taxonomy` | Brand, supplier, material — free-form |
| WC-008 | Unlimited custom fields / post meta per product | Where our `imageUrl`, `_spp_ts` live today |
| WC-009 | Product gallery + featured image | Multi-image PDP |
| WC-010 | Downloadable products (files, limits, expiry) | Digital goods |
| WC-011 | Virtual products (no shipping) | Services |
| WC-012 | Stock management at product *and* variation level | |
| WC-013 | Backorder policy (allow / notify / deny) | |
| WC-014 | Low-stock + no-stock threshold notifications | |
| WC-015 | Stock status independent of quantity | Critical for our dropship model — we know availability, not counts |
| WC-016 | Sale price with scheduled start/end dates | Automated promos |
| WC-017 | Tax class + tax status per product | |
| WC-018 | Shipping class per product | Rate differentiation |
| WC-019 | Up-sells and cross-sells per product | |
| WC-020 | Related products (auto by category/tag) | |
| WC-021 | Product CSV importer/exporter with column mapping | The de-facto bulk tool |
| WC-022 | Product reviews with verified-owner badge + rating | Built in, not an app |

## A2. Storefront, theming & content (WC-023 → WC-040)

| ID | Feature | Why it matters |
|---|---|---|
| WC-023 | Full theme system — any layout is achievable | No platform-imposed design ceiling |
| WC-024 | Child themes | Safe customization that survives updates |
| WC-025 | Template hierarchy override (`woocommerce/` folder in theme) | Any template replaceable file-by-file |
| WC-026 | Full Site Editing (block themes) | Non-dev layout control |
| WC-027 | Gutenberg block editor for all content | |
| WC-028 | Reusable blocks / patterns / synced patterns | Consistent merchandising modules |
| WC-029 | 30+ WooCommerce product blocks (Products by Category, On Sale, Best Sellers…) | Merchandising without code |
| WC-030 | Interactivity API mini-cart (default since 10.4) | Client-side cart without a JS framework |
| WC-031 | Cart & Checkout Blocks (block-based, Store API-driven) | Modern checkout with plugin slots |
| WC-032 | Widget areas / block sidebars | |
| WC-033 | Full CMS underneath — pages, posts, menus, media library | Content marketing is native, not an add-on |
| WC-034 | Native blog with categories, tags, archives, feeds | Shopify's blog is famously weak |
| WC-035 | Menu builder with nested mega-menu support (theme-dependent) | |
| WC-036 | Product search with catalogue-wide indexing | |
| WC-037 | Layered nav / filter widgets by attribute, price, rating, stock | |
| WC-038 | Breadcrumbs with schema | |
| WC-039 | Responsive image sizes / `srcset` auto-generation | |
| WC-040 | Multilingual via WPML/Polylang (unlimited languages) | Shopify caps at 20 and it's shakier |

## A3. Cart, checkout & payments (WC-041 → WC-060)

| ID | Feature | Why it matters |
|---|---|---|
| WC-041 | **Fully editable checkout** — fields, order, validation, steps | The single biggest Woo advantage over Shopify |
| WC-042 | Guest checkout toggle | |
| WC-043 | Account creation at checkout (optional/forced) | |
| WC-044 | One-page or multi-step checkout (developer's choice) | |
| WC-045 | Coupon system: fixed cart, percent cart, fixed product | |
| WC-046 | Coupon restrictions: min/max spend, products, categories, email, usage limits, individual-use | Deep enough to skip an app |
| WC-047 | Free shipping coupon type | |
| WC-048 | Cart-level fees via `add_fee()` — arbitrary charges | This is how our COD extra charge works |
| WC-049 | Negative fees = surcharge-free discounts | Prepaid discount implementation |
| WC-050 | 100+ payment gateways, incl. every Indian one (Razorpay, PayU, Cashfree, CCAvenue, Instamojo, Pay0) | |
| WC-051 | Payment gateway API — write your own in ~200 lines | We use this for Pay0 |
| WC-052 | Multiple gateways enabled simultaneously with per-gateway rules | COD + prepaid side by side |
| WC-053 | Gateway availability filters (hide COD over ₹X, hide for pincode) | |
| WC-054 | Cash on Delivery gateway built into core | Non-negotiable in India; Shopify needs an app |
| WC-055 | Direct bank transfer / cheque / manual gateways | |
| WC-056 | Partial payment / deposits (via extension — we now build it in) | |
| WC-057 | Tax calculation by country/state/city/postcode | |
| WC-058 | Tax display: inclusive vs exclusive, per-role | India needs inclusive |
| WC-059 | Store API (`/wc/store/v1/*`) — headless cart & checkout | Public, no auth needed for cart ops |
| WC-060 | Checkout block slot-fill extensibility (`registerCheckoutBlock`) | Modern extension model |

## A4. Orders, shipping & fulfilment (WC-061 → WC-076)

| ID | Feature | Why it matters |
|---|---|---|
| WC-061 | **HPOS** — dedicated order tables, ~5x faster order ops | 2026 default |
| WC-062 | HPOS datastore caching (standard since Dec 2025) | |
| WC-063 | Custom order statuses via `register_post_status` | Model any workflow — "Awaiting COD confirmation" |
| WC-064 | Order notes: private + customer-facing | Audit trail |
| WC-065 | Manual order creation from admin | Phone/WhatsApp orders |
| WC-066 | Order editing after placement (line items, fees, shipping) | Shopify only recently allowed partial edits |
| WC-067 | Refunds — full, partial, line-item level, gateway-API-backed | |
| WC-068 | Order search across meta, items, customer | |
| WC-069 | Bulk order actions + CSV export | |
| WC-070 | Shipping zones (by country/state/postcode) | |
| WC-071 | Shipping methods per zone: flat rate, free, local pickup, table rate | |
| WC-072 | Flat-rate cost formulas (`10 + (2 * [qty])`) | Surprisingly powerful without an app |
| WC-073 | Free shipping thresholds with coupon interplay | |
| WC-074 | Shipping calculator on cart | |
| WC-075 | Order webhooks (created/updated/deleted) | Aggregator integration |
| WC-076 | Full REST API v3 for orders, products, customers, coupons, reports | Everything scriptable |

## A5. Customers & accounts (WC-077 → WC-087)

| ID | Feature |
|---|---|
| WC-077 | My Account dashboard with pluggable endpoints (add your own tabs) |
| WC-078 | Order history + re-order |
| WC-079 | Downloads area |
| WC-080 | Saved billing & shipping addresses |
| WC-081 | Payment-method vault (gateway-dependent tokenization) |
| WC-082 | Customer roles + capabilities (`customer`, `shop_manager`, custom) |
| WC-083 | Role-based pricing / visibility (extension territory, but the hook layer is core) |
| WC-084 | Customer CSV import/export |
| WC-085 | Guest-to-registered order linking by email |
| WC-086 | GDPR tooling: export personal data, erase personal data, retention rules |
| WC-087 | Customer lifetime value in Analytics |

## A6. Marketing, SEO & analytics (WC-088 → WC-104)

| ID | Feature | Why it matters |
|---|---|---|
| WC-088 | **Full URL control** — no forced `/products/` or `/collections/` prefixes | Shopify's hard-coded URL structure is a real SEO limitation |
| WC-089 | Per-product/category slug editing | |
| WC-090 | Yoast/RankMath-grade SEO control: meta, canonical, robots, schema, redirects | |
| WC-091 | XML sitemaps with product-specific entries | |
| WC-092 | Product schema.org markup (Product, Offer, AggregateRating) | |
| WC-093 | Unlimited 301 redirect management | |
| WC-094 | Google Merchant Center / Shopping feed generation | |
| WC-095 | Meta / TikTok / Pinterest catalogue sync | |
| WC-096 | Abandoned-cart recovery (extension) | |
| WC-097 | Email templates customizable in admin + template override | |
| WC-098 | 11 transactional email types with per-email enable/disable | |
| WC-099 | WooCommerce Analytics: revenue, orders, products, categories, coupons, taxes, downloads, stock | |
| WC-100 | Batched order processing for analytics on high-volume stores (10.5+) | |
| WC-101 | Custom report ranges + CSV export + scheduled email reports | |
| WC-102 | Any analytics script injectable (GA4, GTM, Clarity, server-side) | No sandbox restrictions |
| WC-103 | Product feed / affiliate tracking freedom | |
| WC-104 | Marketing hub with channel recommendations | |

## A7. Extensibility & developer platform (WC-105 → WC-118)

| ID | Feature | Why it matters |
|---|---|---|
| WC-105 | **~1,000+ action & filter hooks** | The reason Woo won. Nearly nothing is closed |
| WC-106 | Plugin architecture with no approval gate | Ship private code instantly (how our plugin works) |
| WC-107 | 60,000+ WP plugins + 1,000+ Woo extensions | |
| WC-108 | Custom post types & taxonomies | Model anything |
| WC-109 | WP-CLI — scriptable everything | |
| WC-110 | Direct database access | Migration/repair without vendor tickets |
| WC-111 | WP-Cron scheduled tasks (+ real cron override) | |
| WC-112 | Action Scheduler — durable background job queue at scale | Genuinely good infrastructure |
| WC-113 | Transients / object cache API | |
| WC-114 | Template override without touching core | |
| WC-115 | Self-hostable anywhere; no vendor lock-in | |
| WC-116 | Full data export/portability | |
| WC-117 | Staging + local dev with real parity | |
| WC-118 | Open source — read and patch the actual code | |

## A8. Admin, roles & security (WC-119 → WC-124)

| ID | Feature |
|---|---|
| WC-119 | Granular capability system (`manage_woocommerce`, `edit_shop_orders`, …) |
| WC-120 | Unlimited staff accounts at zero cost (Shopify charges/limits by plan) |
| WC-121 | Multisite — many stores from one WP install |
| WC-122 | System status report + built-in logger |
| WC-123 | Database tools (clear sessions, recount terms, regenerate lookup tables) |
| WC-124 | Setup wizard + onboarding checklist |

---

# PART B — Shopify
### What makes it a good choice (131 features)

Shopify's advantage is the inverse of Woo's: **nothing is your problem.** Hosting, PCI, CDN, uptime, checkout conversion, fraud, tax tables, and the update treadmill are all absorbed by the vendor. Everything below is *managed*.

## B1. Setup, hosting & operations (SH-001 → SH-012)

| ID | Feature | Why it matters |
|---|---|---|
| SH-001 | Zero-install onboarding — selling in under an hour | |
| SH-002 | Fully managed hosting, auto-scaling through flash sales | No 502s on Black Friday |
| SH-003 | Global CDN for assets and images | |
| SH-004 | Free SSL, auto-renewed | |
| SH-005 | 99.99% SLA, published status page | |
| SH-006 | PCI DSS Level 1 compliance handled by platform | Removes the scariest compliance burden |
| SH-007 | Automatic platform updates — no maintenance window ever | The #1 pain Woo store owners have |
| SH-008 | Automatic daily backups | |
| SH-009 | Domain purchase + DNS management in-admin | |
| SH-010 | Free `.myshopify.com` subdomain from day one | |
| SH-011 | Image CDN with automatic format/size negotiation (WebP/AVIF) | |
| SH-012 | Built-in bot/DDoS protection | |

## B2. Product & inventory (SH-013 → SH-030)

| ID | Feature | Why it matters |
|---|---|---|
| SH-013 | Unlimited products on all plans | |
| SH-014 | Up to 100 variants (2,000 with a variants API workaround), 3 option types | Real ceiling — see GAP notes |
| SH-015 | **Combined Listings** — merge separate products into one PDP switcher | Colour-as-separate-product done right |
| SH-016 | **Metafields** — typed custom fields with admin UI + storefront access | Far more structured than WP meta |
| SH-017 | **Metaobjects** — custom content models (size guides, brands, lookbooks) | Effectively a headless CMS |
| SH-018 | Manual + **automated collections** (rule-based, self-maintaining) | Rules on tag/price/stock/vendor/type |
| SH-019 | Multi-location inventory with per-location stock | |
| SH-020 | Inventory transfers between locations |
| SH-021 | Inventory adjustment history / audit log | |
| SH-022 | Bulk editor — spreadsheet-style in-admin editing | Excellent UX, Woo has no equal |
| SH-023 | CSV import/export with error reporting | |
| SH-024 | Barcode / SKU / harmonized-code fields native | |
| SH-025 | Cost-per-item field → automatic margin & profit reporting | Woo has no native COGS |
| SH-026 | Product media: images, video, 3D models, external video | |
| SH-027 | Native product **bundles** (Shopify Bundles) | |
| SH-028 | Product **taxonomy** (standardized categories → auto tax + channel mapping) | |
| SH-029 | AI image editing incl. mobile editor (Winter '26) | Background removal, cleanup on demand |
| SH-030 | Shopify Magic product description/SEO generation | |

## B3. Themes, design & storefront (SH-031 → SH-046)

| ID | Feature | Why it matters |
|---|---|---|
| SH-031 | **Horizon theme system** (Summer '26) + 10 new free themes | Nested theme blocks — near-Webflow flexibility |
| SH-032 | Online Store 2.0 — sections on every page, not just home | |
| SH-033 | **Theme blocks / nested blocks** — merchant-composable layouts | |
| SH-034 | Drag-and-drop theme editor with live preview | |
| SH-035 | App blocks — apps drop into sections without editing Liquid | Solves Woo's "app broke my theme" problem |
| SH-036 | Theme library with unpublished drafts + one-click publish/rollback | |
| SH-037 | Theme versioning + GitHub integration | |
| SH-038 | Liquid templating language | Safe, sandboxed, fast |
| SH-039 | Section groups (header/footer as editable groups) | |
| SH-040 | Dynamic sources — bind any metafield to any theme setting | No code merchandising |
| SH-041 | Theme Store: 200+ vetted, performance-audited themes | |
| SH-042 | Automatic Core Web Vitals optimization + Web Performance dashboard | |
| SH-043 | Predictive search API (typeahead with products/collections/pages) | |
| SH-044 | Search & Discovery app: filters, boosting, synonyms, no-result rules | |
| SH-045 | Native A/B testing for themes/content (Summer '26) | Was app-only |
| SH-046 | AI merchandising — automatic product ordering by conversion signals (Summer '26) | |

## B4. Checkout & payments (SH-047 → SH-068)

| ID | Feature | Why it matters |
|---|---|---|
| SH-047 | **Shop Pay** — one-tap checkout across the whole Shopify network | Documented highest-converting checkout on the internet |
| SH-048 | Shop Pay Installments (BNPL) | |
| SH-049 | Shopify Payments — no third-party transaction fee, in-admin payouts | |
| SH-050 | 100+ alternative gateways, incl. Razorpay/PayU/Cashfree for India | |
| SH-051 | Accelerated wallets: Apple Pay, Google Pay, PayPal, Meta Pay | |
| SH-052 | **Checkout Extensibility / Checkout Components GA** (Summer '26) | App-safe checkout customization, upgrade-proof |
| SH-053 | Shopify Functions — server-side custom discount/shipping/payment logic in Wasm | Runs in the checkout hot path; genuinely novel |
| SH-054 | Discount Functions — arbitrary discount rules without an app slowing checkout | |
| SH-055 | Delivery Customization Functions — reorder/rename/hide shipping options | |
| SH-056 | Payment Customization Functions — hide COD over threshold, etc. | |
| SH-057 | Cart Transform Functions — bundles, gifts-with-purchase at the cart level | |
| SH-058 | **Post-purchase upsells** (now on Advanced, not just Plus) | One-click, pre-confirmation |
| SH-059 | Abandoned checkout recovery emails built in | |
| SH-060 | Automatic discounts (no code required) | |
| SH-061 | Discount codes with combinability rules (order + product + shipping stacking) | Better modelled than Woo coupons |
| SH-062 | Native gift cards (issue, balance, expiry) | |
| SH-063 | Shopify Tax — automatic rooftop-accurate rates, nexus tracking | |
| SH-064 | Duties & import tax at checkout (DDP) | |
| SH-065 | Fraud analysis on every order with risk scoring | Free; Woo needs an extension |
| SH-066 | Shopify Protect — chargeback liability shift | |
| SH-067 | One-page checkout as default | |
| SH-068 | Checkout branding API (colours, fonts, layout, corner radii) | |

## B5. Orders, shipping & fulfilment (SH-069 → SH-084)

| ID | Feature |
|---|---|
| SH-069 | Unified order timeline (every event, comment, and app action in one thread) |
| SH-070 | Draft orders + invoice links (send a payment link over WhatsApp) |
| SH-071 | Order editing post-purchase with automatic balance capture/refund |
| SH-072 | Partial fulfilment + multiple tracking numbers per order |
| SH-073 | Shopify Shipping — discounted labels, in-admin purchase |
| SH-074 | Carrier-calculated real-time rates at checkout |
| SH-075 | Shipping profiles (per-product rate rules by location) |
| SH-076 | Local delivery with radius/postcode rules |
| SH-077 | Local pickup with per-location pickup instructions |
| SH-078 | Shopify Fulfillment Network / 3PL app integrations |
| SH-079 | Multi-location intelligent order routing |
| SH-080 | Returns & exchanges self-service portal with return labels |
| SH-081 | Native subscriptions API (Selling Plans) |
| SH-082 | Pre-orders / try-before-you-buy selling plans |
| SH-083 | Shopify Flow — visual automation builder (triggers → conditions → actions) |
| SH-084 | Shopify Collective — source products from other Shopify stores (dropshipping, native) |

## B6. Customers, B2B & international (SH-085 → SH-100)

| ID | Feature | Why it matters |
|---|---|---|
| SH-085 | Customer profiles with full order/session/marketing history | |
| SH-086 | **Customer Segmentation** with a query language (`orders_count > 3 AND city = 'Mumbai'`) | Real segmentation, not tags |
| SH-087 | Customer accounts (new) — passwordless email-code login | Removes password friction |
| SH-088 | Customer account extensions (apps inside the account area) | |
| SH-089 | **Shopify Markets** — one store, many countries | |
| SH-090 | Per-market pricing, currency, domain, and language | |
| SH-091 | Automatic currency conversion + rounding rules | |
| SH-092 | Geolocation with market recommendation banner | |
| SH-093 | Up to 20 translated languages with translation API | |
| SH-094 | **B2B native**: company accounts, multiple locations per company | |
| SH-095 | B2B price lists / catalogues per company | |
| SH-096 | B2B payment terms (Net 15/30/60) with automated invoicing (Summer '26) | |
| SH-097 | B2B quantity rules & increments | |
| SH-098 | B2B tax exemption handling | |
| SH-099 | Customer-specific catalogue visibility | |
| SH-100 | Wholesale + DTC from the same admin and inventory pool | |

## B7. Marketing, analytics & AI (SH-101 → SH-118)

| ID | Feature | Why it matters |
|---|---|---|
| SH-101 | **Sidekick** — AI admin agent: create discounts, edit products, build workflows by prompt | |
| SH-102 | Sidekick prompt shortcuts, multi-step task lists, voice control (Winter '26) | |
| SH-103 | Shopify Magic across email, product copy, FAQ, blog | |
| SH-104 | **Agentic Storefronts** — products surfaced inside AI chats with brand-controlled presentation | |
| SH-105 | **Universal Commerce Protocol / Storefront MCP** — standard endpoints for AI agents to browse, cart, and buy | The rails for ChatGPT/Copilot/Perplexity buying |
| SH-106 | Shopify Email — built-in campaign builder, free tier | |
| SH-107 | Marketing automations (welcome, win-back, abandoned browse) | |
| SH-108 | Shopify Audiences (Plus) — cross-merchant ad targeting lists | Uses network data no single merchant has |
| SH-109 | Shopify Inbox — live chat that converts to orders |
| SH-110 | Shopify Forms — lead capture with popups |
| SH-111 | Analytics dashboard: sessions, conversion funnel, AOV, returning rate |
| SH-112 | ShopifyQL + custom report builder |
| SH-113 | Live View — real-time global order map |
| SH-114 | Marketing attribution by channel/campaign with first/last-click models |
| SH-115 | Profit reporting from cost-per-item |
| SH-116 | Sales channels: Shop app, Google, Meta, TikTok, Amazon, eBay, Walmart |
| SH-117 | Shop app — 100M+ user marketplace surface, free distribution |
| SH-118 | Native review + UGC surfaces via Shop |

## B8. Developer platform & extensibility (SH-119 → SH-131)

| ID | Feature | Why it matters |
|---|---|---|
| SH-119 | GraphQL Admin API (primary; REST deprecated) | Precise, versioned, rate-fair |
| SH-120 | Storefront API — headless product/cart/checkout | |
| SH-121 | Versioned APIs with a published deprecation calendar | Woo has no equivalent guarantee |
| SH-122 | Hydrogen (React storefront framework) + Oxygen (free global hosting) | |
| SH-123 | Up to 25 headless storefronts on Plus |
| SH-124 | Shopify CLI + local theme/app dev with hot reload |
| SH-125 | App Store: 13,000+ vetted apps with review + install flow |
| SH-126 | App embed blocks + theme app extensions (no Liquid edits) |
| SH-127 | Webhooks with guaranteed delivery + priority webhooks on Plus |
| SH-128 | Bulk Operations API for large exports/imports |
| SH-129 | Flow connectors — apps expose triggers/actions to merchant automations |
| SH-130 | Shopify POS + POS Go hardware, unified online/offline inventory & customers |
| SH-131 | Organization admin (Plus): multi-store switching, cross-store analytics, shared permission templates |

---

# PART C — The Gaps
### What neither platform gives us, and what our platform must add (78 features)

This is the part that matters most. Sections C1 and C2 are where we can beat both incumbents; C3 is where we beat Shopify specifically; C4 is where we must not lose to WooCommerce.

## C1. Multi-tenant control plane — the superadmin panel (GAP-001 → GAP-020)

*Neither platform has this. Shopify Plus organizations = one company's stores. WP Multisite = shared plugins, no billing/isolation model. This is a from-scratch build and it's our moat.*

| ID | Feature | Notes |
|---|---|---|
| GAP-001 | **Global tenant grid** — every store, status, plan, MRR, last sync, error count, one row each | The screen we'll live in |
| GAP-002 | Per-tenant health score (sync freshness, order volume, error rate, catalogue coverage) | Proactive churn detection |
| GAP-003 | **Impersonation / "view as tenant"** with a mandatory audit trail | Support without asking for passwords |
| GAP-004 | Tenant provisioning in one click — domain, DB namespace, seed catalogue, key issue | Replaces today's manual enrollment |
| GAP-005 | Tenant suspend / resume / archive with data retention policy | |
| GAP-006 | Superadmin-side plan & feature flags per tenant | Gate features by plan without deploys |
| GAP-007 | Cross-tenant search (find an order/product/customer across all stores) | Support velocity |
| GAP-008 | Global announcement / maintenance banner pushed to all tenant admins | |
| GAP-009 | Aggregate GMV, orders, and take-rate dashboard | Our own business metrics |
| GAP-010 | Per-tenant resource metering (API calls, storage, bandwidth, scrape jobs) | Basis for fair pricing |
| GAP-011 | Usage-based overage billing + automated invoicing (extend existing `invoices`) | |
| GAP-012 | Dunning: retry, reminder, grace period, soft-lock, hard-lock ladder | Today's `scheduler.js` grown up |
| GAP-013 | Tenant-level audit log — who changed what, when, from where | Extend existing `audit_log` |
| GAP-014 | Staged rollout / canary releases per tenant cohort | Never ship a v4.0.0 fatal to everyone again |
| GAP-015 | Per-tenant error inbox surfaced to *us* before the client notices | |
| GAP-016 | White-label controls: custom domain, logo, colours, sender email, remove-our-branding flag | |
| GAP-017 | Reseller/agency sub-accounts (a partner managing N stores) | Second revenue channel |
| GAP-018 | Data-isolation guarantee with automated cross-tenant leak tests in CI | The one bug that kills the company |
| GAP-019 | Tenant self-serve signup → trial → paid, with no human in the loop | |
| GAP-020 | Full tenant data export on churn (legal + goodwill) | |

## C2. Catalogue-sourcing native — our unfair advantage (GAP-021 → GAP-036)

*Shopify Collective is the closest thing and it's Shopify-store-to-Shopify-store only. We have 96 scraped suppliers. Make the catalogue a first-class platform primitive rather than a plugin that pulls a feed.*

| ID | Feature | Notes |
|---|---|---|
| GAP-021 | **Catalogue browser in tenant admin** — search/filter all sourced products before importing | Today the client gets whatever the feed sends |
| GAP-022 | Selective import: pick products/categories/brands rather than all-or-nothing | |
| GAP-023 | **Pricing rule engine**: margin bands by category/brand/price/supplier, round-to-99, floor/ceiling | Generalize today's re-price logic |
| GAP-024 | Rule preview/simulation before apply ("this changes 3,412 products") | We already learned this lesson |
| GAP-025 | Price-change alerting when supplier cost moves beyond a threshold | |
| GAP-026 | Automatic out-of-stock propagation with configurable action (hide / mark OOS / show backorder) | The soft-404 work, productized |
| GAP-027 | Stale-product policy per tenant with dry-run and blast-radius guard | Directly from the stale-sweep design |
| GAP-028 | Supplier-level SLA view: freshness, error rate, catalogue size, last successful scrape | |
| GAP-029 | **Category mapping UI** — supplier taxonomy → store taxonomy, drag-and-drop | Today's `category_map` has no UI |
| GAP-030 | Rename/deprecation reconciliation surfaced to the owner for approval | The `handle`-based reconcile work |
| GAP-031 | Duplicate/near-duplicate detection across suppliers | Same watch from 3 sites |
| GAP-032 | Image proxy + optimization + hotlink protection for supplier images | We currently hotlink; that's fragile |
| GAP-033 | AI product-copy rewriting (title/description) to escape duplicate-content SEO penalties | The single biggest SEO problem in dropshipping |
| GAP-034 | Per-tenant catalogue exclusivity / territory locks | Sellable feature: "only you get this brand" |
| GAP-035 | Request-a-source workflow (tenant asks, we scrape, they're notified) | `scrape_requests` with a real UI |
| GAP-036 | Supplier order routing — forward the order to the supplier, track it back | The missing half of dropship; today it's manual |

## C3. India-first commerce (GAP-037 → GAP-054)

*Shopify handles none of this natively; Woo handles it only via a stack of plugins. Building it in is a positioning statement.*

| ID | Feature | Notes |
|---|---|---|
| GAP-037 | **GST-compliant invoicing**: GSTIN, HSN codes, CGST/SGST/IGST split by place of supply | Legally required |
| GAP-038 | Automatic intra-state vs inter-state tax determination | |
| GAP-039 | HSN code field on products with rate lookup | |
| GAP-040 | GSTR-1 / GSTR-3B export | Saves the client's CA a day a month |
| GAP-041 | E-way bill generation over the threshold | |
| GAP-042 | B2B invoice with buyer GSTIN capture at checkout | |
| GAP-043 | **UPI as a first-class method** (intent + QR + collect), not "another gateway" | 27B+ txns/month |
| GAP-044 | Native COD with per-pincode availability | |
| GAP-045 | **Partial COD / advance payment** built in | We've already built the WooBooster replacement — make it core |
| GAP-046 | Prepaid discount / COD surcharge engine with per-category rules | |
| GAP-047 | **RTO risk scoring** at checkout (address quality, pincode history, order value, customer history) | 15–30% RTO is the industry's biggest cost |
| GAP-048 | COD order confirmation flow — OTP or WhatsApp confirm before fulfilment | Proven RTO reduction |
| GAP-049 | Pincode serviceability checker on the PDP | Expected by Indian shoppers |
| GAP-050 | Shiprocket / Delhivery / Pickrr / Shipway aggregator integrations | |
| GAP-051 | **WhatsApp commerce**: order updates, abandoned cart, catalogue sharing, order-via-chat | Formalizes today's Request-a-Quote button |
| GAP-052 | WhatsApp Business API templates managed per tenant | |
| GAP-053 | INR-native pricing, ₹ formatting, lakh/crore number display | |
| GAP-054 | Indian address model (pincode-first, state dropdown, landmark field) | |

## C4. Platform quality bar — where Woo owners get burned (GAP-055 → GAP-066)

| ID | Feature | Notes |
|---|---|---|
| GAP-055 | Managed hosting, updates, and backups — the owner never touches a server | Woo's #1 complaint |
| GAP-056 | Zero-downtime deploys with automated rollback | |
| GAP-057 | Performance budget enforced in CI (LCP/INP/CLS per template) | |
| GAP-058 | Built-in staging environment per tenant with one-click promote | |
| GAP-059 | Versioned public API with a published deprecation policy | Shopify does this; Woo doesn't |
| GAP-060 | Webhook delivery with retries, DLQ, and a replay UI | |
| GAP-061 | Idempotency keys on every write endpoint | |
| GAP-062 | Rate limiting per tenant with clear headers and a usage dashboard | |
| GAP-063 | Sandbox/test mode with fake payments and test orders | |
| GAP-064 | Fraud scoring on orders (free, not an add-on) | |
| GAP-065 | SOC2-shaped controls: encryption at rest, key rotation, least-privilege, access review | Needed before any serious client |
| GAP-066 | Transparent pricing with no per-transaction GMV cut | Direct attack on Shopify's most-hated trait |

## C5. AI & agentic commerce — the 2026 table stakes (GAP-067 → GAP-078)

*Shopify shipped UCP/MCP and Agentic Storefronts this year. If we build a 2020-era platform in 2026 we're born obsolete.*

| ID | Feature | Notes |
|---|---|---|
| GAP-067 | **MCP endpoints per storefront** — let AI agents browse, cart, and check out | Shopify's UCP is the pattern to match |
| GAP-068 | `llms.txt` + structured product feeds for AI crawler consumption | |
| GAP-069 | Agent-readable product schema with rich attributes | |
| GAP-070 | AI merchandising — auto-sort collections by conversion signal | |
| GAP-071 | Semantic/vector product search (natural language: "black leather watch under 3000") | |
| GAP-072 | AI-assisted admin ("mark all Jilani watches out of stock", "make a 20% Diwali sale") | Our Sidekick |
| GAP-073 | AI-generated storefront theme/section from a prompt | |
| GAP-074 | AI product-image cleanup: background removal, standardization across suppliers | Supplier images are wildly inconsistent |
| GAP-075 | AI category classification for unmapped scraped products | Directly attacks the `categoriesMap` maintenance burden |
| GAP-076 | AI-drafted customer support replies from order context | |
| GAP-077 | Automated size/attribute normalization across supplier spellings | Retires the hand-maintained `sizeMap` |
| GAP-078 | Chat-native shopping widget on the storefront | |

---

# PART D — How this maps onto what we already have

We are not starting from zero. Roughly **40% of the hard backend already exists** in `UltimateScrapperV2`.

| Layer | Today | Becomes |
|---|---|---|
| Catalogue store | per-category SQLite (`databases/*.db`) | Needs to become Postgres or per-tenant partitioned — SQLite-per-category can't serve N tenant storefronts with per-tenant pricing (see risk below) |
| Control plane | Supabase: `users`, `sources`, `enrollments`, `invoices`, `audit_log` | Direct foundation for GAP-001…GAP-020 |
| Auth | `x-enrollment-key` + domain lock (`portal/enrollmentKey.js`) | Becomes the tenant API credential model |
| Catalogue delivery | `GET /product/sync-feed` | Becomes internal catalogue read, not an HTTP pull the client polls |
| Scrape pipeline | `scrapeQueue.js` (concurrency 1) + `scraperManager.js` | Unchanged — but concurrency 1 is a scaling wall at N tenants |
| Billing | `portal/scheduler.js`, Pay0, `invoices` | Extends to GAP-011/012 |
| Pricing rules | plugin-side re-price + category margin bands | Moves server-side → GAP-023 |
| Storefront | client's WooCommerce + our plugin | **This is the whole new build** |
| Tenant admin | `portal-app-clean` React SPA | Grows from portal into full store admin |
| Superadmin | partial admin routes in the portal | Becomes GAP-001 |

### The four things that will bite us

1. **SQLite-per-category does not survive multi-tenancy.** `productId` is only unique within a category file, every caller must carry `dbName`, and there is no place to put per-tenant price/visibility. The moment two tenants want different prices for the same product, the current schema has nowhere to write it. Plan the migration to Postgres (`products` + `tenant_products` overlay) before building the storefront, not after.
2. **Scrape queue concurrency 1** is correct today (SQLite WAL contention) and will be the bottleneck at 50 tenants. Postgres removes the reason for it.
3. **Duplicate content SEO.** Every tenant selling the same scraped catalogue with the same descriptions means Google ranks none of them. GAP-033 is not a nice-to-have; it's the difference between stores that get traffic and stores that don't. This is arguably the highest-ROI item on the entire list.
4. **Supplier order routing (GAP-036) is the actual missing half of the product.** We deliver the catalogue but the client still places supplier orders by hand. Closing that loop is what turns this from "a product feed" into "a business in a box" — and it's what justifies a much higher price point.

### Suggested build order

| Phase | Scope | Why first |
|---|---|---|
| **0** | Postgres catalogue migration + `tenant_products` overlay | Everything else depends on it |
| **1** | Tenant admin: catalogue browser, pricing rules, category mapping (GAP-021→030) | Highest client-visible value, reuses existing portal |
| **2** | Storefront MVP: PDP, collection, search, cart, checkout, COD/UPI (WC-041…, GAP-043→049) | The actual e-commerce site |
| **3** | Superadmin control plane (GAP-001→020) | Needed before tenant count grows |
| **4** | India compliance: GST, invoicing, RTO, WhatsApp (GAP-037→054) | Sellable differentiator |
| **5** | Supplier order routing (GAP-036) + AI layer (GAP-067→078) | The moat |

Keep the WooCommerce plugin alive through Phase 2 at minimum. Existing clients are revenue; do not force a migration until the new storefront is demonstrably better than their current Flatsome build.

---

## Sources

- [Shopify Features: The Complete List for 2026 — Charle](https://www.charleagency.com/articles/features-for-shopify/)
- [Shopify Editions](https://www.shopify.com/editions)
- [Shopify Editions 2026: 150+ New Features, Ranked — AdsX](https://www.adsx.com/blog/shopify-editions-2026-new-features)
- [Shopify Summer '26 Edition — IceCube Digital](https://www.icecubedigital.com/blog/shopify-summer-edition-2026/)
- [Shopify Winter Editions 2026 — Latori](https://www.latori.com/en/blogpost/shopify-editions)
- [Shopify Plus features 2026 — On Tap](https://www.ontapgroup.com/blog/shopify-plus-features)
- [Shopify Limitations: What Shopify Cannot Do in 2026 — Swell](https://www.swell.is/content/shopify-limitations)
- [Shopify Pros and Cons in 2026 — ecomm.design](https://ecomm.design/shopify-pros-and-cons/)
- [WooCommerce 10.7: Performance, Analytics, and a better Store API](https://developer.woocommerce.com/2026/04/15/woocommerce-10-7-performance-analytics-and-a-better-store-api)
- [WooCommerce 10.4: The Interactivity API Mini Cart Goes Live](https://developer.woocommerce.com/2025/12/10/woocommerce-10-4-the-interactivity-api-mini-cart-goes-live/)
- [WooCommerce News 2026: 10.8 Release, HPOS & AI Trends — Elsner](https://www.elsner.com/news/woocommerce-news-updates/)
- [WooCommerce Store API Guide — Cart & Checkout (2026)](https://codeatoz.com/blog/woocommerce-store-api/)
- [WooCommerce Subscriptions / Memberships / Product Add-Ons docs](https://woocommerce.com/documentation/products/extensions/woocommerce-subscriptions/)
- [How to Build a Multi-Tenant eCommerce Platform — Spree Commerce](https://spreecommerce.org/how-to-build-a-multi-tenant-ecommerce-platform-architecture-features-and-costs/)
- [The Ultimate Guide to Multi-tenant White-label eCommerce — Spree](https://spreecommerce.org/the-ultimate-guide-to-multi-tenant-white-label-ecommerce/)
- [Multi-Tenant Ecommerce — Bagisto docs](https://docs.bagisto.com/multi-tenant-ecommerce/introduction.html)
- [Best eCommerce Platform in India 2026 — Shipmozo](https://www.shipmozo.com/blog/best-ecommerce-platform-india)
- [WhatsApp Commerce in India — Complete 2026 Guide](https://watease.com/whatsapp-commerce-india)
- [How to Set Up GST, Shipping & COD on Shopify India (2026)](https://bybtraction.com/shopify-gst-shipping-cod-india/)
- [Shopify Storefront MCP Is Live — Weaverse](https://weaverse.io/blogs/shopify-storefront-mcp-hydrogen-2026)
- [What Is Composable Commerce? 2026 Guide — SaM Solutions](https://sam-solutions.com/blog/what-is-composable-commerce/)
- [Headless Commerce in 2026 — BigCommerce](https://www.bigcommerce.com/articles/headless-commerce/)
