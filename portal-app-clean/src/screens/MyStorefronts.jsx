import React, { useEffect, useState } from "react";
import { ExternalLink, Plus, ArrowLeft } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Btn, Badge, Field, inputStyle, Spinner, ErrorNote, Empty, Modal, fmtDate, storeUrl } from "../ui.jsx";

const ORDER_STATUSES = ["pending", "confirmed", "shipped", "delivered", "cancelled"];

export default function MyStorefronts() {
  const [sites, setSites] = useState(null);
  const [error, setError] = useState(null);
  const [creating, setCreating] = useState(false);
  const [selected, setSelected] = useState(null); // site object, or null for list view

  async function load() {
    setError(null);
    try { setSites((await api.myHostedSites()).sites || []); }
    catch (e) { setError(e); }
  }
  useEffect(() => { load(); }, []);

  // keep the open detail view's own data (status/expiry) fresh after an edit
  useEffect(() => {
    if (selected && sites) {
      const fresh = sites.find((s) => s.id === selected.id);
      if (fresh) setSelected(fresh);
    }
  }, [sites]); // eslint-disable-line react-hooks/exhaustive-deps

  if (selected) return <StoreDetail site={selected} onBack={() => setSelected(null)} onChanged={load} />;

  if (error) return (<div><PageHead title="My storefront" /><ErrorNote error={error} /></div>);
  if (!sites) return (<div><PageHead title="My storefront" /><Spinner /></div>);

  return (
    <div>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 12 }}>
        <PageHead title="My storefront" sub="A hosted storefront that shows your own branding and your selected products. Buyers check out via WhatsApp." />
        <Btn tone="lime" onClick={() => setCreating(true)}><Plus size={15} style={{ verticalAlign: "-2px" }} /> New storefront</Btn>
      </div>
      {creating && <CreateSiteModal onClose={() => setCreating(false)} onDone={() => { setCreating(false); load(); }} />}

      {sites.length === 0 ? <Card><Empty msg="No storefronts yet. Create one to get a link you can share." /></Card> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
          {sites.map((s) => (
            <Card key={s.id} style={{ cursor: "pointer" }}>
              <div onClick={() => setSelected(s)} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
                  {s.logo_url
                    ? <img src={s.logo_url} alt="" style={{ width: 36, height: 36, borderRadius: 8, objectFit: "cover" }} />
                    : <div style={{ width: 36, height: 36, borderRadius: 8, background: "#eef1f6" }} />}
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 14.5 }}>{s.store_name}</div>
                    <div style={{ fontSize: 12, color: "#6b7688", marginTop: 2 }}>
                      {s.slug} · {s.status === "active" ? `expires ${fmtDate(s.expiry_date)}` : "not live yet"}
                    </div>
                  </div>
                </div>
                <Badge status={s.status} />
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

function CreateSiteModal({ onClose, onDone }) {
  const [storeName, setStoreName] = useState("");
  const [slug, setSlug] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function submit() {
    if (!storeName.trim()) { setError(new Error("Store name required")); return; }
    setBusy(true); setError(null);
    try { await api.createHostedSite(storeName.trim(), slug.trim() || undefined); onDone(); }
    catch (e) { setError(e); setBusy(false); }
  }

  return (
    <Modal title="New storefront" onClose={onClose}>
      <ErrorNote error={error} />
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 12 }}>
        Starts <strong>pending admin approval</strong>. Once approved and activated, it goes live at its link —
        pick your products and branding any time before or after that.
      </div>
      <Field label="Store name">
        <input style={inputStyle} value={storeName} onChange={(e) => setStoreName(e.target.value)} placeholder="Aqua Watch" autoFocus />
      </Field>
      <Field label="Link (optional — auto-generated from the name if left blank)">
        <input style={inputStyle} value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="aqua-watch" />
      </Field>
      <div style={{ display: "flex", gap: 8, marginTop: 16 }}>
        <Btn tone="lime" onClick={submit} disabled={busy}>{busy ? "Creating…" : "Create storefront"}</Btn>
        <Btn tone="ghost" onClick={onClose}>Cancel</Btn>
      </div>
    </Modal>
  );
}

function StoreDetail({ site, onBack, onChanged }) {
  const [srcVer, setSrcVer] = useState(0); // bumped on source change → NavigationPanel refetches its category list
  // New/not-yet-live stores get the step-by-step guided setup; once active the
  // full single-page editor (what we had) is the default. Toggle either way.
  const [mode, setMode] = useState(site.status === "active" ? "edit" : "wizard");
  const bumpSrc = () => setSrcVer((v) => v + 1);

  return (
    <div>
      <button onClick={onBack} style={{ display: "flex", alignItems: "center", gap: 6, background: "none", border: "none", cursor: "pointer", color: "#6b7688", fontSize: 13, padding: 0, marginBottom: 14 }}>
        <ArrowLeft size={15} /> All storefronts
      </button>

      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 12, marginBottom: 20 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 700, color: "#1b2230" }}>{site.store_name}</h1>
          <div style={{ marginTop: 6, display: "flex", alignItems: "center", gap: 10 }}>
            <Badge status={site.status} />
            <a href={storeUrl(site.slug)} target="_blank" rel="noreferrer" style={{ fontSize: 12.5, color: "#3b6fd8", display: "flex", alignItems: "center", gap: 4, textDecoration: "none" }}>
              {storeUrl(site.slug)} <ExternalLink size={12} />
            </a>
          </div>
        </div>
        <Btn tone="ghost" small onClick={() => setMode((m) => (m === "wizard" ? "edit" : "wizard"))}>
          {mode === "wizard" ? "Edit all settings" : "Guided setup"}
        </Btn>
      </div>

      {site.status === "paused" && (
        <Card style={{ marginBottom: 18, background: "#fdecef", border: "1px solid #f3c2cc" }}>
          <div style={{ fontSize: 13, color: "#a23a4b" }}>
            This storefront is <strong>paused</strong> — buyers can't reach it. It paused because the renewal wasn't paid within the 5-day grace after expiry. Renew from the <strong>Payment</strong> step to bring it back online.
          </div>
        </Card>
      )}
      {site.status !== "active" && site.status !== "paused" && (
        <Card style={{ marginBottom: 18, background: "#fff7e6", border: "1px solid #f0d9a8" }}>
          <div style={{ fontSize: 13, color: "#8a6d2f" }}>
            This storefront is <strong>{site.status}</strong> — it goes live once an admin approves it and the first payment is made.
            Set up branding and products now so it's ready the moment you pay.
          </div>
        </Card>
      )}

      {mode === "wizard" ? (
        <StoreSetupWizard site={site} srcVer={srcVer} bumpSrc={bumpSrc} onChanged={onChanged} />
      ) : (
        <>
          <ProductsPanel siteId={site.id} onChanged={bumpSrc} />
          <div style={{ height: 18 }} />
          <NavigationPanel key={srcVer} siteId={site.id} />
          <div style={{ height: 18 }} />
          <HomepagePresetPanel siteId={site.id} />
          <div style={{ height: 18 }} />
          <CustomDomainPanel site={site} onChanged={onChanged} />
          <div style={{ height: 18 }} />
          <SettingsPanel siteId={site.id} />
          <div style={{ height: 18 }} />
          <PaymentPanel site={site} />
          <div style={{ height: 18 }} />
          <OrdersPanel siteId={site.id} />
        </>
      )}
    </div>
  );
}

