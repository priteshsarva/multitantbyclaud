import React, { useEffect, useState } from "react";
import { api } from "../api.js";
import { PageHead, Card, Btn, Field, inputStyle, Spinner, ErrorNote } from "../ui.jsx";

// Admin: configure the SMTP server used to send invoices / reminders / receipts.
export default function AdminEmailSettings() {
  const [cfg, setCfg] = useState(null);
  const [error, setError] = useState(null);
  const [msg, setMsg] = useState("");
  const [busy, setBusy] = useState(false);
  const [testTo, setTestTo] = useState("");

  useEffect(() => {
    api.adminGetSmtp().then((r) => setCfg(r.smtp)).catch(setError);
  }, []);

  const set = (k, v) => setCfg((c) => ({ ...c, [k]: v }));

  async function save() {
    setBusy(true); setError(null); setMsg("");
    try { const r = await api.adminSaveSmtp(cfg); setCfg(r.smtp); setMsg("Saved."); }
    catch (e) { setError(e); } finally { setBusy(false); }
  }
  async function sendTest() {
    setBusy(true); setError(null); setMsg("");
    try { const r = await api.adminTestSmtp(testTo || undefined); setMsg(`Test email sent to ${r.to}.`); }
    catch (e) { setError(e); } finally { setBusy(false); }
  }

  if (!cfg) return <div><PageHead title="Email (SMTP)" /><Spinner /></div>;

  return (
    <div>
      <PageHead title="Email (SMTP)" sub="The mail server used for invoices, reminders and receipts. Falls back to server .env if left blank." />
      <ErrorNote error={error} />
      {msg && <div style={{ background: "#eef7ee", border: "1px solid #cbe5cb", color: "#2c6e2c", padding: "9px 13px", borderRadius: 9, marginBottom: 14, fontSize: 13 }}>{msg}</div>}

      <Card style={{ maxWidth: 520 }}>
        <Field label="SMTP host"><input style={inputStyle} value={cfg.host || ""} onChange={(e) => set("host", e.target.value)} placeholder="smtp.gmail.com" /></Field>
        <div style={{ display: "flex", gap: 12 }}>
          <div style={{ flex: 1 }}><Field label="Port"><input style={inputStyle} value={cfg.port || ""} onChange={(e) => set("port", e.target.value)} placeholder="587" /></Field></div>
          <div style={{ flex: 1, display: "flex", alignItems: "center", paddingTop: 20 }}>
            <label style={{ fontSize: 13, display: "flex", alignItems: "center", gap: 8, cursor: "pointer" }}>
              <input type="checkbox" checked={!!cfg.secure} onChange={(e) => set("secure", e.target.checked)} /> SSL (port 465)
            </label>
          </div>
        </div>
        <Field label="Username"><input style={inputStyle} value={cfg.user || ""} onChange={(e) => set("user", e.target.value)} placeholder="you@yourco.com" /></Field>
        <Field label="Password / app password">
          <input style={inputStyle} type="password" value={cfg.pass || ""} onChange={(e) => set("pass", e.target.value)} placeholder={cfg.hasPass ? "•••••••• (unchanged)" : ""} />
        </Field>
        <Field label="From address"><input style={inputStyle} value={cfg.from || ""} onChange={(e) => set("from", e.target.value)} placeholder="Server Products <no-reply@yourco.com>" /></Field>
        <div style={{ display: "flex", gap: 8, marginTop: 6 }}>
          <Btn tone="lime" onClick={save} disabled={busy}>{busy ? "Saving…" : "Save"}</Btn>
        </div>
      </Card>

      <Card style={{ maxWidth: 520, marginTop: 16 }}>
        <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 10 }}>Send a test email</div>
        <div style={{ display: "flex", gap: 8 }}>
          <input style={inputStyle} value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="recipient@example.com (blank = your own)" />
          <Btn tone="ghost" onClick={sendTest} disabled={busy}>Send test</Btn>
        </div>
        <div style={{ fontSize: 12, color: "#6b7688", marginTop: 8 }}>Save first, then test — the test uses the saved config.</div>
      </Card>
    </div>
  );
}
