-- =====================================================================
-- SPP Storefront Platform — initial schema
-- Migration 001. Runs in the SAME Supabase database as the portal schema.
--
-- RULES THIS FILE OBEYS:
--   * CREATE only. No ALTER/DROP on any pre-existing table.
--   * Pre-existing tables (users, enrollments, enrollment_sources, sources,
--     invoices, plans) are READ-ONLY to this platform. We reference them
--     with foreign keys but never modify them.
--   * Every new table is prefixed: store_ / catalog_ / shop_
--       store_*    = per-tenant configuration owned by the store owner
--       catalog_*  = mirrored product data pulled from UltimateScrapperV2
--       shop_*     = end-shopper data (their accounts, carts, orders)
--
-- STATUS: DRAFT — do not apply until reviewed (task 0.1.2).
-- =====================================================================

create extension if not exists pgcrypto;   -- gen_random_uuid()
create extension if not exists pg_trgm;    -- fuzzy product search

-- ---------------------------------------------------------------------
-- Shared updated_at trigger
-- ---------------------------------------------------------------------
create or replace function spp_touch_updated_at()
returns trigger language plpgsql as $$
begin
  new.updated_at = now();
  return new;
end $$;


-- =====================================================================
-- SECTION 1 — TENANT CONFIGURATION
-- =====================================================================

-- ---------------------------------------------------------------------
-- store_config — one row per enrollment. THE tenant record.
-- An enrollment already means "one site for one user", so it is the
-- natural tenant key. Everything the storefront renders comes from here.
-- ---------------------------------------------------------------------
create table if not exists store_config (
  enrollment_id   uuid primary key references enrollments(id) on delete cascade,

  -- identity ---------------------------------------------------------
  store_name      text not null default 'My Store',
  tagline         text,
  logo_url        text,
  favicon_url     text,
  slug            text unique,                 -- for <slug>.spp.store

  -- look & feel ------------------------------------------------------
  -- template_id picks the LAYOUT (structure/composition).
  -- theme holds the BRAND OVERRIDES (colors, fonts, radius, spacing).
  -- Together they make each store visually distinct without a rebuild.
  template_id     text not null default 'classic-grid',
  theme           jsonb not null default '{
    "colors": {
      "primary":    "#111827",
      "accent":     "#2563eb",
      "background": "#ffffff",
      "surface":    "#f9fafb",
      "text":       "#111827",
      "muted":      "#6b7280",
      "border":     "#e5e7eb",
      "success":    "#16a34a",
      "danger":     "#dc2626"
    },
    "fonts": { "heading": "inter", "body": "inter" },
    "radius": "md",
    "density": "comfortable",
    "button_style": "solid"
  }'::jsonb,

  -- homepage composition: ordered list of section blocks.
  -- e.g. [{"type":"hero","enabled":true,"props":{...}}, ...]
  homepage_sections jsonb not null default '[]'::jsonb,

  -- announcement / promo bar ------------------------------------------
  announcement_text    text,
  announcement_link    text,
  announcement_enabled boolean not null default false,

  -- contact ----------------------------------------------------------
  support_email   text,
  support_phone   text,
  whatsapp_number text,
  address_line    text,
  socials         jsonb not null default '{}'::jsonb,   -- {instagram, facebook, youtube, x, ...}

  -- commerce ---------------------------------------------------------
  currency          text not null default 'INR',
  currency_symbol   text not null default '₹',
  tax_enabled       boolean not null default false,
  tax_percent       numeric(5,2) not null default 0,
  tax_inclusive     boolean not null default true,
  price_rounding    text not null default 'none'
                      check (price_rounding in ('none','nearest_1','nearest_10','nearest_50','ending_99')),
  cod_enabled       boolean not null default false,
  cod_min_order     numeric(12,2) not null default 0,
  free_ship_above   numeric(12,2),
  shipping_methods  jsonb not null default '[]'::jsonb, -- [{id,label,price,eta_days}]

  -- payment gateway (the OWNER's own gateway — SPP never holds this money)
  gateway_provider          text,               -- 'razorpay' | 'stripe' | 'phonepe' | ...
  gateway_public_key        text,               -- publishable/key id — safe to expose
  gateway_secret_encrypted  text,               -- AES-256-GCM, NEVER leaves the server
  gateway_webhook_secret_encrypted text,
  gateway_enabled           boolean not null default false,

  -- outbound email (owner's SMTP; falls back to SPP's if null)
  smtp_config_encrypted text,

  -- seo --------------------------------------------------------------
  seo_title_template text default '{product} | {store}',
  seo_description    text,
  og_image_url       text,
  ga4_id             text,
  meta_pixel_id      text,

  -- lifecycle --------------------------------------------------------
  setup_completed    boolean not null default false,
  setup_step         text,                       -- resume point for the wizard
  is_live            boolean not null default false,

  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

create index if not exists idx_store_config_slug on store_config(slug);

drop trigger if exists trg_store_config_touch on store_config;
create trigger trg_store_config_touch before update on store_config
  for each row execute function spp_touch_updated_at();


-- ---------------------------------------------------------------------
-- store_domains — every hostname that maps to a tenant.
-- enrollments.domain already holds the primary one, but a tenant may want
-- <slug>.spp.store AND their own domain AND a www variant. This table is
-- the single lookup the domain-resolver middleware hits.
-- ---------------------------------------------------------------------
create table if not exists store_domains (
  id            uuid primary key default gen_random_uuid(),
  enrollment_id uuid not null references enrollments(id) on delete cascade,
  hostname      text unique not null,            -- lowercase, no scheme, no port
  is_primary    boolean not null default false,  -- canonical host for redirects/SEO
  kind          text not null default 'custom' check (kind in ('subdomain','custom')),
  verified      boolean not null default false,
  verified_at   timestamptz,
  ssl_status    text not null default 'pending' check (ssl_status in ('pending','active','failed')),
  created_at    timestamptz not null default now()
);

create index if not exists idx_store_domains_enr on store_domains(enrollment_id);


-- ---------------------------------------------------------------------
-- store_source_markup — per-tenant, per-source price markup.
-- This is the side table that replaces the ALTER we are NOT allowed to
-- do on enrollment_sources. Shopper price = base_price * (1 + markup/100).
-- ---------------------------------------------------------------------
create table if not exists store_source_markup (
  id             uuid primary key default gen_random_uuid(),
  enrollment_id  uuid not null references enrollments(id) on delete cascade,
  source_id      text not null references sources(id),
  markup_percent numeric(6,2) not null default 0 check (markup_percent >= -100),
  -- optional flat add-on applied AFTER the percentage
  markup_flat    numeric(12,2) not null default 0,
  -- optional per-category override: {"Analog Watches": 45, "Digital": 30}
  category_overrides jsonb not null default '{}'::jsonb,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),
  unique (enrollment_id, source_id)
);