// Step-by-step guided setup. Required steps (branding, products) can't be
// skipped; optional ones can. Payment is last and skippable — but the store only
// goes live once it's paid.
function StoreSetupWizard({ site, srcVer, bumpSrc, onChanged }) {
  const steps = [
    { key: "branding", title: "Branding", required: true, hint: "Name, logo, colours, contact — the essentials.", render: () => <SettingsPanel siteId={site.id} /> },
    { key: "products", title: "Products", required: true, hint: "Pick which sources feed your storefront.", render: () => <ProductsPanel siteId={site.id} onChanged={bumpSrc} /> },
    { key: "navigation", title: "Navigation & front page", required: false, hint: "Optional — curate your menu and home page.", render: () => <NavigationPanel key={srcVer} siteId={site.id} /> },
    { key: "homepage", title: "Homepage layout", required: false, hint: "Optional — pick a ready-made layout.", render: () => <HomepagePresetPanel siteId={site.id} /> },
    { key: "domain", title: "Custom domain", required: false, hint: "Optional — use your own domain.", render: () => <CustomDomainPanel site={site} onChanged={onChanged} /> },
    { key: "payment", title: "Payment & go live", required: false, hint: "Skippable — but the store only goes live once paid.", render: () => <PaymentPanel site={site} /> },
  ];
  const [i, setI] = useState(0);
  const step = steps[i];
  const last = i === steps.length - 1;

  return (
    <div style={{ display: "grid", gridTemplateColumns: "220px 1fr", gap: 20, alignItems: "start" }}>
      {/* step rail */}
      <Card style={{ position: "sticky", top: 16 }}>
        <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 10 }}>Setup steps</div>
        <div style={{ display: "flex", flexDirection: "column", gap: 3 }}>
          {steps.map((s, j) => (
            <button key={s.key} onClick={() => setI(j)}
              style={{ display: "flex", alignItems: "center", gap: 9, textAlign: "left", border: "none", cursor: "pointer",
                background: j === i ? "#eef6ff" : "transparent", borderRadius: 8, padding: "8px 10px", fontSize: 12.5,
                color: j === i ? "#1b2230" : "#42505f", fontWeight: j === i ? 700 : 500 }}>
              <span style={{ width: 20, height: 20, borderRadius: 999, display: "grid", placeItems: "center", fontSize: 11,
                background: j < i ? "#C8FF3D" : j === i ? "#1b2230" : "#e6e9f0", color: j === i ? "#fff" : "#1b2230" }}>
                {j < i ? "✓" : j + 1}
              </span>
              <span style={{ flex: 1 }}>{s.title}</span>
              {!s.required && <span style={{ fontSize: 10, color: "#9aa3b2" }}>optional</span>}
            </button>
          ))}
        </div>
      </Card>

      {/* current step */}
      <div>
        <div style={{ marginBottom: 12 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: "#1b2230" }}>{step.title}</h2>
            <Badge status={step.required ? "required" : "optional"} />
          </div>
          <div style={{ fontSize: 12.5, color: "#6b7688", marginTop: 4 }}>{step.hint}</div>
        </div>

        {step.render()}

        <div style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 18 }}>
          <Btn tone="ghost" disabled={i === 0} onClick={() => setI((n) => Math.max(0, n - 1))}>← Back</Btn>
          <div style={{ flex: 1 }} />
          {!step.required && !last && <Btn tone="ghost" onClick={() => setI((n) => n + 1)}>Skip</Btn>}
          {!last && <Btn tone="lime" onClick={() => setI((n) => n + 1)}>Save &amp; continue →</Btn>}
          {last && <Btn tone="lime" onClick={onChanged}>Done</Btn>}
        </div>
      </div>
    </div>
  );
}

// Payment step: shows the store's latest invoice and a Pay button. Paying
// activates the store (and renews it when it's expired/paused).
function PaymentPanel({ site }) {
  const [invoices, setInvoices] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.invoices().then((r) => setInvoices((r.invoices || []).filter((iv) => iv.enrollment_id === site.id))).catch(setError);
  }, [site.id]);

  async function pay(id) {
    setBusy(true); setError(null);
    try { const r = await api.payInvoice(id); if (r.payment_url) window.location.href = r.payment_url; else setError(new Error("No payment URL returned.")); }
    catch (e) { setError(e); setBusy(false); }
  }

  const unpaid = (invoices || []).find((iv) => iv.status !== "paid");

  return (
    <Card>
      <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 4 }}>Payment &amp; go live</div>
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 12 }}>
        Your storefront goes live once payment is made. It stays active for a month; near expiry you'll be reminded, with a 5-day grace before it pauses.
      </div>
      <ErrorNote error={error} />
      {site.status === "active" && (
        <div style={{ fontSize: 13, color: "#2e7d32", marginBottom: 10 }}>
          ✓ This storefront is <strong>live</strong>{site.expiry_date ? ` — renews on ${fmtDate(site.expiry_date)}` : ""}.
        </div>
      )}
      {!invoices ? <Spinner /> : unpaid ? (
        <div style={{ border: "1px solid #eef1f6", borderRadius: 10, padding: 14, display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12 }}>
          <div>
            <div style={{ fontWeight: 700, fontSize: 14 }}>{unpaid.invoice_no} · ₹{Number(unpaid.amount).toLocaleString("en-IN")}</div>
            <div style={{ fontSize: 12, color: "#6b7688", marginTop: 2 }}>{unpaid.status}{unpaid.due_date ? ` · due ${fmtDate(unpaid.due_date)}` : ""}</div>
          </div>
          <Btn tone="lime" disabled={busy} onClick={() => pay(unpaid.id)}>{busy ? "Redirecting…" : "Pay now"}</Btn>
        </div>
      ) : invoices.length === 0 ? (
        <div style={{ fontSize: 13, color: "#8a6d2f" }}>
          No invoice yet — your store is awaiting admin approval. An invoice appears here the moment it's approved; pay it to go live.
        </div>
      ) : (
        <div style={{ fontSize: 13, color: "#2e7d32" }}>✓ All invoices paid.</div>
      )}
    </Card>
  );
}

// Which product sources feed this storefront. The vendor picks from the active
// source catalogue; the storefront then shows only those sources' products.
function ProductsPanel({ siteId, onChanged }) {
  const [data, setData] = useState(null); // { available, attached, categories }
  const [sel, setSel] = useState(null);   // Set of selected source ids
  const [q, setQ] = useState("");
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.hostedSiteSources(siteId)
      .then((r) => { setData(r); setSel(new Set(r.attached)); })
      .catch(setError);
  }, [siteId]);

  function toggle(id) {
    setSaved(false);
    setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n; });
  }

  async function save() {
    setBusy(true); setError(null); setSaved(false);
    try { await api.saveHostedSiteSources(siteId, [...sel]); setSaved(true); onChanged?.(); }
    catch (e) { setError(e); }
    finally { setBusy(false); }
  }

  if (error) return <Card><div style={{ fontWeight: 700, fontSize: 15, marginBottom: 8 }}>Products</div><ErrorNote error={error} /></Card>;
  if (!data) return <Card><Spinner msg="Loading sources…" /></Card>;

  const term = q.trim().toLowerCase();
  const shown = data.available.filter((s) => !term || s.name.toLowerCase().includes(term) || s.id.toLowerCase().includes(term));
  const byCat = shown.reduce((m, s) => { (m[s.category] ||= []).push(s); return m; }, {});

  return (
    <Card>
      <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 4 }}>Products</div>
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 12 }}>
        Choose which product sources feed this storefront. Your shop shows only the products from the sources you pick.
        <strong> {sel.size}</strong> selected.
      </div>
      <ErrorNote error={error} />
      <input style={{ ...inputStyle, marginBottom: 12 }} placeholder="Filter sources…" value={q} onChange={(e) => setQ(e.target.value)} />
      <div style={{ maxHeight: 320, overflowY: "auto", border: "1px solid #eef1f6", borderRadius: 8, padding: "4px 0" }}>
        {Object.keys(byCat).sort().map((cat) => (
          <div key={cat}>
            <div style={{ padding: "8px 14px 4px", fontSize: 11, textTransform: "uppercase", letterSpacing: 0.5, color: "#9aa3b2", fontWeight: 700 }}>{cat}</div>
            {byCat[cat].map((s) => (
              <label key={s.id} style={{ display: "flex", alignItems: "center", gap: 10, padding: "7px 14px", cursor: "pointer", fontSize: 13 }}>
                <input type="checkbox" checked={sel.has(s.id)} onChange={() => toggle(s.id)} />
                <span style={{ color: "#1b2230" }}>{s.name}</span>
                <span style={{ color: "#b3bccb", fontSize: 11 }}>{s.id}</span>
              </label>
            ))}
          </div>
        ))}
        {shown.length === 0 && <div style={{ padding: 14, color: "#9aa3b2", fontSize: 12.5 }}>No sources match "{q}".</div>}
      </div>
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 12 }}>
        <Btn tone="lime" onClick={save} disabled={busy}>{busy ? "Saving…" : "Save products"}</Btn>
        {saved && <span style={{ fontSize: 12.5, color: "#2e7d32" }}>Saved ✓ — live on your storefront now</span>}
      </div>
    </Card>
  );
}

