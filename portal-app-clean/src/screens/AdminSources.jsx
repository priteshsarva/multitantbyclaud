import React, { useEffect, useMemo, useState } from "react";
import { Search, RefreshCw } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Btn, Badge, Spinner, ErrorNote, Empty, Modal, inputStyle } from "../ui.jsx";

export default function AdminSources() {
  const [sources, setSources] = useState(null);
  const [error, setError] = useState(null);
  const [q, setQ] = useState("");
  const [cat, setCat] = useState("all");
  const [active, setActive] = useState(null); // source being inspected
  const [refreshingAll, setRefreshingAll] = useState(false);

  useEffect(() => {
    api.adminSources().then((r) => setSources(r.sources || [])).catch(setError);
  }, []);

  const filtered = useMemo(() => {
    if (!sources) return [];
    return sources.filter((s) =>
      (cat === "all" || s.category === cat) &&
      (!q || s.id.toLowerCase().includes(q.toLowerCase()) || (s.name || "").toLowerCase().includes(q.toLowerCase()) || (s.base_url || "").toLowerCase().includes(q.toLowerCase()))
    );
  }, [sources, q, cat]);

  // categories are free-form now — build the filter from what actually exists
  const cats = useMemo(
    () => [...new Set((sources || []).map((s) => s.category).filter(Boolean))].sort(),
    [sources]
  );

  // Pause = the rotator stops scraping it AND it disappears from the client
  // pickers (the /portal/sources route only returns active). Products already
  // synced to stores stay untouched. Activate reverses it.
  async function setStatus(s, status) {
    if (status === "paused" && !window.confirm(`Pause ${s.name || s.id}? It stops being scraped and clients can no longer attach it.`)) return;
    try {
      await api.adminSetSourceStatus(s.id, status);
      setSources((list) => list.map((x) => (x.id === s.id ? { ...x, status } : x)));
    } catch (e) { alert(e.message); }
  }

  async function refreshAll() {
    if (!window.confirm("Re-derive every source's categories (+ in-stock counts) from the stored products? Takes a few seconds.")) return;
    setRefreshingAll(true);
    try { const r = await api.adminRefreshAllCategories(); alert(`Refreshed ${r.categories} categories across all sources.`); }
    catch (e) { alert(e.message); }
    finally { setRefreshingAll(false); }
  }

  return (
    <div>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 12 }}>
        <PageHead title="Sources" sub={sources ? `${sources.length} sources in the registry` : "Loading…"} />
        <Btn tone="ghost" onClick={refreshAll} disabled={refreshingAll}>
          <RefreshCw size={14} style={{ verticalAlign: "-2px" }} /> {refreshingAll ? "Refreshing…" : "Refresh all categories"}
        </Btn>
      </div>
      <ErrorNote error={error} />

      <div style={{ display: "flex", gap: 10, marginBottom: 14 }}>
        <div style={{ position: "relative", flex: 1, maxWidth: 320 }}>
          <Search size={15} style={{ position: "absolute", left: 11, top: 10, color: "#9aa3b2" }} />
          <input style={{ ...inputStyle, paddingLeft: 32 }} placeholder="Search id or name…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <select style={{ ...inputStyle, width: 160 }} value={cat} onChange={(e) => setCat(e.target.value)}>
          <option value="all">All categories</option>
          {cats.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>

      {!sources ? <Spinner /> : (
        <Card style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
            <thead>
              <tr style={{ background: "#f7f8fb", color: "#6b7688", textAlign: "left" }}>
                <th style={th}>Source</th><th style={th}>Category</th><th style={th}>Method</th><th style={th}>Status</th><th style={th}></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((s) => (
                <tr key={s.id} style={{ borderTop: "1px solid #eef1f6" }}>
                  <td style={td}>
                    <div style={{ fontWeight: 600 }}>{s.name}</div>
                    <div style={{ fontSize: 11.5, color: "#9aa3b2" }}>{s.id} · {s.search_key}</div>
                    {s.base_url && (
                      <a href={s.base_url} target="_blank" rel="noopener noreferrer"
                        style={{ fontSize: 11.5, color: C.sky || "#2f7bd6", textDecoration: "none", wordBreak: "break-all" }}>
                        {s.base_url}
                      </a>
                    )}
                  </td>
                  <td style={td}>{s.category}</td>
                  <td style={td}><code style={{ fontSize: 11.5 }}>{s.method}</code></td>
                  <td style={td}><Badge status={s.status} /></td>
                  <td style={{ ...td, textAlign: "right", whiteSpace: "nowrap" }}>
                    <Btn small tone="ghost" onClick={() => setActive(s)}>Categories</Btn>{" "}
                    {s.status === "active"
                      ? <Btn small tone="ghost" onClick={() => setStatus(s, "paused")}>Pause</Btn>
                      : <Btn small tone="lime" onClick={() => setStatus(s, "active")}>Activate</Btn>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {filtered.length === 0 && <Empty msg="No sources match." />}
        </Card>
      )}

      {active && <CategoriesModal source={active} onClose={() => setActive(null)} />}
    </div>
  );
}

function CategoriesModal({ source, onClose }) {
  const [cats, setCats] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  async function load() {
    setError(null);
    try { setCats((await api.adminSourceCategories(source.id)).categories || []); } catch (e) { setError(e); }
  }
  useEffect(() => { load(); }, [source.id]);

  async function toggle(c) {
    try { await api.adminToggleCategory(source.id, c.cat_name, !c.enabled); load(); } catch (e) { alert(e.message); }
  }
  async function rescrape() {
    setBusy(true);
    try { await api.adminRefreshCategories(source.id, "scrape"); await load(); } catch (e) { setError(e); } finally { setBusy(false); }
  }
  async function refreshFromDb() {
    setBusy(true);
    try { await api.adminRefreshCategories(source.id, "db"); await load(); } catch (e) { setError(e); } finally { setBusy(false); }
  }

  return (
    <Modal title={`${source.name} — categories`} onClose={onClose}>
      <ErrorNote error={error} />
      <div style={{ display: "flex", gap: 8, marginBottom: 12, flexWrap: "wrap" }}>
        <Btn small tone="lime" onClick={refreshFromDb} disabled={busy}>
          <RefreshCw size={13} style={{ verticalAlign: "-2px" }} /> {busy ? "Working…" : "Refresh from products"}
        </Btn>
        <Btn small tone="ghost" onClick={rescrape} disabled={busy}>
          <RefreshCw size={13} style={{ verticalAlign: "-2px" }} /> {busy ? "Scraping…" : "Re-scrape from site"}
        </Btn>
      </div>
      {cats && cats.length > 0 && (
        <div style={{ fontSize: 12, color: "#42505f", marginBottom: 10, padding: "8px 11px", background: "#f7f8fb", borderRadius: 8 }}>
          <strong>{cats.reduce((s, c) => s + (c.product_count || 0), 0).toLocaleString("en-IN")}</strong> total products ·{" "}
          <strong>{cats.reduce((s, c) => s + (c.in_stock_count || 0), 0).toLocaleString("en-IN")}</strong> in stock ·{" "}
          {cats.length} categories
        </div>
      )}
      {!cats ? <Spinner /> : cats.length === 0 ? <Empty msg="No categories. Try refresh from products." /> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 7 }}>
          {cats.map((c) => (
            <div key={c.cat_name} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "8px 11px", border: "1px solid #eef1f6", borderRadius: 9 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 9 }}>
                {c.img && <img src={c.img} alt="" style={{ width: 30, height: 30, borderRadius: 6, objectFit: "cover" }} />}
                <div>
                  <div style={{ fontSize: 13, fontWeight: 600, display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
                    {c.cat_name}
                    {c.no_stock && <span style={{ fontSize: 9.5, fontWeight: 700, color: "#b26a00", background: "#fff3e0", padding: "1px 6px", borderRadius: 4 }}>NO STOCK</span>}
                  </div>
                  <div style={{ fontSize: 11.5, color: "#9aa3b2" }}>{c.product_count} products · {c.in_stock_count} in stock</div>
                </div>
              </div>
              <button onClick={() => toggle(c)} style={{ border: "none", background: c.enabled ? C.lime : "#e6e9f0", color: c.enabled ? C.ink : "#6b7688", padding: "4px 10px", borderRadius: 999, fontSize: 11.5, fontWeight: 700, cursor: "pointer" }}>
                {c.enabled ? "Enabled" : "Disabled"}
              </button>
            </div>
          ))}
        </div>
      )}
    </Modal>
  );
}

const th = { padding: "10px 14px", fontWeight: 600, fontSize: 12 };
const td = { padding: "10px 14px" };
