# Handoff — UltimateScrapper plugin hardening

**Session date:** 26 Jul 2026
**Repo/host:** `/home/ubuntu/UltimateScrapperV2` (Express, pm2 `ultimate-scrapperv2`, port 3002, `43.204.135.214`)
**Artifact this session produced:** WordPress plugin **Server Products v3.6.1**

> Settled detail is **not** repeated here. It lives in the artifacts listed under
> *References*. This document carries only the live thread — what is in flight,
> why, and what to do next.

---

## Where the work stands

This session started as a Flatsome theme audit and turned into a debugging pass on the WordPress plugin. Three things happened in order:

1. **Theme audit → plugin port.** Diffed the client's two Flatsome builds. Parent theme had zero hand-edits; all customization lived in `flatsome-child/`. Ported it into plugin modules (`class-spp-theme`, `class-spp-compat`, `class-spp-checkout`) so the plugin is theme-independent. *Full findings: `theme-audit.md` — not repeated here.*

2. **"Nothing works" debugging (v3.5.0).** User reported Sync now / auto-sync / delete / resync / Request Quote all broken. **Diagnosis: the buttons were wired correctly the whole time — they were silent.** Root causes found:
   - Progress messages were being written to the *error* slot, so `"Re-priced 3000 products"` displayed as a red error. Split into separate `log()` (errors) and `progress()` (activity) channels.
   - `run_batch` read categories from cached status; on a cold cache a manual sync ran against an empty category and imported nothing. Now fetches status first.
   - Request Quote button silently vanished when the WhatsApp number was blank, leaving quote products with no price *and* no way to enquire. Now falls back and the health check flags it.
   - No proof the cron was alive. Added heartbeat timestamps + schedule self-heal.
   - Added: 7-point **Health check** panel, **Price preview** tool, real per-button outcome messages.