// Which categories (and, under each, which brands) appear in the storefront's
// menu and home page — with vendor-controlled ORDER and per-item THUMBNAILS.
// nav = { items:  [{ category, label, on_home, thumbnail }],
//         brands: [{ category, brand, label, on_home, thumbnail }] }
// Empty items = storefront shows all attached categories in default order.
function NavigationPanel({ siteId }) {
  const [order, setOrder] = useState(null);       // category names, in display order
  const [items, setItems] = useState({});         // cat -> { include, on_home, label, thumbnail }
  const [brandsAvail, setBrandsAvail] = useState({}); // cat -> [{name,count}] | "loading"
  const [brandSel, setBrandSel] = useState({});   // "cat brand" -> { on_home, label, thumbnail } (presence = featured)
  const [openCat, setOpenCat] = useState(null);   // which category's brand list is expanded
  const [brandQuery, setBrandQuery] = useState({}); // cat -> search term for the featured-brand picker
  const [subcatsAvail, setSubcatsAvail] = useState({});     // cat -> [{name,count}] | "loading"
  const [subcatSel, setSubcatSel] = useState({});           // "cat::subcat" -> { category, subcat, label, on_home }
  const [openSubcatCat, setOpenSubcatCat] = useState(null);
  const [subbrandsAvail, setSubbrandsAvail] = useState({}); // "cat::brand" -> [{name,count}] | "loading"
  const [subbrandSel, setSubbrandSel] = useState({});       // "cat::brand::sub" -> { category, brand, sub_brand, label, on_home }
  const [openSubbrand, setOpenSubbrand] = useState(null);   // "cat::brand"
  const [links, setLinks] = useState([]);                   // custom nav links [{ label, url }]
  const [hideUnmapped, setHideUnmapped] = useState(false); // hide sub-categories not renamed in your map
  const [navLayout, setNavLayout] = useState("single");    // single | double (logo-centred + second nav row)
  const [showCats, setShowCats] = useState(true);          // show categories in the menu
  const [showBrands, setShowBrands] = useState(false);     // show featured brands in the menu
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(null);

  const bkey = (c, b) => `${c} ${b}`;

  useEffect(() => {
    Promise.all([api.hostedSiteSources(siteId), api.hostedSiteSettings(siteId)])
      .then(([src, s]) => {
        const available = src.categories || [];
        const nav = s.settings?.nav || {};
        const navItems = nav.items || [];
        const byCat = Object.fromEntries(navItems.map((i) => [i.category, i]));
        // display order: saved order first (still-available only), then any new categories
        const savedOrder = navItems.map((i) => i.category).filter((c) => available.includes(c));
        setOrder([...savedOrder, ...available.filter((c) => !savedOrder.includes(c))]);
        setItems(Object.fromEntries(available.map((c) => {
          const cur = byCat[c];
          return [c, { include: !!cur, on_home: cur ? cur.on_home !== false : true, label: (cur && cur.label) || cap(c), thumbnail: (cur && cur.thumbnail) || "" }];
        })));
        setBrandSel(Object.fromEntries((nav.brands || []).map((b) =>
          [bkey(b.category, b.brand), { category: b.category, brand: b.brand, on_home: b.on_home !== false, label: b.label || b.brand, thumbnail: b.thumbnail || "" }])));
        setHideUnmapped(!!nav.hide_unmapped);
        setNavLayout(nav.layout === "double" ? "double" : "single");
        setShowCats(nav.show_categories !== false);
        setShowBrands(!!nav.show_brands);
        setSubcatSel(Object.fromEntries((nav.subcats || []).map((s) =>
          [`${s.category}::${s.subcat}`, { category: s.category, subcat: s.subcat, label: s.label || s.subcat, on_home: s.on_home !== false }])));
        setSubbrandSel(Object.fromEntries((nav.subbrands || []).map((s) =>
          [`${s.category}::${s.brand}::${s.sub_brand}`, { category: s.category, brand: s.brand, sub_brand: s.sub_brand, label: s.label || s.sub_brand, on_home: s.on_home !== false }])));
        setLinks(Array.isArray(nav.links) ? nav.links.map((l) => ({ label: l.label || "", url: l.url || "" })) : []);
      })
      .catch(setError);
  }, [siteId]);

  function set(cat, key, val) { setSaved(false); setItems((m) => ({ ...m, [cat]: { ...m[cat], [key]: val } })); }
  function move(i, dir) {
    setSaved(false);
    setOrder((o) => { const n = [...o]; const j = i + dir; if (j < 0 || j >= n.length) return o; [n[i], n[j]] = [n[j], n[i]]; return n; });
  }

  async function toggleBrandList(c) {
    if (openCat === c) { setOpenCat(null); return; }
    setOpenCat(c);
    if (!brandsAvail[c]) {
      setBrandsAvail((m) => ({ ...m, [c]: "loading" }));
      try { const r = await api.hostedSiteBrands(siteId, c); setBrandsAvail((m) => ({ ...m, [c]: r.brands || [] })); }
      catch { setBrandsAvail((m) => ({ ...m, [c]: [] })); }
    }
  }
  function toggleBrand(c, b) {
    setSaved(false);
    setBrandSel((m) => { const k = bkey(c, b); const n = { ...m }; if (n[k]) delete n[k]; else n[k] = { category: c, brand: b, on_home: true, label: b, thumbnail: "" }; return n; });
  }
  function setBrand(c, b, key, val) { setSaved(false); setBrandSel((m) => ({ ...m, [bkey(c, b)]: { ...m[bkey(c, b)], [key]: val } })); }

  // ---- sub-categories ----
  const skey = (c, s) => `${c}::${s}`;
  async function toggleSubcatList(c) {
    if (openSubcatCat === c) { setOpenSubcatCat(null); return; }
    setOpenSubcatCat(c);
    if (!subcatsAvail[c]) {
      setSubcatsAvail((m) => ({ ...m, [c]: "loading" }));
      try { const r = await api.hostedSiteSubcategories(siteId, c); setSubcatsAvail((m) => ({ ...m, [c]: r.subcategories || [] })); }
      catch { setSubcatsAvail((m) => ({ ...m, [c]: [] })); }
    }
  }
  function toggleSubcat(c, s) {
    setSaved(false);
    setSubcatSel((m) => { const k = skey(c, s); const n = { ...m }; if (n[k]) delete n[k]; else n[k] = { category: c, subcat: s, label: s, on_home: true }; return n; });
  }
  function setSubcat(c, s, key, val) { setSaved(false); setSubcatSel((m) => ({ ...m, [skey(c, s)]: { ...m[skey(c, s)], [key]: val } })); }

  // ---- sub-brands (under a featured brand) ----
  const sbkey = (c, b, s) => `${c}::${b}::${s}`;
  async function toggleSubbrandList(c, b) {
    const k = `${c}::${b}`;
    if (openSubbrand === k) { setOpenSubbrand(null); return; }
    setOpenSubbrand(k);
    if (!subbrandsAvail[k]) {
      setSubbrandsAvail((m) => ({ ...m, [k]: "loading" }));
      try { const r = await api.hostedSiteSubBrands(siteId, c, b); setSubbrandsAvail((m) => ({ ...m, [k]: r.subbrands || [] })); }
      catch { setSubbrandsAvail((m) => ({ ...m, [k]: [] })); }
    }
  }
  function toggleSubbrand(c, b, s) {
    setSaved(false);
    setSubbrandSel((m) => { const k = sbkey(c, b, s); const n = { ...m }; if (n[k]) delete n[k]; else n[k] = { category: c, brand: b, sub_brand: s, label: s, on_home: true }; return n; });
  }

  // ---- custom links ----
  function addLink() { setSaved(false); setLinks((l) => [...l, { label: "", url: "" }]); }
  function setLink(i, key, val) { setSaved(false); setLinks((l) => l.map((row, j) => j === i ? { ...row, [key]: val } : row)); }
  function removeLink(i) { setSaved(false); setLinks((l) => l.filter((_, j) => j !== i)); }

  async function save() {
    setBusy(true); setError(null); setSaved(false);
    try {
      const nav = {
        items: (order || []).filter((c) => items[c]?.include).map((c) => ({
          category: c, label: (items[c].label || cap(c)).trim(), on_home: !!items[c].on_home, thumbnail: (items[c].thumbnail || "").trim(),
        })),
        brands: Object.values(brandSel).map((v) => ({
          category: v.category, brand: v.brand, label: (v.label || v.brand).trim(), on_home: !!v.on_home, thumbnail: (v.thumbnail || "").trim(),
        })),
        subcats: Object.values(subcatSel).map((v) => ({ category: v.category, subcat: v.subcat, label: (v.label || v.subcat).trim(), on_home: !!v.on_home })),
        subbrands: Object.values(subbrandSel).map((v) => ({ category: v.category, brand: v.brand, sub_brand: v.sub_brand, label: (v.label || v.sub_brand).trim(), on_home: !!v.on_home })),
        links: links.map((l) => ({ label: (l.label || "").trim(), url: (l.url || "").trim() })).filter((l) => l.label && l.url),
        hide_unmapped: hideUnmapped,
        layout: navLayout,
        show_categories: showCats,
        show_brands: showBrands,
      };
      await api.saveHostedSiteSettings(siteId, { nav });
      setSaved(true);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  }

  const th = { width: 84 };

  return (
    <Card>
      <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 4 }}>Navigation & front page</div>
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 12 }}>
        Choose which categories (and brands under them) appear in your menu and on the home page, in the order you want,
        each with its own thumbnail. Leave everything unticked to show all your categories automatically, in default order.
      </div>
      <ErrorNote error={error} />
      {!order ? <Spinner msg="Loading categories…" /> : order.length === 0 ? (
        <Empty msg="No categories yet — add product sources above first." />
      ) : (
        <>
          {order.map((c, i) => {
            const it = items[c] || {};
            const av = brandsAvail[c];
            return (
              <div key={c} style={{ border: "1px solid #eef1f6", borderRadius: 10, padding: 12, marginBottom: 10 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
                  <div style={{ display: "flex", flexDirection: "column" }}>
                    <button type="button" onClick={() => move(i, -1)} disabled={i === 0} style={arrowBtn(i === 0)}>▲</button>
                    <button type="button" onClick={() => move(i, +1)} disabled={i === order.length - 1} style={arrowBtn(i === order.length - 1)}>▼</button>
                  </div>
                  <span style={{ fontSize: 13, fontWeight: 700, color: "#1b2230", minWidth: 70, textTransform: "capitalize" }}>{c}</span>
                  <label style={ckLbl}><input type="checkbox" checked={!!it.include} onChange={(e) => set(c, "include", e.target.checked)} /> In menu</label>
                  <label style={ckLbl}><input type="checkbox" checked={!!it.on_home} onChange={(e) => set(c, "on_home", e.target.checked)} /> On home</label>
                  <button type="button" onClick={() => toggleSubcatList(c)} style={{ marginLeft: "auto", ...linkBtn }}>
                    {openSubcatCat === c ? "Hide sub-cats ▲" : "Sub-cats ▼"}
                  </button>
                  <button type="button" onClick={() => toggleBrandList(c)} style={{ ...linkBtn }}>
                    {openCat === c ? "Hide brands ▲" : "Brands ▼"}
                  </button>
                </div>
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, marginTop: 8 }}>
                  <input style={inputStyle} value={it.label || ""} onChange={(e) => set(c, "label", e.target.value)} placeholder={`Menu label (${cap(c)})`} />
                  <input style={inputStyle} value={it.thumbnail || ""} onChange={(e) => set(c, "thumbnail", e.target.value)} placeholder="Thumbnail image URL (optional)" />
                </div>

                {openSubcatCat === c && (() => {
                  const sav = subcatsAvail[c];
                  return (
                    <div style={{ marginTop: 10, borderTop: "1px dashed #e6e9f0", paddingTop: 10 }}>
                      <div style={{ fontSize: 11, textTransform: "uppercase", letterSpacing: 0.4, color: "#9aa3b2", marginBottom: 6 }}>Featured sub-categories in {cap(c)}</div>
                      {sav === "loading" || !sav ? <Spinner msg="Loading…" /> : sav.length === 0 ? (
                        <div style={{ fontSize: 12, color: "#9aa3b2" }}>No sub-categories in this category yet.</div>
                      ) : (
                        <div style={{ maxHeight: 240, overflowY: "auto", display: "flex", flexDirection: "column", gap: 6 }}>
                          {sav.map((sc) => {
                            const sel = subcatSel[skey(c, sc.name)];
                            return (
                              <div key={sc.name} style={{ border: "1px solid #f0f2f6", borderRadius: 8, padding: "7px 9px" }}>
                                <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5, cursor: "pointer" }}>
                                  <input type="checkbox" checked={!!sel} onChange={() => toggleSubcat(c, sc.name)} />
                                  <span style={{ color: "#1b2230" }}>{sc.name}</span>
                                  <span style={{ color: "#b3bccb", fontSize: 11 }}>{sc.count}</span>
                                </label>
                                {sel && (
                                  <div style={{ display: "grid", gridTemplateColumns: "1fr auto", gap: 6, marginTop: 6 }}>
                                    <input style={inputStyle} value={sel.label || ""} onChange={(e) => setSubcat(c, sc.name, "label", e.target.value)} placeholder={`Label (${sc.name})`} />
                                    <label style={{ ...ckLbl, whiteSpace: "nowrap" }}><input type="checkbox" checked={sel.on_home !== false} onChange={(e) => setSubcat(c, sc.name, "on_home", e.target.checked)} /> Home</label>
                                  </div>
                                )}
                              </div>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  );
                })()}

                {openCat === c && (
                  <div style={{ marginTop: 10, borderTop: "1px dashed #e6e9f0", paddingTop: 10 }}>
                    <div style={{ fontSize: 11, textTransform: "uppercase", letterSpacing: 0.4, color: "#9aa3b2", marginBottom: 6 }}>Featured brands in {cap(c)}</div>
                    {av === "loading" || !av ? <Spinner msg="Loading brands…" /> : av.length === 0 ? (
                      <div style={{ fontSize: 12, color: "#9aa3b2" }}>No brands with in-stock products in this category yet.</div>
                    ) : (() => {
                      const q = (brandQuery[c] || "").toLowerCase();
                      const shown = q ? av.filter((br) => br.name.toLowerCase().includes(q)) : av;
                      return (
                      <>
                      <input style={{ ...inputStyle, marginBottom: 8 }} value={brandQuery[c] || ""} onChange={(e) => setBrandQuery((m) => ({ ...m, [c]: e.target.value }))} placeholder={`Search brands (${av.length})…`} />
                      {shown.length === 0 ? <div style={{ fontSize: 12, color: "#9aa3b2" }}>No brands match.</div> : (
                      <div style={{ maxHeight: 260, overflowY: "auto", display: "flex", flexDirection: "column", gap: 6 }}>
                        {shown.map((br) => {
                          const sel = brandSel[bkey(c, br.name)];
                          return (
                            <div key={br.name} style={{ border: "1px solid #f0f2f6", borderRadius: 8, padding: "7px 9px" }}>
                              <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5, cursor: "pointer" }}>
                                <input type="checkbox" checked={!!sel} onChange={() => toggleBrand(c, br.name)} />
                                <span style={{ color: "#1b2230" }}>{br.name}</span>
                                <span style={{ color: "#b3bccb", fontSize: 11 }}>{br.count}</span>
                              </label>
                              {sel && (<>
                                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr auto", gap: 6, marginTop: 6 }}>
                                  <input style={inputStyle} value={sel.label || ""} onChange={(e) => setBrand(c, br.name, "label", e.target.value)} placeholder={`Label (${br.name})`} />
                                  <input style={inputStyle} value={sel.thumbnail || ""} onChange={(e) => setBrand(c, br.name, "thumbnail", e.target.value)} placeholder="Thumbnail URL" />
                                  <label style={{ ...ckLbl, whiteSpace: "nowrap" }}><input type="checkbox" checked={sel.on_home !== false} onChange={(e) => setBrand(c, br.name, "on_home", e.target.checked)} /> Home</label>
                                </div>
                                <button type="button" onClick={() => toggleSubbrandList(c, br.name)} style={{ ...linkBtn, marginTop: 6 }}>
                                  {openSubbrand === `${c}::${br.name}` ? "Hide sub-brands ▲" : "Sub-brands ▼"}
                                </button>
                                {openSubbrand === `${c}::${br.name}` && (() => {
                                  const sbav = subbrandsAvail[`${c}::${br.name}`];
                                  return sbav === "loading" || !sbav ? <Spinner msg="Loading…" /> : sbav.length === 0 ? (
                                    <div style={{ fontSize: 11.5, color: "#9aa3b2", marginTop: 4 }}>No sub-brands for {br.name} here.</div>
                                  ) : (
                                    <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginTop: 6 }}>
                                      {sbav.map((sb) => {
                                        const on = !!subbrandSel[sbkey(c, br.name, sb.name)];
                                        return (
                                          <label key={sb.name} style={{ display: "flex", alignItems: "center", gap: 5, fontSize: 11.5, border: `1px solid ${on ? "#C8FF3D" : "#eef1f6"}`, borderRadius: 6, padding: "3px 7px", cursor: "pointer" }}>
                                            <input type="checkbox" checked={on} onChange={() => toggleSubbrand(c, br.name, sb.name)} /> {sb.name} <span style={{ color: "#b3bccb" }}>{sb.count}</span>
                                          </label>
                                        );
                                      })}
                                    </div>
                                  );
                                })()}
                              </>)}
                            </div>
                          );
                        })}
                      </div>
                      )}
                      </>
                      );
                    })()}
                  </div>
                )}
              </div>
            );
          })}

          <div style={{ borderTop: "1px solid #eef1f6", marginTop: 14, paddingTop: 12 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: "#1b2230", marginBottom: 4 }}>Custom links</div>
            <div style={{ fontSize: 11, color: "#9aa3b2", marginBottom: 8 }}>Add any links you like to the menu — a sale page, a full external URL, a lookbook. Add as many as you want.</div>
            {links.map((l, i) => (
              <div key={i} style={{ display: "grid", gridTemplateColumns: "1fr 1.6fr auto", gap: 6, marginBottom: 6 }}>
                <input style={inputStyle} value={l.label} onChange={(e) => setLink(i, "label", e.target.value)} placeholder="Label (e.g. Sale)" />
                <input style={inputStyle} value={l.url} onChange={(e) => setLink(i, "url", e.target.value)} placeholder="/c/shoes?sort=discount  or  https://…" />
                <button type="button" onClick={() => removeLink(i)} style={{ border: "none", background: "none", cursor: "pointer", color: "#c4505f", fontSize: 16 }} title="Remove">✕</button>
              </div>
            ))}
            <button type="button" onClick={addLink} style={{ ...linkBtn, marginTop: 2 }}>+ Add link</button>
          </div>

          <div style={{ borderTop: "1px solid #eef1f6", marginTop: 14, paddingTop: 12 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: "#1b2230", marginBottom: 8 }}>Menu style</div>
            <div style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap", marginBottom: 8 }}>
              <label style={ckLbl}>
                <input type="radio" name="navLayout" checked={navLayout === "single"} onChange={() => { setSaved(false); setNavLayout("single"); }} /> Single row
              </label>
              <label style={ckLbl}>
                <input type="radio" name="navLayout" checked={navLayout === "double"} onChange={() => { setSaved(false); setNavLayout("double"); }} /> Double row (logo centred, menu below)
              </label>
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap" }}>
              <label style={ckLbl}><input type="checkbox" checked={showCats} onChange={(e) => { setSaved(false); setShowCats(e.target.checked); }} /> Show categories</label>
              <label style={ckLbl}><input type="checkbox" checked={showBrands} onChange={(e) => { setSaved(false); setShowBrands(e.target.checked); }} /> Show featured brands</label>
            </div>
            <div style={{ fontSize: 11, color: "#9aa3b2", marginTop: 5 }}>Pick either or both. Featured brands are the ones you tick under each category above.</div>
          </div>
          <label style={{ ...ckLbl, marginTop: 12, fontSize: 12.5 }}>
            <input type="checkbox" checked={hideUnmapped} onChange={(e) => { setSaved(false); setHideUnmapped(e.target.checked); }} />
            Hide un-mapped sub-categories from the menu (show only the ones you renamed in your category map)
          </label>
          <div style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 10 }}>
            <Btn tone="lime" onClick={save} disabled={busy}>{busy ? "Saving…" : "Save navigation"}</Btn>
            {saved && <span style={{ fontSize: 12.5, color: "#2e7d32" }}>Saved ✓</span>}
          </div>
        </>
      )}
    </Card>
  );
}

