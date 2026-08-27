import React, { useEffect, useState } from "react";
import { ExternalLink } from "lucide-react";
import { api } from "../api.js";
import { PageHead, Card, Btn, Badge, Spinner, ErrorNote, Empty, fmtDate, storeUrl } from "../ui.jsx";

export default function AdminHostedSites() {
  const [sites, setSites] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(null); // id currently acting on

  function load() {
    api.adminHostedSites().then((r) => setSites(r.sites || [])).catch(setError);
  }
  useEffect(load, []);

  async function act(id, fn, ...args) {
    setBusy(id);
    try { await fn(...args); load(); }
    catch (e) { alert(e.message); }
    finally { setBusy(null); }
  }

  async function remove(id, name) {
    if (!confirm(`Permanently delete "${name}"? This removes its branding, orders and customers too.`)) return;
    act(id, api.adminDeleteHostedSite, id);
  }

  return (
    <div>
      <PageHead title="Storefronts" sub="Every hosted, multi-tenant storefront across all vendors." />
      <ErrorNote error={error} />
      {!sites ? <Spinner /> : sites.length === 0 ? <Card><Empty msg="No storefronts yet." /></Card> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
          {sites.map((s) => (
            <Card key={s.id}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", flexWrap: "wrap", gap: 10 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
                  {s.logo_url
                    ? <img src={s.logo_url} alt="" style={{ width: 36, height: 36, borderRadius: 8, objectFit: "cover" }} />
                    : <div style={{ width: 36, height: 36, borderRadius: 8, background: "#eef1f6" }} />}
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 14.5 }}>{s.store_name || s.slug}</div>
                    <div style={{ fontSize: 12, color: "#6b7688", marginTop: 2 }}>{s.owner_email}</div>
                  </div>
                </div>
                <div style={{ textAlign: "right" }}>
                  <Badge status={s.status} />
                  <div style={{ fontSize: 12, color: "#6b7688", marginTop: 4 }}>
                    {s.order_count} order{s.order_count === "1" ? "" : "s"} · {s.status === "active" ? `expires ${fmtDate(s.expiry_date)}` : fmtDate(s.created_at)}
                  </div>
                </div>
              </div>

              <div style={{ marginTop: 10, display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
                <a href={storeUrl(s.slug)} target="_blank" rel="noreferrer" style={{ fontSize: 12.5, color: "#3b6fd8", display: "flex", alignItems: "center", gap: 4, textDecoration: "none" }}>
                  {s.slug} <ExternalLink size={12} />
                </a>
                {s.custom_domain && (
                  <div style={{ fontSize: 12.5, display: "flex", alignItems: "center", gap: 6 }}>
                    <a href={`https://${s.custom_domain}`} target="_blank" rel="noreferrer" style={{ color: "#3b6fd8" }}>{s.custom_domain}</a>
                    {s.custom_domain_verified_at
                      ? <span style={{ background: "#e6f5eb", color: "#2e7d32", padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>✓ verified</span>
                      : <span style={{ background: "#fff4e6", color: "#8a6d2f", padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600 }}>unverified</span>}
                  </div>
                )}
              </div>

              <div style={{ marginTop: 12, display: "flex", gap: 7, flexWrap: "wrap" }}>
                {s.status === "pending" && (
                  <>
                    <Btn small tone="lime" disabled={busy === s.id} onClick={() => act(s.id, api.adminApproveEnrollment, s.id)}>Approve</Btn>
                    <Btn small tone="danger" disabled={busy === s.id} onClick={() => act(s.id, api.adminRejectEnrollment, s.id)}>Reject</Btn>
                  </>
                )}
                {s.status === "approved" && (
                  <Btn small tone="lime" disabled={busy === s.id} onClick={() => act(s.id, api.adminActivateEnrollment, s.id)}>Activate</Btn>
                )}
                {s.status === "active" && (
                  <Btn small tone="ghost" disabled={busy === s.id} onClick={() => act(s.id, api.adminUpdateHostedSite, s.id, { status: "paused" })}>Pause</Btn>
                )}
                {s.status === "paused" && (
                  <Btn small tone="lime" disabled={busy === s.id} onClick={() => act(s.id, api.adminUpdateHostedSite, s.id, { status: "active" })}>Resume</Btn>
                )}
                {s.custom_domain && !s.custom_domain_verified_at && (
                  <Btn small tone="ghost" disabled={busy === s.id} onClick={async () => {
                    setBusy(s.id);
                    try { const r = await api.adminVerifyCustomDomain(s.id); alert("Verified: " + (r.note || "OK")); load(); }
                    catch (e) { alert("Verify failed: " + e.message); }
                    finally { setBusy(null); }
                  }}>Verify domain</Btn>
                )}
                <Btn small tone="danger" disabled={busy === s.id} onClick={() => remove(s.id, s.store_name || s.slug)}>Delete</Btn>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
