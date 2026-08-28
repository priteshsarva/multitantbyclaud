import React, { useEffect, useMemo, useState } from "react";
import { Search, ArrowRight, Trash2 } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Spinner, ErrorNote, Empty, inputStyle, Btn } from "../ui.jsx";

// Super-admin GLOBAL brand mapping. The left pane lists ALL scraped brands
// (A–Z); map any to a clean PRIMARY brand + optional SECONDARY (sub-)brand —
// e.g. "Rolex Date" → Rolex · Date. Applies across every storefront.
//
// Each row keeps its OWN input state so typing in one of thousands of rows
// doesn't re-render the whole list.
const PoolRow = React.memo(function PoolRow({ name, count, mapping, onSaved, onError }) {
  const [primary, setPrimary] = useState(mapping?.canonical || "");
  const [secondary, setSecondary] = useState(mapping?.secondary || "");
  const [busy, setBusy] = useState(false);

  // re-sync when the saved mapping changes (e.g. after a reload)
  useEffect(() => { setPrimary(mapping?.canonical || ""); setSecondary(mapping?.secondary || ""); }, [mapping?.canonical, mapping?.secondary]);

  async function save() {
    const p = primary.trim();
    if (!p) return;
    setBusy(true);
    try { await api.adminSaveBrandMap(name, p, secondary.trim()); onSaved(); }
    catch (e) { onError(e); }
    finally { setBusy(false); }
  }

  return (
    <div style={{ display: "grid", gridTemplateColumns: "1fr auto 1fr 1fr auto", gap: 7, alignItems: "center", border: "1px solid #eef1f6", borderRadius: 8, padding: "7px 9px" }}>
      <div style={{ fontSize: 12.5, minWidth: 0 }}>
        <div style={{ color: mapping ? "#1b2230" : "#a23a4b", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{name}</div>
        <div style={{ fontSize: 10.5, color: "#b3bccb" }}>{count}{mapping ? "" : " · unmapped"}</div>
      </div>
      <ArrowRight size={13} color="#c4ccd8" />
      <input style={{ ...inputStyle, padding: "6px 8px", fontSize: 12.5 }} placeholder="Primary…" value={primary} onChange={(e) => setPrimary(e.target.value)} />
      <input style={{ ...inputStyle, padding: "6px 8px", fontSize: 12.5 }} placeholder="Sub-brand…" value={secondary} onChange={(e) => setSecondary(e.target.value)} />
      <Btn small tone="lime" disabled={busy} onClick={save}>{mapping ? "Update" : "Map"}</Btn>
    </div>
  );
});

export default function BrandMapping() {
  const [mappings, setMappings] = useState(null);
  const [brands, setBrands] = useState(null);
  const [q, setQ] = useState("");
  const [error, setError] = useState(null);

  function loadMappings() { api.adminBrandMap().then((r) => setMappings(r.mappings || [])).catch(setError); }
  useEffect(() => { loadMappings(); }, []);
  useEffect(() => {
    setBrands(null);
    const t = setTimeout(() => api.adminBrands(q).then((r) => setBrands(r.brands || [])).catch(() => setBrands([])), 300);
    return () => clearTimeout(t);
  }, [q]);

  // fast lookup of a raw brand's current mapping (raws are stored lowercased)
  const byRaw = useMemo(() => {
    const m = new Map();
    for (const row of mappings || []) m.set(row.raw, row);
    return m;
  }, [mappings]);

  const mappedCount = (mappings || []).length;

  return (
    <div>
      <PageHead title="Brand mapping" sub="Global — map raw scraped brands to a primary brand + optional sub-brand, across every storefront." />
      <ErrorNote error={error} />

      <div style={{ display: "grid", gridTemplateColumns: "1.6fr 1fr", gap: 18, alignItems: "start" }}>
        {/* all scraped brands to map */}
        <Card>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 10 }}>
            <div style={{ fontWeight: 700, fontSize: 14 }}>All scraped brands (A–Z)</div>
            {brands && <div style={{ fontSize: 11.5, color: "#9aa3b2" }}>{brands.length} shown · {mappedCount} mapped</div>}
          </div>
          <div style={{ position: "relative", marginBottom: 12 }}>
            <Search size={15} style={{ position: "absolute", left: 11, top: 10, color: "#9aa3b2" }} />
            <input style={{ ...inputStyle, paddingLeft: 32 }} placeholder="Search brands…" value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
          {!brands ? <Spinner /> : brands.length === 0 ? <Empty msg="No brands match." /> : (
            <div style={{ maxHeight: 560, overflowY: "auto", display: "flex", flexDirection: "column", gap: 6 }}>
              {brands.map((b) => (
                <PoolRow key={b.name} name={b.name} count={b.count} mapping={byRaw.get(b.name.toLowerCase())} onSaved={loadMappings} onError={setError} />
              ))}
            </div>
          )}
        </Card>

        {/* existing mappings */}
        <Card>
          <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 10 }}>Current mappings {mappings ? `(${mappings.length})` : ""}</div>
          {!mappings ? <Spinner /> : mappings.length === 0 ? <Empty msg="No brand mappings yet." /> : (
            <div style={{ display: "flex", flexDirection: "column", gap: 6, maxHeight: 620, overflowY: "auto" }}>
              {mappings.map((m) => (
                <div key={m.raw} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, border: "1px solid #eef1f6", borderRadius: 8, padding: "7px 10px", fontSize: 12.5 }}>
                  <div style={{ minWidth: 0 }}>
                    <span style={{ color: "#9aa3b2" }}>{m.raw}</span> <ArrowRight size={11} style={{ verticalAlign: "-1px" }} color="#c4ccd8" />{" "}
                    <strong style={{ color: "#1b2230" }}>{m.canonical}</strong>
                    {m.secondary && <span style={{ color: "#6b7688" }}> · {m.secondary}</span>}
                  </div>
                  <button onClick={() => api.adminDeleteBrandMap(m.raw).then(loadMappings).catch(setError)} style={{ border: "none", background: "none", cursor: "pointer", color: "#c4505f" }}><Trash2 size={14} /></button>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
