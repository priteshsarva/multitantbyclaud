import React, { useEffect, useState } from "react";
import { ExternalLink, TrendingUp, ShoppingBag, IndianRupee, Store as StoreIcon } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Spinner, ErrorNote, Empty, Badge, storeUrl, fmtDate } from "../ui.jsx";

const inr = (n) => "₹" + Math.round(Number(n) || 0).toLocaleString("en-IN");

export default function Dashboard({ me }) {
  const [data, setData] = useState(null); // { totals, series, sites }
  const [error, setError] = useState(null);

  useEffect(() => { api.hostedAnalytics().then(setData).catch(setError); }, []);

  if (error) return (<div><PageHead title={`Welcome, ${me?.name || me?.email || "there"}`} /><ErrorNote error={error} /></div>);
  if (!data) return (<div><PageHead title="Dashboard" /><Spinner /></div>);

  const t = data.totals || {};
  const sites = data.sites || [];

  return (
    <div>
      <PageHead title={`Welcome, ${me?.name || me?.email || "there"}`} sub="Your storefronts and sales at a glance." />

      {/* KPI tiles */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 12, marginBottom: 18 }}>
        <Kpi icon={<IndianRupee size={16} />} label="Today's sales" value={inr(t.today_sales)} sub={`${t.today_orders || 0} order${t.today_orders == 1 ? "" : "s"} today`} highlight />
        <Kpi icon={<TrendingUp size={16} />} label="Total sales" value={inr(t.sales)} sub={`${inr(t.net_sales)} net of cancelled`} />
        <Kpi icon={<ShoppingBag size={16} />} label="Orders" value={t.orders || 0} sub="all time" />
        <Kpi icon={<StoreIcon size={16} />} label="Storefronts" value={sites.length} sub={`${sites.filter((s) => s.status === "active").length} active`} />
      </div>

      <Card style={{ marginBottom: 18 }}>
        <div style={{ fontWeight: 700, marginBottom: 4, fontSize: 14 }}>Sales — last 30 days</div>
        <SalesChart series={data.series || []} />
      </Card>

      <Card>
        <div style={{ fontWeight: 700, marginBottom: 12, fontSize: 14 }}>Your storefronts</div>
        {sites.length === 0 ? <Empty msg="No storefronts yet — create one under “My storefront”." /> : (
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            {sites.map((s) => (
              <div key={s.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, padding: "11px 13px", border: "1px solid #eef1f6", borderRadius: 10, flexWrap: "wrap" }}>
                <div>
                  <div style={{ fontWeight: 700, fontSize: 13.5 }}>{s.store_name} <Badge status={s.status} /></div>
                  <div style={{ fontSize: 12, color: "#6b7688", marginTop: 3 }}>{s.order_count} orders · {inr(s.sales)} sales</div>
                </div>
                <a href={storeUrl(s.slug)} target="_blank" rel="noreferrer"
                  style={{ display: "inline-flex", alignItems: "center", gap: 5, fontSize: 12.5, color: "#3b6fd8", textDecoration: "none", border: "1px solid #d4d9e3", borderRadius: 8, padding: "6px 11px" }}>
                  Open storefront <ExternalLink size={13} />
                </a>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}

function Kpi({ icon, label, value, sub, highlight }) {
  return (
    <div style={{ background: highlight ? C.ink : "#fff", color: highlight ? C.text : "#1b2230", border: highlight ? "none" : "1px solid #e6e9f0", borderRadius: 12, padding: 16 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 12, color: highlight ? C.dim : "#6b7688" }}>{icon} {label}</div>
      <div style={{ fontSize: 26, fontWeight: 800, marginTop: 6, color: highlight ? C.lime : "#1b2230" }}>{value}</div>
      {sub && <div style={{ fontSize: 11.5, color: highlight ? C.dim : "#9aa3b2", marginTop: 2 }}>{sub}</div>}
    </div>
  );
}

// Lazy inline bar chart — no charting dependency. Fills 30 day-slots so the axis
// is stable even with sparse orders.
function SalesChart({ series }) {
  const days = 30;
  const today = new Date();
  const byDate = Object.fromEntries(series.map((r) => [String(r.d).slice(0, 10), Number(r.sales)]));
  const bars = [];
  for (let i = days - 1; i >= 0; i--) {
    const dt = new Date(today); dt.setDate(today.getDate() - i);
    const key = dt.toISOString().slice(0, 10);
    bars.push({ key, sales: byDate[key] || 0, label: dt.getDate() });
  }
  const max = Math.max(1, ...bars.map((b) => b.sales));
  if (!series.length) return <div style={{ fontSize: 12.5, color: "#9aa3b2", padding: "20px 0" }}>No sales yet — your chart fills in as orders come in.</div>;
  return (
    <div style={{ display: "flex", alignItems: "flex-end", gap: 3, height: 140, marginTop: 10 }}>
      {bars.map((b) => (
        <div key={b.key} title={`${b.key}: ${inr(b.sales)}`} style={{ flex: 1, display: "flex", flexDirection: "column", justifyContent: "flex-end", alignItems: "center", height: "100%" }}>
          <div style={{ width: "100%", height: `${(b.sales / max) * 100}%`, minHeight: b.sales ? 3 : 0, background: b.sales ? C.lime : "transparent", borderRadius: "3px 3px 0 0" }} />
        </div>
      ))}
    </div>
  );
}