const arrowBtn = (disabled) => ({ border: "none", background: "none", cursor: disabled ? "default" : "pointer", color: disabled ? "#cfd6e0" : "#6b7688", fontSize: 9, lineHeight: "11px", padding: 0 });
const ckLbl = { display: "flex", alignItems: "center", gap: 5, fontSize: 12, color: "#42505f" };
const linkBtn = { background: "none", border: "none", color: "#3b6fd8", fontSize: 12, cursor: "pointer", padding: 0 };

const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : "");

// Best achievable text contrast on a colour (using black OR white text), as a
// WCAG contrast ratio. < 4.5 means even the better of black/white fails AA.
function _rgb(hex) { let h = String(hex || "").replace("#", "").trim(); if (h.length === 3) h = h.split("").map((c) => c + c).join(""); if (!/^[0-9a-fA-F]{6}$/.test(h)) return null; return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)); }
function bestContrast(hex) {
  const rgb = _rgb(hex); if (!rgb) return 21;
  const [r, g, b] = rgb.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
  const L = 0.2126 * r + 0.7152 * g + 0.0722 * b;
  return Math.max(1.05 / (L + 0.05), (L + 0.05) / 0.05); // vs white, vs black
}

function CustomDomainPanel({ site, onChanged }) {
  const [domain, setDomain] = useState(site.custom_domain || "");
  const [busy, setBusy] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [msg, setMsg] = useState(null);
  const [verify, setVerify] = useState(null); // { txt_name, txt_value, ... } from the save response
  const [error, setError] = useState(null);
  const verified = !!site.custom_domain_verified_at;

  async function save() {
    setBusy(true); setError(null); setMsg(null); setVerify(null);
    try {
      const r = await api.setHostedSiteCustomDomain(site.id, domain.trim());
      setVerify(r.verify || null);
      setMsg(domain.trim() ? "Domain saved. Add ONE of the records below, then click Verify." : "Domain cleared.");
      onChanged();
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  }

  async function verifyNow() {
    setVerifying(true); setError(null); setMsg(null);
    try {
      const r = await api.verifyHostedSiteDomain(site.id);
      setMsg("✓ Verified — " + (r.note || "your domain is live."));
      onChanged();
    } catch (e) { setError(e); }
    finally { setVerifying(false); }
  }

  return (
    <Card>
      <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 4 }}>Custom domain</div>
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 12 }}>
        Point your own domain (like <code style={{ background: "#f5f6f9", padding: "1px 6px", borderRadius: 4 }}>yourbrand.com</code>) at your storefront instead of the platform subdomain.
      </div>
      <ErrorNote error={error} />
      <div style={{ display: "flex", gap: 8, alignItems: "flex-start", flexWrap: "wrap" }}>
        <input
          style={{ ...inputStyle, flex: 1, minWidth: 240 }}
          placeholder="yourbrand.com"
          value={domain}
          onChange={(e) => setDomain(e.target.value)}
        />
        <Btn tone="lime" disabled={busy} onClick={save}>{busy ? "Saving…" : "Save domain"}</Btn>
      </div>
      {msg && <div style={{ fontSize: 12.5, color: "#2e7d32", marginTop: 8 }}>{msg}</div>}
      {site.custom_domain && (
        <div style={{ marginTop: 12, padding: "12px 14px", background: verified ? "#e6f5eb" : "#fff7e6", border: `1px solid ${verified ? "#c8e6d0" : "#f0d9a8"}`, borderRadius: 8, fontSize: 12.5 }}>
          {verified ? (
            <span style={{ color: "#2e7d32" }}>✓ <strong>{site.custom_domain}</strong> is verified — shoppers reach your storefront through it.</span>
          ) : (
            <div style={{ color: "#8a6d2f" }}>
              <div style={{ marginBottom: 8 }}>⏳ <strong>{site.custom_domain}</strong> is not verified yet.</div>
              <div style={{ fontSize: 12, color: "#42505f", marginBottom: 8 }}>
                <strong>Step 1 — prove you own the domain.</strong> Add EITHER of these at your DNS/registrar:
                {verify ? (
                  <div style={{ marginTop: 6 }}>
                    <div style={{ marginBottom: 4 }}>• <strong>TXT record</strong> — name <code style={mono}>{verify.txt_name}</code>, value <code style={mono}>{verify.txt_value}</code></div>
                    <div>• <strong>or a file</strong> at <code style={mono}>{verify.wellknown_url}</code> containing <code style={mono}>{verify.wellknown_value}</code></div>
                  </div>
                ) : (
                  <div style={{ marginTop: 4, color: "#6b7688" }}>Re-save the domain above to (re)issue the verification token.</div>
                )}
              </div>
              <div style={{ fontSize: 12, color: "#42505f", marginBottom: 10 }}>
                <strong>Step 2 — point the domain at us.</strong> Add a <code style={mono}>CNAME</code> for <code>@</code>/<code>www</code> to the platform host you were given.
              </div>
              <Btn small tone="lime" disabled={verifying} onClick={verifyNow}>{verifying ? "Checking…" : "Verify now"}</Btn>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

const mono = { background: "#f5f6f9", padding: "1px 6px", borderRadius: 4, fontFamily: "monospace", wordBreak: "break-all" };

function HomepagePresetPanel({ siteId }) {
  const [presets, setPresets] = useState(null);
  const [applying, setApplying] = useState(null);
  const [applied, setApplied] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    // load the presets AND which one this site currently has applied, so the
    // selection survives a page refresh (was resetting to none before).
    Promise.all([api.hostedSitePresets(), api.hostedSiteSettings(siteId)])
      .then(([pr, s]) => { setPresets(pr.presets || []); setApplied(s.settings?.preset || null); })
      .catch(setError);
  }, [siteId]);

  async function apply(id) {
    setApplying(id); setError(null);
    try {
      // component templates are hand-built pages selected by preset id (no section
      // list); the rest are section-preset layouts built server-side.
      if (COMPONENT_TEMPLATES.some((t) => t.id === id)) await api.saveHostedSiteSettings(siteId, { sections: [], preset: id });
      else await api.applyHostedSitePreset(siteId, id);
      setApplied(id);
    }
    catch (e) { setError(e); }
    finally { setApplying(null); }
  }

  // Hand-built full-page templates (rendered by their own component, not the
  // section builder). "original" is the classic multi-category home; "velocity"
  // is the shoe-first athletic template.
  const COMPONENT_TEMPLATES = [
    { id: "original", name: "Original (multi-category)", description: "Best-sellers rail per category + a mixed All-products rail. Shows every category you sell. Recommended.", section_count: "auto" },
    { id: "velocity", name: "Velocity (athletic / shoes)", description: "High-energy neon + crimson, floating shoe shots, men's/women's selector, tech breakdown. Built shoes-first — best for footwear stores.", section_count: "auto" },
    { id: "chrono", name: "Chrono (luxe / watches)", description: "Same bold layout as Velocity in a gold + deep-blue palette, built watches-first — floating watch shots, movement/crystal tech breakdown. Best for watch stores.", section_count: "auto" },
  ];

  return (
    <Card>
      <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 4 }}>Homepage layout</div>
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 14 }}>
        Pick a ready-made layout for your storefront's home page — hero, category grid, product rails, testimonials.
        Applies immediately. Your branding (name, logo, colours, hero image) fills in automatically.
      </div>
      <ErrorNote error={error} />
      {!presets ? <Spinner msg="Loading presets…" /> : (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(240px, 1fr))", gap: 12 }}>
          {[...COMPONENT_TEMPLATES, ...presets].map((p) => {
            // the original layout is "applied" whenever no preset id is stored
            const isOn = p.id === "original" ? (!applied || applied === "original") : applied === p.id;
            return (
              <div key={p.id} style={{ border: isOn ? "2px solid #C8FF3D" : "1px solid #e6e9f0", borderRadius: 10, padding: 14, background: "#fff" }}>
                <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 4 }}>{p.name}</div>
                <div style={{ fontSize: 12, color: "#6b7688", marginBottom: 10, minHeight: 32 }}>{p.description}</div>
                <div style={{ fontSize: 11, color: "#9aa3b2", marginBottom: 10 }}>{p.section_count === "auto" ? "auto-built" : `${p.section_count} sections`}</div>
                <Btn small tone={isOn ? "lime" : "ghost"} disabled={applying === p.id} onClick={() => apply(p.id)}>
                  {applying === p.id ? "Applying…" : isOn ? "Applied ✓" : "Use this layout"}
                </Btn>
              </div>
            );
          })}
        </div>
      )}
    </Card>
  );
}