drop trigger if exists trg_store_markup_touch on store_source_markup;
create trigger trg_store_markup_touch before update on store_source_markup
  for each row execute function spp_touch_updated_at();


-- ---------------------------------------------------------------------
-- store_pages — owner-authored CMS/legal pages.
-- Separate from store_config so page bodies (potentially long HTML) don't
-- bloat the config row that gets read on every storefront boot.
-- ---------------------------------------------------------------------
create table if not exists store_pages (
  id            uuid primary key default gen_random_uuid(),
  enrollment_id uuid not null references enrollments(id) on delete cascade,
  slug          text not null,                  -- 'privacy-policy', 'about', ...
  title         text not null,
  body_html     text,
  show_in_footer boolean not null default true,
  sort_order    int not null default 0,
  published     boolean not null default true,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now(),
  unique (enrollment_id, slug)
);

drop trigger if exists trg_store_pages_touch on store_pages;
create trigger trg_store_pages_touch before update on store_pages
  for each row execute function spp_touch_updated_at();


-- =====================================================================
-- SECTION 2 — MIRRORED CATALOG
-- Pulled from UltimateScrapperV2 /product/sync-feed over HTTP.
-- Stored ONCE per (source_id, external_id) and shared by every tenant
-- enrolled in that source. Tenant visibility and price are resolved at
-- query time, so 50 tenants selling the same watch = 1 row, not 50.
-- =====================================================================

create table if not exists catalog_products (
  id              uuid primary key default gen_random_uuid(),

  -- provenance -------------------------------------------------------
  source_id       text not null references sources(id),
  external_id     text not null,          -- productId from the source SQLite db
  db_name         text,                   -- which .db it came from ('watches')
  fetched_from    text,                   -- productFetchedFrom (the supplier URL)

  -- identity ---------------------------------------------------------
  title           text not null,
  slug            text not null,          -- url-safe, unique per source
  brand           text,
  category        text,                   -- raw category from the source
  category_norm   text,                   -- normalized/canonical

  -- money ------------------------------------------------------------
  -- base_price is the SUPPLIER price. Never shown to a shopper directly;
  -- always passed through the tenant's markup first.
  base_price      numeric(12,2),
  base_mrp        numeric(12,2),          -- supplier's compare-at, if any

  -- content ----------------------------------------------------------
  description     text,
  images          text[] not null default '{}',
  sizes           text[] not null default '{}',
  tags            text[] not null default '{}',
  attributes      jsonb not null default '{}'::jsonb,

  -- state ------------------------------------------------------------
  in_stock        boolean not null default true,
  out_of_stock_since timestamptz,

  -- sync bookkeeping -------------------------------------------------
  source_updated_at  timestamptz,         -- productLastUpdated from the feed
  first_seen_at      timestamptz not null default now(),
  last_seen_at       timestamptz not null default now(),

  -- search -----------------------------------------------------------
  search_tsv      tsvector generated always as (
                    setweight(to_tsvector('simple', coalesce(title,'')), 'A') ||
                    setweight(to_tsvector('simple', coalesce(brand,'')), 'B') ||
                    setweight(to_tsvector('simple', coalesce(category,'')), 'C')
                  ) stored,

  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),

  unique (source_id, external_id),
  unique (source_id, slug)
);

