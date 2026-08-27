import React, { useEffect, useState } from "react";
import { api } from "../api.js";
import { C, PageHead, Card, Badge, Spinner, ErrorNote, Empty, Field, inputStyle, fmtDate } from "../ui.jsx";

const ORDER_STATUSES = ["pending", "confirmed", "shipped", "delivered", "cancelled"];
const inr = (n) => "₹" + Number(n || 0).toLocaleString("en-IN");

// All storefront orders for the logged-in vendor, across every shop, filterable
// by shop + status. Expand a row for line items (each links to its product page).
export default function MyOrders() {
  const [sites, setSites] = useState([]);
  const [orders, setOrders] = useState(null);
  const [error, setError] = useState(null);
  const [site, setSite] = useState("");     // enrollment id filter
  const [status, setStatus] = useState("");
  const [openId, setOpenId] = useState(null);
  const [detail, setDetail] = useState({}); // orderId -> { order, items }

  useEffect(() => { api.myHostedSites().then((r) => setSites(r.sites || [])).catch(() => {}); }, []);

  function load() {
    setOrders(null); setError(null);
    api.hostedOrders({ ...(site && { site }), ...(status && { status }) })
      .then((r) => setOrders(r.orders || []))
      .catch(setError);
  }
  useEffect(load, [site, status]); // eslint-disable-line react-hooks/exhaustive-deps

  async function toggle(o) {
    if (openId === o.id) { setOpenId(null); return; }
    setOpenId(o.id);
    if (!detail[o.id]) {
      try { const r = await api.hostedSiteOrder(o.enrollment_id, o.id); setDetail((d) => ({ ...d, [o.id]: r })); }
      catch (e) { setError(e); }
    }
  }
  async function changeStatus(o, s) {
    try { await api.updateHostedSiteOrderStatus(o.enrollment_id, o.id, s); load(); }
    catch (e) { alert(e.message); }
  }

  const pill = (active) => ({ border: active ? `1px solid ${C.ink}` : "1px solid #d4d9e3", background: active ? C.ink : "#fff", color: active ? "#fff" : "#42505f", padding: "5px 11px", borderRadius: 999, fontSize: 12, cursor: "pointer", textTransform: "capitalize" });

  return (
    <div>
      <PageHead title="Orders" sub="Every storefront order, shop by shop. Click one to see items and update its status." />

      <Card style={{ marginBottom: 14 }}>
        <div style={{ display: "flex", gap: 16, flexWrap: "wrap", alignItems: "flex-end" }}>
          <Field label="Shop">
            <select style={{ ...inputStyle, minWidth: 180 }} value={site} onChange={(e) => setSite(e.target.value)}>
              <option value="">All shops</option>
              {sites.map((s) => <option key={s.id} value={s.id}>{s.store_name || s.slug}</option>)}
            </select>
          </Field>
          <div>
            <div style={{ fontSize: 12, color: "#6b7688", marginBottom: 6 }}>Status</div>
            <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
              {["", ...ORDER_STATUSES].map((s) => (
                <button key={s || "all"} onClick={() => setStatus(s)} style={pill(status === s)}>{s || "all"}</button>
              ))}
            </div>
          </div>
        </div>
      </Card>

      <ErrorNote error={error} />
      {!orders ? <Spinner /> : orders.length === 0 ? <Card><Empty msg="No orders match this filter yet." /></Card> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          {orders.map((o) => (
            <Card key={o.id} style={{ padding: 0, overflow: "hidden" }}>
              <div onClick={() => toggle(o)} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "12px 15px", cursor: "pointer", gap: 12, flexWrap: "wrap" }}>
                <div>
                  <div style={{ fontWeight: 700, fontSize: 13.5 }}>
                    {o.order_no} <span style={{ fontWeight: 500, color: "#9aa3b2" }}>· {o.buyer_name}</span>
                  </div>
                  <div style={{ fontSize: 12, color: "#6b7688", marginTop: 3 }}>
                    <span style={{ color: "#3b6fd8", fontWeight: 600 }}>{o.store_name}</span> · {inr(o.total)} · {fmtDate(o.created_at)}
                  </div>
                </div>
                <Badge status={o.status} />
              </div>
              {openId === o.id && (
                <div style={{ padding: "0 15px 14px", borderTop: "1px solid #eef1f6" }}>
                  {!detail[o.id] ? <Spinner msg="Loading…" /> : (
                    <>
                      <div style={{ margin: "12px 0", display: "flex", flexDirection: "column", gap: 6 }}>
                        {detail[o.id].items.map((it) => (
                          <div key={it.id} style={{ display: "flex", justifyContent: "space-between", fontSize: 12.5, color: "#42505f", gap: 10 }}>
                            <span>
                              {it.page_url
                                ? <a href={it.page_url} target="_blank" rel="noreferrer" style={{ color: "#3b6fd8", textDecoration: "none" }}>{it.product_name}</a>
                                : it.product_name}
                              {it.size ? ` (Size ${it.size})` : ""} × {it.qty}
                            </span>
                            <span>{inr(it.line_total)}</span>
                          </div>
                        ))}
                      </div>
                      <div style={{ fontSize: 12, color: "#6b7688", marginBottom: 12 }}>
                        📍 {[o.address?.line1, o.address?.city, o.address?.state, o.address?.pincode].filter(Boolean).join(", ")} · 📞 {o.buyer_phone}
                      </div>
                      <Field label="Status">
                        <select style={{ ...inputStyle, maxWidth: 200 }} value={o.status} onChange={(e) => changeStatus(o, e.target.value)}>
                          {ORDER_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                      </Field>
                    </>
                  )}
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
