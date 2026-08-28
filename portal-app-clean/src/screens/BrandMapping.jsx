import React, { useEffect, useState } from "react";
import { Search, ArrowRight, Trash2 } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Spinner, ErrorNote, Empty, inputStyle, Btn } from "../ui.jsx";

// Super-admin GLOBAL brand mapping. Search the raw scraped brands, map any to a
// clean canonical name; the mapping applies across every storefront.
export default function BrandMapping() {
  const [mappings, setMappings] = useState(null);
  const [brands, setBrands] = useState(null);
  const [q, setQ] = useState("");
  const [drafts, setDrafts] = useState({}); // raw -> canonical input
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);

  function loadMappings() { api.adminBrandMap().then((r) => setMappings(r.mappings || [])).catch(setError); }
  useEffect(() => { loadMappings(); }, []);
  useEffect(() => {
    setBrands(null);
    const t = setTimeout(() => api.adminBrands(q).then((r) => setBrands(r.brands || [])).catch(() => setBrands([])), 300);
    return () => clearTimeout(t);
  }, [q]);

  const canonOf = (raw) => (mappings || []).find((m) => m.raw === raw.toLowerCase())?.canonical || "";

  async function save(raw) {
    const canonical = (drafts[raw] ?? canonOf(raw)).trim();
    if (!canonical) return;
    setBusy(raw); setError(null);
    try { await api.adminSaveBrandMap(raw, canonical); await loadMappings(); setDrafts((d) => { const n = { ...d }; delete n[raw]; return n; }); }
    catch (e) { setError(e); }
    finally { setBusy(null); }
  }
  async function remove(raw) {
    setBusy(raw);
    try { await api.adminDeleteBrandMap(raw); await loadMappings(); } catch (e) { setError(e); } finally { setBusy(null); }
  }

  return (
    <div>
      <PageHead title="Brand mapping" sub="Global — maps raw scraped brand names to clean ones across every storefront." />
      <ErrorNote error={error} />

      <div style={{ display: "grid", gridTemplateColumns: "1.4fr 1fr", gap: 18, alignItems: "start" }}>
        {/* raw brands to map */}
        <Card>
          <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 10 }}>Scraped brands</div>
          <div style={{ position: "relative", marginBottom: 12 }}>
            <Search size={15} style={{ position: "absolute", left: 11, top: 10, color: "#9aa3b2" }} />
            <input style={{ ...inputStyle, paddingLeft: 32 }} placeholder="Search brands…" value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
          {!brands ? <Spinner /> : brands.length === 0 ? <Empty msg="No brands match." /> : (
            <div style={{ maxHeight: 460, overflowY: "auto", display: "flex", flexDirection: "column", gap: 6 }}>
              {brands.map((b) => {
                const mapped = canonOf(b.name);
                return (
                  <div key={b.name} style={{ display: "grid", gridTemplateColumns: "1fr auto 1fr auto", gap: 8, alignItems: "center", border: "1px solid #eef1f6", borderRadius: 8, padding: "7px 10px" }}>
                    <div style={{ fontSize: 12.5, minWidth: 0 }}>
                      <div style={{ color: "#1b2230", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{b.name}</div>
                      <div style={{ fontSize: 10.5, color: "#b3bccb" }}>{b.count}</div>
                    </div>
                    <ArrowRight size={13} color="#c4ccd8" />
                    <input style={{ ...inputStyle, padding: "6px 8px", fontSize: 12.5 }} placeholder={mapped || "canonical…"}
                      value={drafts[b.name] ?? mapped} onChange={(e) => setDrafts((d) => ({ ...d, [b.name]: e.target.value }))} />
                    <Btn small tone="lime" disabled={busy === b.name} onClick={() => save(b.name)}>{mapped ? "Update" : "Map"}</Btn>
                  </div>
                );
              })}
            </div>
          )}
        </Card>

        {/* existing mappings */}
        <Card>
          <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 10 }}>Current mappings {mappings ? `(${mappings.length})` : ""}</div>
          {!mappings ? <Spinner /> : mappings.length === 0 ? <Empty msg="No brand mappings yet." /> : (
            <div style={{ display: "flex", flexDirection: "column", gap: 6, maxHeight: 500, overflowY: "auto" }}>
              {mappings.map((m) => (
                <div key={m.raw} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, border: "1px solid #eef1f6", borderRadius: 8, padding: "7px 10px", fontSize: 12.5 }}>
                  <div style={{ minWidth: 0 }}>
                    <span style={{ color: "#9aa3b2" }}>{m.raw}</span> <ArrowRight size={11} style={{ verticalAlign: "-1px" }} color="#c4ccd8" /> <strong style={{ color: "#1b2230" }}>{m.canonical}</strong>
                  </div>
                  <button onClick={() => remove(m.raw)} disabled={busy === m.raw} style={{ border: "none", background: "none", cursor: "pointer", color: "#c4505f" }}><Trash2 size={14} /></button>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