function SettingsPanel({ siteId }) {
  const [form, setForm] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api.hostedSiteSettings(siteId).then((r) => setForm(normalize(r.settings || {}))).catch(setError);
  }, [siteId]);

  function normalize(s) {
    // pricing.bands is an array; give the form a stable shape (4 rows) it can bind to
    const bandsFromServer = s.pricing?.bands || [];
    const DEFAULT_BANDS = [
      { min: 0, max: 500, add: 750 },
      { min: 500, max: 4500, add: 1000 },
      { min: 4500, max: 6000, add: 1250 },
      { min: 6000, max: null, add: 1500 },
    ];
    const bands = bandsFromServer.length ? bandsFromServer : DEFAULT_BANDS;
    return {
      store_name: s.store_name || "",
      logo_url: s.logo_url || "",
      favicon_url: s.favicon_url || "",
      whatsapp: s.whatsapp || "",
      email: s.email || "",
      phone: s.phone || "",
      announcement: s.announcement || "",
      about: s.about || "",
      theme: {
        primary: (s.theme && s.theme.primary) || "#0E7A5F",
        secondary: (s.theme && s.theme.secondary) || "#1a1512",
        complementary: (s.theme && s.theme.complementary) || "#C8A24B",
        background: (s.theme && s.theme.background) || "#ffffff",
      },
      address: { line1: "", city: "", state: "", pincode: "", ...(s.address || {}) },
      social_urls: { instagram: "", facebook: "", youtube: "", community: "", ...(s.social_urls || {}) },
      hero: { title: "", subtitle: "", image_url: "", video_url: "", ...(s.hero || {}) },
      policies: { shipping: "", returns: "", privacy: "", terms: "", ...(s.policies || {}) },
      pricing: { bands, using_default: bandsFromServer.length === 0 },
      analytics: { ga4_id: "", meta_pixel_id: "", ...(s.analytics || {}) },
      reviews: Array.isArray(s.reviews) ? s.reviews : [],
    };
  }

  function set(path, value) {
    setSaved(false);
    setForm((f) => {
      const next = { ...f };
      if (path.includes(".")) {
        const [group, key] = path.split(".");
        next[group] = { ...next[group], [key]: value };
      } else {
        next[path] = value;
      }
      return next;
    });
  }

  async function save() {
    setBusy(true); setError(null); setSaved(false);
    try {
      // shape pricing: `{ using_default: true }` = an empty bands array on the
      // server → fall back to the platform default markup. `false` = save the
      // vendor's edited bands.
      const payload = {
        ...form,
        reviews: (form.reviews || []).map((s) => String(s).trim()).filter(Boolean),
        pricing: form.pricing.using_default ? {} : { bands: form.pricing.bands.map((b) => ({
          min: Number(b.min) || 0,
          max: b.max === null || b.max === "" || b.max === undefined ? null : Number(b.max),
          add: Number(b.add) || 0,
        })) },
      };
      await api.saveHostedSiteSettings(siteId, payload); setSaved(true);
    }
    catch (e) { setError(e); }
    finally { setBusy(false); }
  }

  function setBand(i, key, value) {
    setSaved(false);
    setForm((f) => {
      const bands = f.pricing.bands.map((b, j) => j === i ? { ...b, [key]: value } : b);
      return { ...f, pricing: { ...f.pricing, bands, using_default: false } };
    });
  }

  if (error) return <Card><ErrorNote error={error} /></Card>;
  if (!form) return <Card><Spinner msg="Loading branding…" /></Card>;

  return (
    <Card>
      <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 4 }}>Branding</div>
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 16 }}>
        Shown everywhere on your storefront — the logo, name, colours, contact details and checkout number are yours alone.
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
        <Field label="Store name"><input style={inputStyle} value={form.store_name} onChange={(e) => set("store_name", e.target.value)} /></Field>
        <Field label="Logo URL"><input style={inputStyle} value={form.logo_url} onChange={(e) => set("logo_url", e.target.value)} placeholder="https://…" /></Field>
        <Field label="Favicon URL (browser-tab icon)"><input style={inputStyle} value={form.favicon_url} onChange={(e) => set("favicon_url", e.target.value)} placeholder="https://…/favicon.png (falls back to your logo)" /></Field>
        <Field label="WhatsApp number (checkout)"><input style={inputStyle} value={form.whatsapp} onChange={(e) => set("whatsapp", e.target.value)} placeholder="+91 98765 43210" /></Field>
        <Field label="Contact email"><input style={inputStyle} value={form.email} onChange={(e) => set("email", e.target.value)} /></Field>
        <Field label="Contact phone"><input style={inputStyle} value={form.phone} onChange={(e) => set("phone", e.target.value)} /></Field>
      </div>

      <div style={{ fontWeight: 700, fontSize: 13, margin: "18px 0 6px" }}>Colour palette</div>
      <div style={{ fontSize: 12, color: "#6b7688", marginBottom: 10 }}>
        Four brand colours. Text on any coloured element is auto-set to black or white for readability.
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr 1fr", gap: 12 }}>
        {[["primary", "Primary"], ["secondary", "Secondary"], ["complementary", "Complementary"], ["background", "Background"]].map(([k, label]) => {
          const lowContrast = bestContrast(form.theme[k]) < 4.5; // even black/white text can't hit WCAG AA
          return (
            <Field key={k} label={label}>
              <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                <input type="color" style={{ width: 40, height: 38, padding: 2, border: "1px solid #d4d9e3", borderRadius: 6, cursor: "pointer" }} value={form.theme[k]} onChange={(e) => set(`theme.${k}`, e.target.value)} />
                <input style={{ ...inputStyle, flex: 1 }} value={form.theme[k]} onChange={(e) => set(`theme.${k}`, e.target.value)} />
              </div>
              {lowContrast && (
                <div style={{ fontSize: 11, color: "#b26a00", marginTop: 4, lineHeight: 1.3 }}>
                  ⚠ Text may be hard to read on this colour — pick a darker or lighter shade.
                </div>
              )}
            </Field>
          );
        })}
      </div>

      <div style={{ fontWeight: 700, fontSize: 13, margin: "18px 0 10px" }}>Address</div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
        <Field label="Address line"><input style={inputStyle} value={form.address.line1} onChange={(e) => set("address.line1", e.target.value)} /></Field>
        <Field label="City"><input style={inputStyle} value={form.address.city} onChange={(e) => set("address.city", e.target.value)} /></Field>
        <Field label="State"><input style={inputStyle} value={form.address.state} onChange={(e) => set("address.state", e.target.value)} /></Field>
        <Field label="Pincode"><input style={inputStyle} value={form.address.pincode} onChange={(e) => set("address.pincode", e.target.value)} /></Field>
      </div>

      <div style={{ fontWeight: 700, fontSize: 13, margin: "18px 0 4px" }}>Social links</div>
      <div style={{ fontSize: 12, color: "#6b7688", marginBottom: 10 }}>Leave any blank — only the ones you fill show on your storefront.</div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
        <Field label="Instagram URL"><input style={inputStyle} value={form.social_urls.instagram} onChange={(e) => set("social_urls.instagram", e.target.value)} placeholder="https://instagram.com/…" /></Field>
        <Field label="Facebook URL"><input style={inputStyle} value={form.social_urls.facebook} onChange={(e) => set("social_urls.facebook", e.target.value)} placeholder="https://facebook.com/…" /></Field>
        <Field label="YouTube URL"><input style={inputStyle} value={form.social_urls.youtube} onChange={(e) => set("social_urls.youtube", e.target.value)} placeholder="https://youtube.com/…" /></Field>
        <Field label="WhatsApp community / group link"><input style={inputStyle} value={form.social_urls.community} onChange={(e) => set("social_urls.community", e.target.value)} placeholder="https://chat.whatsapp.com/…" /></Field>
      </div>

      <div style={{ fontWeight: 700, fontSize: 13, margin: "18px 0 10px" }}>Homepage</div>
      <Field label="Announcement bar (leave blank to hide)">
        <input style={inputStyle} value={form.announcement} onChange={(e) => set("announcement", e.target.value)} placeholder="Free shipping this week!" />
      </Field>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
        <Field label="Hero title"><input style={inputStyle} value={form.hero.title} onChange={(e) => set("hero.title", e.target.value)} /></Field>
        <Field label="Hero image URL"><input style={inputStyle} value={form.hero.image_url} onChange={(e) => set("hero.image_url", e.target.value)} placeholder="https://…" /></Field>
      </div>
      <Field label="Hero video URL — .mp4/.webm or a YouTube / Vimeo link (autoplays muted, looped; takes priority over the image)">
        <input style={inputStyle} value={form.hero.video_url} onChange={(e) => set("hero.video_url", e.target.value)} placeholder="https://youtube.com/watch?v=… or https://…/banner.mp4" />
      </Field>
      <Field label="Hero subtitle"><input style={inputStyle} value={form.hero.subtitle} onChange={(e) => set("hero.subtitle", e.target.value)} /></Field>
      <Field label="About"><textarea style={{ ...inputStyle, minHeight: 70, resize: "vertical" }} value={form.about} onChange={(e) => set("about", e.target.value)} /></Field>

      <Field label="Customer review images (one image URL per line — shown in an auto-sliding strip on your home page)">
        <textarea
          style={{ ...inputStyle, minHeight: 84, resize: "vertical" }}
          value={(form.reviews || []).join("\n")}
          onChange={(e) => set("reviews", e.target.value.split("\n"))}
          placeholder={"https://…/review1.jpg\nhttps://…/review2.jpg"}
        />
      </Field>

      <div style={{ fontWeight: 700, fontSize: 13, margin: "18px 0 10px" }}>Policies</div>
      <Field label="Shipping policy"><textarea style={{ ...inputStyle, minHeight: 60, resize: "vertical" }} value={form.policies.shipping} onChange={(e) => set("policies.shipping", e.target.value)} /></Field>
      <Field label="Returns policy"><textarea style={{ ...inputStyle, minHeight: 60, resize: "vertical" }} value={form.policies.returns} onChange={(e) => set("policies.returns", e.target.value)} /></Field>
      <Field label="Privacy policy"><textarea style={{ ...inputStyle, minHeight: 60, resize: "vertical" }} value={form.policies.privacy} onChange={(e) => set("policies.privacy", e.target.value)} /></Field>
      <Field label="Terms of service"><textarea style={{ ...inputStyle, minHeight: 60, resize: "vertical" }} value={form.policies.terms} onChange={(e) => set("policies.terms", e.target.value)} /></Field>

      <div style={{ fontWeight: 700, fontSize: 13, margin: "18px 0 6px" }}>Pricing markup</div>
      <div style={{ fontSize: 12, color: "#6b7688", marginBottom: 10 }}>
        The scraped cost price is marked up by a flat amount per price band. Blank max = no upper limit.
        {form.pricing.using_default && (
          <span style={{ color: "#8a6d2f" }}> Currently using the platform default — edit any row to override.</span>
        )}
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8, marginBottom: 4, fontSize: 11, color: "#9aa3b2", textTransform: "uppercase", letterSpacing: 0.4 }}>
        <div>Min (₹)</div><div>Max (₹, blank = ∞)</div><div>Markup (₹)</div>
      </div>
      {form.pricing.bands.map((b, i) => (
        <div key={i} style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8, marginBottom: 6 }}>
          <input style={inputStyle} type="number" value={b.min ?? ""} onChange={(e) => setBand(i, "min", e.target.value)} />
          <input style={inputStyle} type="number" value={b.max ?? ""} onChange={(e) => setBand(i, "max", e.target.value === "" ? null : e.target.value)} placeholder="∞" />
          <input style={inputStyle} type="number" value={b.add ?? ""} onChange={(e) => setBand(i, "add", e.target.value)} />
        </div>
      ))}
      <div style={{ marginTop: 4, marginBottom: 4 }}>
        <button type="button" onClick={() => { setSaved(false); setForm((f) => ({ ...f, pricing: { ...f.pricing, using_default: true } })); }} style={{ background: "none", border: "none", color: "#6b7688", fontSize: 12, textDecoration: "underline", cursor: "pointer", padding: 0 }}>
          Reset to platform default
        </button>
      </div>

      <div style={{ fontWeight: 700, fontSize: 13, margin: "18px 0 10px" }}>Analytics pixels</div>
      <div style={{ fontSize: 12, color: "#6b7688", marginBottom: 10 }}>
        Injected into every storefront page for logged-out and logged-in visitors. Leave blank to skip.
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
        <Field label="Google Analytics 4 ID"><input style={inputStyle} value={form.analytics.ga4_id} onChange={(e) => set("analytics.ga4_id", e.target.value)} placeholder="G-XXXXXXXXXX" /></Field>
        <Field label="Meta Pixel ID"><input style={inputStyle} value={form.analytics.meta_pixel_id} onChange={(e) => set("analytics.meta_pixel_id", e.target.value)} placeholder="1234567890" /></Field>
      </div>

      <ErrorNote error={error} />
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 6 }}>
        <Btn tone="lime" onClick={save} disabled={busy}>{busy ? "Saving…" : "Save branding"}</Btn>
        {saved && <span style={{ fontSize: 12.5, color: "#2e7d32" }}>Saved ✓ — live on your storefront now</span>}
      </div>
    </Card>
  );
}

