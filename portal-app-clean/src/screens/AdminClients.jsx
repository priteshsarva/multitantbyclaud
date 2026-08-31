import React, { useEffect, useState } from "react";
import { Search } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Btn, Badge, Spinner, ErrorNote, Empty, Modal, inputStyle, fmtDate, storeUrl } from "../ui.jsx";

// every field collected at signup, shown to the admin
export default function AdminClients() {
  const [users, setUsers] = useState(null);
  const [error, setError] = useState(null);
  const [q, setQ] = useState("");
  const [active, setActive] = useState(null);

  async function load() {
    setError(null);
    try { setUsers((await api.adminUsers(q)).users || []); }
    catch (e) { setError(e); }
  }
  useEffect(() => { load(); /* eslint-disable-next-line */ }, []);
  // debounce search
  useEffect(() => { const t = setTimeout(load, 300); return () => clearTimeout(t); /* eslint-disable-next-line */ }, [q]);

  return (
    <div>
      <PageHead title="Clients" sub={users ? `${users.length} registered client${users.length === 1 ? "" : "s"}` : "Loading…"} />
      <ErrorNote error={error} />

      <div style={{ position: "relative", maxWidth: 360, marginBottom: 14 }}>
        <Search size={15} style={{ position: "absolute", left: 11, top: 10, color: "#9aa3b2" }} />
        <input style={{ ...inputStyle, paddingLeft: 32 }} placeholder="Search email, name, mobile, shop…" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      {!users ? <Spinner /> : users.length === 0 ? <Card><Empty msg="No clients found." /></Card> : (
        <Card style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
            <thead>
              <tr style={{ background: "#f7f8fb", color: "#6b7688", textAlign: "left" }}>
                <th style={th}>Client</th><th style={th}>Mobile</th><th style={th}>Shops</th>
                <th style={th}>Paid</th><th style={th}>Unpaid</th><th style={th}>Joined</th><th style={th}></th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id} style={{ borderTop: "1px solid #eef1f6" }}>
                  <td style={td}>
                    <div style={{ fontWeight: 600 }}>{u.name || "—"}</div>
                    <div style={{ fontSize: 11.5, color: "#9aa3b2" }}>{u.email}</div>
                  </td>
                  <td style={td}>{u.mobile || "—"}</td>
                  <td style={td}>{u.active_shops}/{u.shops}</td>
                  <td style={{ ...td, fontWeight: 600 }}>{Number(u.paid_total || 0).toLocaleString("en-IN")}</td>
                  <td style={td}>{Number(u.unpaid_invoices) > 0 ? <span style={{ color: "#b26a00", fontWeight: 600 }}>{u.unpaid_invoices}</span> : "0"}</td>
                  <td style={{ ...td, color: "#6b7688" }}>{fmtDate(u.created_at)}</td>
                  <td style={{ ...td, textAlign: "right" }}><Btn small tone="ghost" onClick={() => setActive(u)}>Details</Btn></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {active && <ClientModal u={active} onClose={() => setActive(null)} />}
    </div>
  );
}

function ClientModal({ u, onClose }) {
  const social = u.social_urls && typeof u.social_urls === "object" ? u.social_urls : {};
  const socialRows = Object.entries(social).filter(([, v]) => v);
  const sites = Array.isArray(u.sites) ? u.sites : [];
  const [pw, setPw] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null); // { temp_password? } or error string

  async function reset(generate) {
    setBusy(true); setResult(null);
    try {
      const r = await api.adminSetUserPassword(u.id, generate ? "" : pw);
      setResult(r.temp_password
        ? `Temporary password set: ${r.temp_password} — share it with ${r.email}; they can change it after logging in.`
        : `Password updated for ${r.email}.`);
      setPw("");
    } catch (e) { setResult("Error: " + e.message); }
    finally { setBusy(false); }
  }

  return (
    <Modal title={u.name || u.email} onClose={onClose}>
      <Row label="Email" value={u.email} />
      <Row label="Mobile" value={u.mobile} />
      <Row label="WhatsApp number" value={u.whatsapp_number} />
      <Row label="WhatsApp community" value={u.whatsapp_community_url} link />
      {socialRows.map(([k, v]) => <Row key={k} label={k[0].toUpperCase() + k.slice(1)} value={v} link />)}
      <Row label="Plugin shops" value={(u.domains || []).join(", ")} />
      <Row label="Orders" value={`${u.orders || 0} · ₹${Number(u.order_sales || 0).toLocaleString("en-IN")} sales`} />
      <Row label="Status" value={<Badge status={u.status} />} raw />
      <Row label="Joined" value={fmtDate(u.created_at)} />

      <div style={{ fontWeight: 700, fontSize: 13, margin: "16px 0 8px" }}>Storefronts ({sites.length})</div>
      {sites.length === 0 ? <div style={{ fontSize: 12.5, color: "#9aa3b2" }}>No storefronts.</div> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
          {sites.map((s) => (
            <div key={s.slug} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", fontSize: 12.5, border: "1px solid #eef1f6", borderRadius: 8, padding: "7px 10px" }}>
              <a href={storeUrl(s.slug)} target="_blank" rel="noreferrer" style={{ color: "#3b6fd8", textDecoration: "none" }}>{s.slug}</a>
              <Badge status={s.status} />
            </div>
          ))}
        </div>
      )}

      <div style={{ fontWeight: 700, fontSize: 13, margin: "16px 0 8px" }}>Reset password</div>
      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
        <input style={{ ...inputStyle, flex: 1, minWidth: 160 }} type="text" placeholder="New password (or generate)" value={pw} onChange={(e) => setPw(e.target.value)} />
        <Btn small tone="lime" disabled={busy || !pw} onClick={() => reset(false)}>Set</Btn>
        <Btn small tone="ghost" disabled={busy} onClick={() => reset(true)}>Generate temp</Btn>
      </div>
      {result && <div style={{ fontSize: 12.5, color: result.startsWith("Error") ? "#b3261e" : "#2e7d32", marginTop: 8, wordBreak: "break-word" }}>{result}</div>}
    </Modal>
  );
}

function Row({ label, value, link, raw }) {
  return (
    <div style={{ display: "flex", justifyContent: "space-between", gap: 14, padding: "8px 0", borderBottom: "1px solid #f2f4f8", fontSize: 13 }}>
      <span style={{ color: "#6b7688", flexShrink: 0 }}>{label}</span>
      <span style={{ textAlign: "right", wordBreak: "break-all", color: "#1b2230" }}>
        {raw ? value
          : value
            ? (link ? <a href={value} target="_blank" rel="noopener noreferrer" style={{ color: C.sky || "#2f7bd6" }}>{value}</a> : value)
            : <span style={{ color: "#c4ccd8" }}>—</span>}
      </span>
    </div>
  );
}

const th = { padding: "10px 14px", fontWeight: 600, fontSize: 12 };
const td = { padding: "10px 14px" };
