import React, { useEffect, useState } from "react";
import { Clock, Globe } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Spinner, ErrorNote, Empty, daysUntil, fmtDate, Badge } from "../ui.jsx";

export default function Dashboard({ me }) {
  const [enr, setEnr] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.enrollments().then((r) => setEnr(r.enrollments || [])).catch(setError);
  }, []);

  if (error) return (<div><PageHead title={`Welcome, ${me?.name || me?.email || "there"}`} /><ErrorNote error={error} /></div>);
  if (!enr) return (<div><PageHead title="Dashboard" /><Spinner /></div>);

  // earliest-to-expire active site
  const active = enr.filter((e) => e.expiry_date);
  const soonest = active.slice().sort((a, b) => new Date(a.expiry_date) - new Date(b.expiry_date))[0];
  const d = soonest ? daysUntil(soonest.expiry_date) : null;
  const tone = d == null ? C.dim : d < 0 ? C.coral : d <= 7 ? C.amber : C.lime;

  return (
    <div>
      <PageHead title={`Welcome, ${me?.name || me?.email || "there"}`} sub="Your stores at a glance." />

      <div style={{ background: C.ink, borderRadius: 16, padding: 24, color: C.text, marginBottom: 20 }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8, color: C.dim, fontSize: 13 }}>
          <Clock size={15} /> Earliest site to expire
        </div>
        {soonest ? (
          <div style={{ marginTop: 10, display: "flex", alignItems: "baseline", gap: 14 }}>
            <div style={{ fontSize: 40, fontWeight: 800, color: tone }}>
              {d < 0 ? "Expired" : `${d} day${d === 1 ? "" : "s"}`}
            </div>
            <div style={{ color: C.dim, fontSize: 14 }}>
              {soonest.domain} · expires {fmtDate(soonest.expiry_date)}
            </div>
          </div>
        ) : (
          <div style={{ marginTop: 10, color: C.dim }}>No active sites yet.</div>
        )}
      </div>

      <Card>
        <div style={{ fontWeight: 700, marginBottom: 12, fontSize: 14 }}>Your sites</div>
        {enr.length === 0 ? <Empty msg="No sites enrolled yet." /> : (
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            {enr.map((e) => (
              <div key={e.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "10px 12px", border: "1px solid #eef1f6", borderRadius: 10 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                  <Globe size={16} color="#9aa3b2" />
                  <span style={{ fontWeight: 600, fontSize: 13.5 }}>{e.domain}</span>
                </div>
                <div style={{ display: "flex", alignItems: "center", gap: 14 }}>
                  <span style={{ fontSize: 12.5, color: "#6b7688" }}>expires {fmtDate(e.expiry_date)}</span>
                  <Badge status={e.status} />
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