function OrdersPanel({ siteId }) {
  const [orders, setOrders] = useState(null);
  const [error, setError] = useState(null);
  const [statusFilter, setStatusFilter] = useState("");
  const [openId, setOpenId] = useState(null);
  const [detail, setDetail] = useState({}); // orderId -> { order, items }

  function load() {
    setError(null);
    api.hostedSiteOrders(siteId, statusFilter || undefined)
      .then((r) => setOrders(r.orders || []))
      .catch(setError);
  }
  useEffect(load, [siteId, statusFilter]); // eslint-disable-line react-hooks/exhaustive-deps

  async function toggleOpen(id) {
    if (openId === id) { setOpenId(null); return; }
    setOpenId(id);
    if (!detail[id]) {
      try { const r = await api.hostedSiteOrder(siteId, id); setDetail((d) => ({ ...d, [id]: r })); }
      catch (e) { setError(e); }
    }
  }

  async function changeStatus(id, status) {
    try { await api.updateHostedSiteOrderStatus(siteId, id, status); load(); }
    catch (e) { alert(e.message); }
  }

  return (
    <Card>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 14 }}>
        <div style={{ fontWeight: 700, fontSize: 15 }}>Orders</div>
        <div style={{ display: "flex", gap: 6 }}>
          {["", ...ORDER_STATUSES].map((s) => (
            <button key={s || "all"} onClick={() => setStatusFilter(s)}
              style={{ border: statusFilter === s ? `1px solid ${C.ink}` : "1px solid #d4d9e3", background: statusFilter === s ? C.ink : "#fff", color: statusFilter === s ? "#fff" : "#42505f", padding: "4px 10px", borderRadius: 999, fontSize: 11.5, cursor: "pointer", textTransform: "capitalize" }}>
              {s || "all"}
            </button>
          ))}
        </div>
      </div>
      <ErrorNote error={error} />

      {!orders ? <Spinner /> : orders.length === 0 ? <Empty msg="No orders yet." /> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          {orders.map((o) => (
            <div key={o.id} style={{ border: "1px solid #eef1f6", borderRadius: 10, overflow: "hidden" }}>
              <div onClick={() => toggleOpen(o.id)} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "11px 14px", cursor: "pointer" }}>
                <div>
                  <div style={{ fontWeight: 700, fontSize: 13.5 }}>{o.order_no} <span style={{ fontWeight: 500, color: "#9aa3b2" }}>· {o.buyer_name}</span></div>
                  <div style={{ fontSize: 12, color: "#6b7688", marginTop: 2 }}>₹{Number(o.total).toLocaleString("en-IN")} · {fmtDate(o.created_at)}</div>
                </div>
                <Badge status={o.status} />
              </div>
              {openId === o.id && (
                <div style={{ padding: "0 14px 14px", borderTop: "1px solid #eef1f6" }}>
                  {!detail[o.id] ? <Spinner msg="Loading…" /> : (
                    <>
                      <div style={{ margin: "12px 0", display: "flex", flexDirection: "column", gap: 6 }}>
                        {detail[o.id].items.map((it) => (
                          <div key={it.id} style={{ display: "flex", justifyContent: "space-between", fontSize: 12.5, color: "#42505f" }}>
                            <span>
                              {it.page_url
                                ? <a href={it.page_url} target="_blank" rel="noreferrer" style={{ color: "#3b6fd8", textDecoration: "none" }}>{it.product_name}</a>
                                : it.product_name}
                              {it.size ? ` (Size ${it.size})` : ""} × {it.qty}
                            </span>
                            <span>₹{Number(it.line_total).toLocaleString("en-IN")}</span>
                          </div>
                        ))}
                      </div>
                      <div style={{ fontSize: 12, color: "#6b7688", marginBottom: 12 }}>
                        📍 {[o.address.line1, o.address.city, o.address.state, o.address.pincode].filter(Boolean).join(", ")} · 📞 {o.buyer_phone}
                      </div>
                      <Field label="Status">
                        <select style={{ ...inputStyle, maxWidth: 200 }} value={o.status} onChange={(e) => changeStatus(o.id, e.target.value)}>
                          {ORDER_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                      </Field>
                    </>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}