create index if not exists idx_catalog_source     on catalog_products(source_id);
create index if not exists idx_catalog_category   on catalog_products(source_id, category);
create index if not exists idx_catalog_brand      on catalog_products(source_id, brand);
create index if not exists idx_catalog_stock      on catalog_products(source_id, in_stock);
create index if not exists idx_catalog_price      on catalog_products(base_price);
create index if not exists idx_catalog_search     on catalog_products using gin(search_tsv);
create index if not exists idx_catalog_title_trgm on catalog_products using gin(title gin_trgm_ops);

drop trigger if exists trg_catalog_touch on catalog_products;
create trigger trg_catalog_touch before update on catalog_products
  for each row execute function spp_touch_updated_at();


-- ---------------------------------------------------------------------
-- catalog_sync_state — where the mirror got to, per source.
-- Lets a killed sync resume instead of restarting the whole pull.
-- ---------------------------------------------------------------------
create table if not exists catalog_sync_state (
  source_id       text primary key references sources(id),
  cursor_mode     text not null default 'id' check (cursor_mode in ('id','ts')),
  cursor_value    text not null default '0',
  last_run_at     timestamptz,
  last_success_at timestamptz,
  last_status     text not null default 'idle' check (last_status in ('idle','running','ok','error')),
  last_error      text,
  rows_seen       int not null default 0,
  rows_upserted   int not null default 0,
  full_pass_started_at timestamptz,   -- rows not touched since this => out of stock
  updated_at      timestamptz not null default now()
);

drop trigger if exists trg_sync_state_touch on catalog_sync_state;
create trigger trg_sync_state_touch before update on catalog_sync_state
  for each row execute function spp_touch_updated_at();


-- =====================================================================
-- SECTION 3 — END-SHOPPER DATA
-- Every table here is scoped to enrollment_id. A shopper on store A must
-- never be resolvable from store B, even with the same email address.
-- =====================================================================

create table if not exists shop_customers (
  id             uuid primary key default gen_random_uuid(),
  enrollment_id  uuid not null references enrollments(id) on delete cascade,
  email          text not null,
  password_hash  text,                    -- null for guest-created records
  name           text,
  phone          text,
  email_verified boolean not null default false,
  marketing_optin boolean not null default false,
  status         text not null default 'active' check (status in ('active','blocked')),
  last_login_at  timestamptz,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),
  -- THE tenant-isolation constraint: same email on two stores is fine,
  -- twice on one store is not.
  unique (enrollment_id, email)
);

create index if not exists idx_shop_cust_enr on shop_customers(enrollment_id);

drop trigger if exists trg_shop_cust_touch on shop_customers;
create trigger trg_shop_cust_touch before update on shop_customers
  for each row execute function spp_touch_updated_at();


create table if not exists shop_customer_tokens (
  id            uuid primary key default gen_random_uuid(),
  customer_id   uuid not null references shop_customers(id) on delete cascade,
  kind          text not null check (kind in ('password_reset','email_verify')),
  token_hash    text not null,
  expires_at    timestamptz not null,
  used_at       timestamptz,
  created_at    timestamptz not null default now()
);

create index if not exists idx_shop_tok_hash on shop_customer_tokens(token_hash);


