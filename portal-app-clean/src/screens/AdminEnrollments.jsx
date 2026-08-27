import React, { useEffect, useState } from "react";
import { api } from "../api.js";
import { PageHead, Card, Badge, Btn, Spinner, ErrorNote, Empty, fmtDate, C } from "../ui.jsx";

function statusTone(s, daysLeft) {
  if (s === "active" && daysLeft !== null && daysLeft <= 7) return "amber";
  return { active: "lime", expired: "coral", pending: "amber", approved: "lime", rejected: "coral" }[s] || "ghost";
}

export default function AdminEnrollments() {
  const [enr, setEnr] = useState(null);
  const [error, setError] = useState(null);
  const [verifying, setVerifying] = useState(null);   // enrollment id being verified
  const [verifyMsg, setVerifyMsg] = useState({});      // id -> message

  function load() {
    api.adminEnrollmentOverview().then((r) => setEnr(r.enrollments || [])).catch(setError);
  }
  useEffect(load, []);

  async function verify(id) {
    setVerifying(id); setError(null);
    try {
      const r = await api.adminVerifyDomain(id);
      setVerifyMsg((m) => ({ ...m, [id]: r.message }));
      load();
    } catch (e) { setVerifyMsg((m) => ({ ...m, [id]: e.message || "Verification failed" })); }
    finally { setVerifying(null); }
  }

  return (
    <div>
      <PageHead title="Enrollments" sub="Every site across all clients — what they're enrolled for, and how long their key is valid." />
      <ErrorNote error={error} />
      {!enr ? <Spinner /> : enr.length === 0 ? <Card><Empty msg="No enrollments yet." /></Card> : (
        <div style={{ display: "grid", gap: 12 }}>
          {enr.map((e) => {
            const days = e.days_left;
            const expLabel =
              e.status === "expired" || (days !== null && days < 0) ? "Expired"
              : days !== null ? `${days} day${days === 1 ? "" : "s"} left`
              : "No expiry";
            return (
              <Card key={e.id}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", flexWrap: "wrap", gap: 10 }}>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 15 }}>{e.domain}</div>
                    <div style={{ fontSize: 12.5, color: "#6b7688", marginTop: 2 }}>{e.owner_email || "—"}</div>
                  </div>
                  <div style={{ textAlign: "right" }}>
                    <Badge tone={statusTone(e.status, days)}>{e.status}</Badge>
                    <div style={{ fontSize: 12.5, color: days !== null && days <= 7 ? C.coral : "#6b7688", marginTop: 4 }}>
                      {expLabel}{e.expiry_date ? ` · ${fmtDate(e.expiry_date)}` : ""}
                    </div>
                    <div style={{ marginTop: 6 }}>
                      {e.domain_verified
                        ? <Badge tone="lime">✓ domain verified</Badge>
                        : <Badge tone="ghost">domain unverified</Badge>}
                    </div>
                  </div>
                </div>

                <div style={{ marginTop: 8, display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
                  <Btn tone="ghost" onClick={() => verify(e.id)} disabled={verifying === e.id}>
                    {verifying === e.id ? "Verifying…" : "Verify domain"}
                  </Btn>
                  {(verifyMsg[e.id] || e.domain_verify_msg) && (
                    <span style={{ fontSize: 12, color: e.domain_verified ? "#2e7d32" : "#9a2b2b" }}>
                      {verifyMsg[e.id] || e.domain_verify_msg}
                    </span>
                  )}
                </div>

                <div style={{ marginTop: 10, fontSize: 12, color: "#9aa3b2", fontFamily: "monospace", wordBreak: "break-all" }}>
                  {e.enrollment_key}
                </div>

                <div style={{ marginTop: 12 }}>
                  <div style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.4, color: "#9aa3b2", marginBottom: 6 }}>
                    Enrolled sources ({e.sources.length})
                  </div>
                  {e.sources.length === 0 ? (
                    <div style={{ fontSize: 12.5, color: "#9aa3b2" }}>No sources attached.</div>
                  ) : (
                    <div style={{ display: "flex", flexWrap: "wrap", gap: 7 }}>
                      {e.sources.map((s) => (
                        <span key={s.source_id}
                          style={{ border: "1px solid #d4d9e3", borderRadius: 8, padding: "5px 10px", fontSize: 12.5 }}>
                          <strong>{s.name || s.source_id}</strong>
                          <span style={{ color: "#9aa3b2" }}> · {s.categories && s.categories.length ? s.categories.join(", ") : "all"}</span>
                        </span>
                      ))}
                    </div>
                  )}
                </div>

                {e.last_mismatch_domain && (
                  <div style={{ marginTop: 10, background: "#fff4f4", border: `1px solid ${C.coral}`, borderRadius: 8, padding: "8px 11px", fontSize: 12.5, color: "#9a2b2b" }}>
                    ⚠ Key was used from an unrecognized domain: <strong>{e.last_mismatch_domain}</strong>
                    {e.last_mismatch_at ? ` · ${fmtDate(e.last_mismatch_at)}` : ""}
                  </div>
                )}

                <div style={{ fontSize: 11.5, color: "#9aa3b2", marginTop: 10, display: "flex", gap: 16, flexWrap: "wrap" }}>
                  {e.last_seen_domain && <span>Used from: <strong style={{ color: "#6b7688" }}>{e.last_seen_domain}</strong></span>}
                  {e.last_sync_at && <span>Last sync: {fmtDate(e.last_sync_at)}</span>}
                </div>
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}