3. **Sync cycle + server visibility (v3.6.0), then crash fix (v3.6.1).**
   - Added `x-spp-trigger` header (`auto` / `manual` / `full-resync` / `check`) so server logs distinguish button clicks from background sync.
   - Added a second clock: **automatic full resync, default every 12 h**, configurable, 0 = off. (User explicitly asked for 12 h.)
   - User then hit a WordPress **critical error** during a manual sync. Log line was `http=200 90ms` — the server delivered the products fine and **PHP fataled while importing one of them** (around id 1400, watches). Fixed by making the import crash-proof: per-product `try/catch` on `\Throwable`, cursor advances *before* upsert (so a bad row can't wedge the backfill), every field coerced through new `scalar()`/`num()` helpers, and object-cache suppression during bulk runs to stop memory exhaustion.

**Nothing from step 3 has been verified on the live site.** That is the top of the next session's list.

---

## In flight / unverified

| Item | State |
|---|---|
| **v3.6.1 crash fix** | Built, brace-checked, logic-tested. **Not installed, not verified.** The root-cause product is still unidentified. |
| Re-price over ~25k products | Was mid-run at session end. Re-price *pauses* sync while it runs (by design) — this is why the catalogue looked stalled. Resumes automatically. |
| Server log middleware | `spp-sync-logger.js` written and syntax-checked, **not yet mounted** in the Express app. |
| DB verification | `db-health.js` / `db-quick-check.sh` written, **not yet run**. User asked "is the server really saving products?" — answered from indirect evidence (25,172 products pulled, `http=200` from `sync-feed`, which reads SQLite directly), but never confirmed against the actual DB. |
| Child-theme cleanup | **Not done.** Mandatory — see risk below. |

---

## Live decisions from this session

- **Luxury Watch showing Request Quote on every product is intentional**, not a bug. The category rule is a single band `0 → and above` with a blank margin, which forces quote-only regardless of price. User confirmed they want this. Quote detection now also computes *live* from category + price, so products show the button immediately after sync without waiting for a re-price pass.
- **Errors and progress are now separate channels.** Anything written via `progress()` is normal activity and must not surface as an error.
- **The cursor advances before the upsert, deliberately.** A permanently-failing product is skipped and logged rather than retried forever.
- Re-price intentionally takes priority over sync in the heartbeat. Do not "fix" the resulting apparent stall.

---

## Risks / gotchas for the next agent

- ⚠️ **Installing v3.6.1 without deleting the ported code from `flatsome-child/functions.php` causes fatal "cannot redeclare" errors.** The plugin and child theme would both define the same functions. Removal list is in `theme-audit.md` Part 5.
- ⚠️ **The previously-generated `handoff-summary.md` contains live credentials in plaintext** (payment gateway key, SMTP app password, enrollment keys, a personal email, WhatsApp numbers). The user stated they intend to upload it as project knowledge. Flag before that happens; those secrets were also pasted into chat and are worth rotating.
- `watches.db` and `shoes.db` have **separate productId spaces that collide**. Always carry `dbName`; SKUs are `SPP-<db>-<id>`.
- WooCommerce REST does **not** natively support `meta_key`/`meta_value` queries. `class-spp-compat.php` enables it, and the server routes `/dev/outofstock5days` and `/dev/clean-old-oos-products` depend on it. Do not remove it.
- Route mount order matters: `/product/refresh-one` must be mounted **before** the `/:id` catch-all in `productRoutes`, or it returns `{"results":[]}`.
- The old tenant system (TheAquaWatch → TimesKeepers / stylenova via `/dev` + `tenantIdentify`) still runs in parallel and must not break.
- Sandbox limits when working on this: no PHP CLI available (use a string-aware brace checker, not `grep -c`, which miscounts `{$var}` interpolation and `[]` subscripts — this produced a false alarm and then caught a real orphaned function this session); `node --check` works; outbound network is allow-listed and **cannot reach the user's server**.

---

## Next steps

1. **Install v3.6.1, click Sync now, confirm the site stays up.** Then find the `Skipped product NNNN: <reason>` line (plugin status panel, or `wp-content/debug.log` with `WP_DEBUG_LOG` on) and fix the underlying data issue. The WordPress admin-email crash report (file + line of the original fatal) would pinpoint it fastest — ask for it.
2. Do the **child-theme cleanup** (`theme-audit.md` Part 5) — before or immediately after activating v3.6.1.
3. Mount `sppSyncLogger()` in Express ahead of the product routes; optionally set `res.locals.sppCount = results.length` in the `sync-feed` handler for row counts. Confirm 🟢 MANUAL / 🔵 FULL RESYNC lines appear in `pm2 logs`.
4. Run `db-health.js` on the server. Healthy = non-zero counts per source **and** "updated in last 24 h" close to the total. If totals are high but 24 h counts are near zero, the scraper has stalled — investigate the scrape scheduler, not the plugin.
5. Watch the first automatic 12 h full resync fire (panel shows "next in Xh") and confirm the count stays stable.
6. Verify the Luxury Watch category page once re-price completes — every product should show Request Quote.

**Loose ends:** prepaid discount is ₹199 in settings but the storefront banner says ₹200 — reconcile before enabling the checkout module. Undecided whether to keep `ux_products_child.php` shortcodes. A written plugin changelog was offered and never produced.

---

## Suggested skills

- **`engineering/diagnosing-bugs`** — for step 1. There is a known fatal with a narrow reproduction (one product, ~id 1400, watches DB) and a now-instrumented log line pointing at it. This is the main open thread.
- **`engineering/code-review`** — the plugin grew fast across three versions in one session. A review pass over `class-spp-sync.php` and `class-spp-product.php` is warranted, particularly the new coercion helpers and the cursor-advance-before-upsert ordering.
- **`engineering/triage`** — several parallel pending lists (server-side mounts, WordPress-side cleanup, verification) that would benefit from being ordered against actual risk.
- **`engineering/implement`** — once the root-cause product is identified and a fix is scoped.
- **`productivity/handoff`** — re-run at the end of the next session; this thread turns over quickly.

---

## References (do not re-derive — read these)

| Path | What it holds |
|---|---|
| `server-products.zip` | The plugin, **v3.6.1**. 15 PHP files. The main deliverable. |
| `theme-audit.md` | Complete Flatsome audit: 18 `functions.php` customizations, 8 modified templates, per-site values, what was ported vs deliberately dropped, and the ordered migration/cleanup steps. |
| `handoff-summary.md` | Prior full-project handoff: architecture, all key decisions, live site configs, credentials **(unredacted — see risk above)**. |
| `client-pitch.md` | Client-facing sales pitch. Complete, unrelated to the open bug. |
| `spp-sync-logger.js` | Express middleware; tags sync calls by trigger. Not yet mounted. |
| `db-health.js` / `db-quick-check.sh` | Read-only SQLite catalogue reports (counts, freshness, per-source, stock split). Not yet run. |
| `/mnt/transcripts/` (+ `journal.txt`) | Full transcripts of all prior sessions. |

**Credentials:** deliberately omitted. Payment-gateway key, SMTP app password, and per-site enrollment keys are in `handoff-summary.md` and the user's own records. Per-site WhatsApp numbers, brand allow-lists, and hidden-category slugs now live in plugin settings rather than code.