create table if not exists shop_addresses (
  id            uuid primary key default gen_random_uuid(),
  enrollment_id uuid not null references enrollments(id) on delete cascade,
  customer_id   uuid not null references shop_customers(id) on delete cascade,
  label         text,                     -- 'Home', 'Office'
  full_name     text not null,
  phone         text not null,
  line1         text not null,
  line2         text,
  city          text not null,
  state         text,
  postal_code   text not null,
  country       text not null default 'IN',
  is_default    boolean not null default false,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create index if not exists idx_shop_addr_cust on shop_addresses(customer_id);

drop trigger if exists trg_shop_addr_touch on shop_addresses;
create trigger trg_shop_addr_touch before update on shop_addresses
  for each row execute function spp_touch_updated_at();


-- ---------------------------------------------------------------------
-- Carts. Anonymous carts are keyed by a client-generated token so a guest
-- can keep a cart across reloads; on login the guest cart is merged.
-- ---------------------------------------------------------------------
create table if not exists shop_carts (
  id            uuid primary key default gen_random_uuid(),
  enrollment_id uuid not null references enrollments(id) on delete cascade,
  customer_id   uuid references shop_customers(id) on delete cascade,
  anon_token    text,                     -- set when customer_id is null
  status        text not null default 'active' check (status in ('active','converted','abandoned')),
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now(),
  check (customer_id is not null or anon_token is not null)
);

create index if not exists idx_shop_cart_cust on shop_carts(customer_id);
create unique index if not exists idx_shop_cart_anon
  on shop_carts(enrollment_id, anon_token) where anon_token is not null;

drop trigger if exists trg_shop_cart_touch on shop_carts;
create trigger trg_shop_cart_touch before update on shop_carts
  for each row execute function spp_touch_updated_at();


create table if not exists shop_cart_items (
  id            uuid primary key default gen_random_uuid(),
  cart_id       uuid not null references shop_carts(id) on delete cascade,
  product_id    uuid not null references catalog_products(id) on delete cascade,
  variant       text,                     -- chosen size/colour, free text
  quantity      int not null default 1 check (quantity > 0),
  created_at    timestamptz not null default now(),
  unique (cart_id, product_id, variant)
);

create index if not exists idx_shop_cartitem_cart on shop_cart_items(cart_id);


-- ---------------------------------------------------------------------
-- Orders. Everything price- and product-related is SNAPSHOTTED at write
-- time: a catalog re-sync must never retroactively change what someone
-- was charged or what the invoice says they bought.
-- ---------------------------------------------------------------------
create table if not exists shop_orders (
  id              uuid primary key default gen_random_uuid(),
  enrollment_id   uuid not null references enrollments(id) on delete cascade,
  customer_id     uuid references shop_customers(id) on delete set null,

  order_number    text not null,            -- per-store human number, e.g. 'A-1042'
  guest_token     text,                     -- lets a guest re-open their order

  status          text not null default 'pending_payment'
                    check (status in ('pending_payment','paid','processing','shipped',
                                      'delivered','cancelled','refunded','failed')),

  -- money (all snapshotted) ------------------------------------------
  currency        text not null default 'INR',
  subtotal        numeric(12,2) not null default 0,
  discount_total  numeric(12,2) not null default 0,
  shipping_total  numeric(12,2) not null default 0,
  tax_total       numeric(12,2) not null default 0,
  grand_total     numeric(12,2) not null default 0,

  coupon_code     text,

  -- contact + address snapshot ---------------------------------------
  email           text not null,
  phone           text,
  shipping_address jsonb not null default '{}'::jsonb,
  billing_address  jsonb,

  shipping_method  text,
  shipping_eta_days int,

  -- payment ----------------------------------------------------------
  payment_method   text,                   -- 'razorpay' | 'cod' | ...
  gateway          text,
  gateway_order_id text,
  gateway_payment_id text,
  paid_at          timestamptz,

  -- fulfilment -------------------------------------------------------
  courier          text,
  tracking_number  text,
  tracking_url     text,
  shipped_at       timestamptz,
  delivered_at     timestamptz,
  cancelled_at     timestamptz,
  refunded_at      timestamptz,
  refund_amount    numeric(12,2),

  owner_note       text,
  customer_note    text,

  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now(),

  unique (enrollment_id, order_number)
);

create index if not exists idx_shop_order_enr    on shop_orders(enrollment_id, created_at desc);
create index if not exists idx_shop_order_cust   on shop_orders(customer_id);
create index if not exists idx_shop_order_status on shop_orders(enrollment_id, status);
create index if not exists idx_shop_order_gw     on shop_orders(gateway_order_id);

drop trigger if exists trg_shop_order_touch on shop_orders;
create trigger trg_shop_order_touch before update on shop_orders
  for each row execute function spp_touch_updated_at();


create table if not exists shop_order_items (
  id             uuid primary key default gen_random_uuid(),
  order_id       uuid not null references shop_orders(id) on delete cascade,

  -- soft reference: the catalog row may later be deleted or re-synced,
  -- the order must still render exactly as it was placed.
  product_id     uuid references catalog_products(id) on delete set null,
  source_id      text,
  external_id    text,

  -- snapshot ---------------------------------------------------------
  title          text not null,
  image_url      text,
  variant        text,
  brand          text,
  unit_price     numeric(12,2) not null,   -- what the shopper actually paid, per unit
  base_price     numeric(12,2),            -- supplier price at time of order (owner margin audit)
  quantity       int not null check (quantity > 0),
  line_total     numeric(12,2) not null,

  created_at     timestamptz not null default now()
);

create index if not exists idx_shop_orderitem_order on shop_order_items(order_id);


-- per-store order numbering, so store A and store B both start at 1000
create table if not exists shop_order_counter (
  enrollment_id uuid primary key references enrollments(id) on delete cascade,
  next_number   int not null default 1000
);


-- ---------------------------------------------------------------------
-- Reserved for Phase 10. Created now so the shape is settled and we never
-- need a disruptive migration later.
-- ---------------------------------------------------------------------
create table if not exists shop_coupons (
  id            uuid primary key default gen_random_uuid(),
  enrollment_id uuid not null references enrollments(id) on delete cascade,
  code          text not null,
  kind          text not null check (kind in ('percent','flat','free_shipping')),
  value         numeric(12,2) not null default 0,
  min_order     numeric(12,2) not null default 0,
  max_discount  numeric(12,2),
  usage_limit   int,
  used_count    int not null default 0,
  per_customer_limit int,
  first_order_only boolean not null default false,
  starts_at     timestamptz,
  ends_at       timestamptz,
  active        boolean not null default true,
  created_at    timestamptz not null default now(),
  unique (enrollment_id, code)
);

create table if not exists shop_reviews (
  id            uuid primary key default gen_random_uuid(),
  enrollment_id uuid not null references enrollments(id) on delete cascade,
  product_id    uuid not null references catalog_products(id) on delete cascade,
  customer_id   uuid references shop_customers(id) on delete set null,
  order_id      uuid references shop_orders(id) on delete set null,
  rating        int not null check (rating between 1 and 5),
  title         text,
  body          text,
  images        text[] not null default '{}',
  status        text not null default 'pending' check (status in ('pending','approved','rejected')),
  created_at    timestamptz not null default now()
);

create index if not exists idx_shop_review_prod on shop_reviews(enrollment_id, product_id, status);

create table if not exists shop_wishlist (
  id            uuid primary key default gen_random_uuid(),
  enrollment_id uuid not null references enrollments(id) on delete cascade,
  customer_id   uuid not null references shop_customers(id) on delete cascade,
  product_id    uuid not null references catalog_products(id) on delete cascade,
  created_at    timestamptz not null default now(),
  unique (customer_id, product_id)
);


-- =====================================================================
-- SECTION 4 — ROW LEVEL SECURITY
-- The API connects with the service role and bypasses RLS. We still turn
-- RLS on with no policies so that if a Supabase anon/publishable key ever
-- leaks or gets used from a browser, every one of these tables is closed
-- by default rather than world-readable.
-- =====================================================================

alter table store_config          enable row level security;
alter table store_domains         enable row level security;
alter table store_source_markup   enable row level security;
alter table store_pages           enable row level security;
alter table catalog_products      enable row level security;
alter table catalog_sync_state    enable row level security;
alter table shop_customers        enable row level security;
alter table shop_customer_tokens  enable row level security;
alter table shop_addresses        enable row level security;
alter table shop_carts            enable row level security;
alter table shop_cart_items       enable row level security;
alter table shop_orders           enable row level security;
alter table shop_order_items      enable row level security;
alter table shop_order_counter    enable row level security;
alter table shop_coupons          enable row level security;
alter table shop_reviews          enable row level security;
alter table shop_wishlist         enable row level security;

-- No policies are defined on purpose: deny-all for anon/authenticated.


-- =====================================================================
-- SECTION 5 — VERIFICATION (task 0.1.4)
-- Run these after applying. Every one should return the expected result.
-- =====================================================================
--
-- 1. All 17 tables exist:
--    select table_name from information_schema.tables
--     where table_schema='public'
--       and (table_name like 'store\_%' or table_name like 'catalog\_%'
--            or table_name like 'shop\_%')
--     order by 1;
--
-- 2. Nothing pre-existing was modified — these must still be present and
--    unchanged: users, enrollments, enrollment_sources, sources, invoices.
--
-- 3. Tenant isolation: the SAME email on two different enrollments must
--    both insert successfully, and the second insert of the same email on
--    the SAME enrollment must fail with a unique violation.
--
-- 4. RLS is on and closed:
--    select relname, relrowsecurity from pg_class
--     where relname like 'shop\_%';   -- every row true
--
-- =====================================================================
