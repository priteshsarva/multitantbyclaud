import React, { useEffect, useState } from "react";
import { Search, ExternalLink } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Spinner, ErrorNote, Empty, inputStyle } from "../ui.jsx";

const inr = (n) => "₹" + Number(n || 0).toLocaleString("en-IN");

// Browse the full scraped catalogue across every source site. Shows the front
// image, original (supplier) price, attributes, and the source — click the
// source to open the actual product page in a new tab.
const SORTS = [["newest", "Newest"], ["price_asc", "Price: low to high"], ["price_desc", "Price: high to low"], ["name", "Name A–Z"]];
const lbl = { fontSize: 11, color: "#6b7688", marginBottom: 5 };

export default function CatalogueSearch() {
  const [q, setQ] = useState("");
  const [category, setCategory] = useState("");
  const [stock, setStock] = useState("");     // "" all | in | out
  const [sort, setSort] = useState("newest");
  const [size, setSize] = useState("");
  const [brand, setBrand] = useState("");
  const [source, setSource] = useState("");
  const [priceMin, setPriceMin] = useState("");
  const [priceMax, setPriceMax] = useState("");
  const [sources, setSources] = useState([]);
  const [items, setItems] = useState([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => { api.sources().then((r) => setSources(r.sources || [])).catch(() => {}); }, []);

  function params() {
    return {
      ...(q && { q }), ...(category && { category }), ...(stock && { stock }),
      ...(sort && sort !== "newest" && { sort }), ...(size && { size }),
      ...(brand && { brand }), ...(source && { source }),
      ...(priceMin && { price_min: priceMin }), ...(priceMax && { price_max: priceMax }),
    };
  }
  function load(reset) {
    const p = reset ? 1 : page + 1;
    setLoading(true); setError(null);
    api.catalogue({ ...params(), page: p, limit: 24 })
      .then((r) => {
        setItems((prev) => (reset ? r.results : [...prev, ...r.results]));
        setHasMore(r.hasMore); setPage(p);
      })
      .catch(setError)
      .finally(() => setLoading(false));
  }
  // debounce: reload from page 1 whenever any filter changes
  useEffect(() => { const t = setTimeout(() => load(true), 300); return () => clearTimeout(t); /* eslint-disable-next-line */ }, [q, category, stock, sort, size, brand, source, priceMin, priceMax]);

  function clearAll() { setQ(""); setCategory(""); setStock(""); setSort("newest"); setSize(""); setBrand(""); setSource(""); setPriceMin(""); setPriceMax(""); }
  const activeFilters = [category, stock, size, brand, source, priceMin, priceMax].filter(Boolean).length + (sort !== "newest" ? 1 : 0);

  return (
    <div>
      <PageHead title="Catalogue search" sub="Every product across all our source sites — research what you can sell." />

      <Card style={{ marginBottom: 16 }}>
        <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "flex-end" }}>
          <div style={{ position: "relative", flex: 1, minWidth: 220 }}>
            <div style={lbl}>Search</div>
            <Search size={15} style={{ position: "absolute", left: 11, top: 30, color: "#9aa3b2" }} />
            <input style={{ ...inputStyle, paddingLeft: 32 }} placeholder="Product name or brand…" value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
          <div>
            <div style={lbl}>Category</div>
            <select style={{ ...inputStyle, minWidth: 130 }} value={category} onChange={(e) => setCategory(e.target.value)}>
              <option value="">All</option><option value="watches">Watches</option><option value="shoes">Shoes</option>
            </select>
          </div>
          <div>
            <div style={lbl}>Stock</div>
            <select style={{ ...inputStyle, minWidth: 120 }} value={stock} onChange={(e) => setStock(e.target.value)}>
              <option value="">All</option><option value="in">In stock</option><option value="out">Out of stock</option>
            </select>
          </div>
          <div>
            <div style={lbl}>Sort</div>
            <select style={{ ...inputStyle, minWidth: 150 }} value={sort} onChange={(e) => setSort(e.target.value)}>
              {SORTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
        </div>
        <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "flex-end", marginTop: 12 }}>
          <div>
            <div style={lbl}>Brand</div>
            <input style={{ ...inputStyle, width: 150 }} placeholder="e.g. Rolex" value={brand} onChange={(e) => setBrand(e.target.value)} />
          </div>
          <div>
            <div style={lbl}>Size</div>
            <input style={{ ...inputStyle, width: 90 }} placeholder="e.g. 44" value={size} onChange={(e) => setSize(e.target.value)} />
          </div>
          <div>
            <div style={lbl}>Source site</div>
            <select style={{ ...inputStyle, minWidth: 180 }} value={source} onChange={(e) => setSource(e.target.value)}>
              <option value="">All sources</option>
              {sources.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.category})</option>)}
            </select>
          </div>
          <div>
            <div style={lbl}>Price (₹)</div>
            <div style={{ display: "flex", gap: 6, alignItems: "center" }}>
              <input style={{ ...inputStyle, width: 80 }} type="number" placeholder="min" value={priceMin} onChange={(e) => setPriceMin(e.target.value)} />
              <span style={{ color: "#9aa3b2" }}>–</span>
              <input style={{ ...inputStyle, width: 80 }} type="number" placeholder="max" value={priceMax} onChange={(e) => setPriceMax(e.target.value)} />
            </div>
          </div>
          {activeFilters > 0 && (
            <button onClick={clearAll} style={{ background: "none", border: "none", color: "#3b6fd8", fontSize: 12.5, cursor: "pointer", padding: "9px 0" }}>
              Clear filters ({activeFilters})
            </button>
          )}
        </div>
      </Card>

      <ErrorNote error={error} />
      {loading && items.length === 0 ? <Spinner /> : items.length === 0 ? <Card><Empty msg="No products match." /></Card> : (
        <>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(200px, 1fr))", gap: 14 }}>
            {items.map((p) => (
              <div key={`${p.category}-${p.productId}`} style={{ border: "1px solid #e6e9f0", borderRadius: 12, overflow: "hidden", background: "#fff", display: "flex", flexDirection: "column" }}>
                <div style={{ aspectRatio: "1/1", background: "#f4f5f8", position: "relative" }}>
                  {p.image ? <img src={p.image} alt={p.name} loading="lazy" style={{ width: "100%", height: "100%", objectFit: "cover" }} /> : <div style={{ display: "flex", alignItems: "center", justifyContent: "center", height: "100%", color: "#c4ccd8", fontSize: 12 }}>No image</div>}
                  {!p.in_stock && <span style={{ position: "absolute", top: 8, left: 8, background: "rgba(0,0,0,0.7)", color: "#fff", fontSize: 10, padding: "2px 7px", borderRadius: 4 }}>Out of stock</span>}
                  <span style={{ position: "absolute", top: 8, right: 8, background: "#fff", color: "#42505f", fontSize: 10, padding: "2px 7px", borderRadius: 4, textTransform: "capitalize" }}>{p.category}</span>
                </div>
                <div style={{ padding: 11, display: "flex", flexDirection: "column", gap: 4, flex: 1 }}>
                  {p.brand && <div style={{ fontSize: 10.5, textTransform: "uppercase", letterSpacing: 0.4, color: "#9aa3b2" }}>{p.brand}</div>}
                  <div style={{ fontSize: 12.5, color: "#1b2230", lineHeight: 1.35, minHeight: 34, display: "-webkit-box", WebkitLineClamp: 2, WebkitBoxOrient: "vertical", overflow: "hidden" }}>{p.name}</div>
                  <div style={{ fontWeight: 700, fontSize: 14, color: "#1b2230" }}>{inr(p.original_price)} <span style={{ fontSize: 10.5, fontWeight: 400, color: "#9aa3b2" }}>original</span></div>
                  {p.catName && <div style={{ fontSize: 11, color: "#6b7688" }}>{p.catName}</div>}
                  {p.sizes.length > 0 && <div style={{ fontSize: 10.5, color: "#6b7688" }}>Sizes: {p.sizes.slice(0, 10).join(", ")}</div>}
                  <a href={p.product_url} target="_blank" rel="noreferrer"
                    style={{ marginTop: "auto", display: "inline-flex", alignItems: "center", gap: 4, fontSize: 11.5, color: "#3b6fd8", textDecoration: "none", paddingTop: 6 }}>
                    {p.source_name} <ExternalLink size={12} />
                  </a>
                </div>
              </div>
            ))}
          </div>
          {hasMore && (
            <div style={{ textAlign: "center", marginTop: 20 }}>
              <button onClick={() => load(false)} disabled={loading} style={{ border: `1px solid ${C.ink}`, background: "#fff", color: C.ink, padding: "9px 20px", borderRadius: 9, cursor: "pointer", fontSize: 13 }}>
                {loading ? "Loading…" : "Load more"}
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
