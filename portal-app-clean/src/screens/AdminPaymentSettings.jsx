import React, { useEffect, useState } from "react";
import { CheckCircle2, Circle } from "lucide-react";
import { api } from "../api.js";
import { PageHead, Card, Btn, Field, inputStyle, Spinner, ErrorNote } from "../ui.jsx";

// small inline status pill (ui.jsx Badge is fixed to enrollment statuses)
function Pill({ children, bg = "#eef1f6", fg = "#6b7688" }) {
  return <span style={{ background: bg, color: fg, fontSize: 11, fontWeight: 700, padding: "2px 8px", borderRadius: 999 }}>{children}</span>;
}

// Admin: a WooCommerce-style list of payment gateways. Pick which one is LIVE and
// edit its keys. Secrets stay on the server — the WordPress plugin only ever reads
// the active gateway's name + a server-made pay link, so switching the gateway
// here never requires a plugin update.
//
// Per-provider field maps. Add a provider here (+ DEFAULT_PROVIDERS + an adapter in
// pluginPayRoutes on the server) and the UI generalises with no further work.
const PROVIDER_FIELDS = {
  pay0: [
    { key: "title", label: "Display title", ph: "UPI (Scan & Pay)" },
    { key: "description", label: "Description", ph: "Pay securely with any UPI app." },
    { key: "base_url", label: "API base URL", ph: "https://pay0.shop/api" },
    { key: "api_key", label: "API Key", secret: true, ph: "your Pay0 API key" },
    { key: "secret", label: "Secret", secret: true, ph: "your Pay0 secret (webhook verify)" },
    { key: "webhook_url", label: "Webhook URL", ph: "https://your-server/portal/pay/webhook" },
  ],
};

export default function AdminPaymentSettings() {
  const [reg, setReg] = useState(null);        // { active, providers:{id:{...}} }
  const [editing, setEditing] = useState(null); // provider id currently open
  const [draft, setDraft] = useState(null);
  const [error, setError] = useState(null);
  const [msg, setMsg] = useState("");
  const [busy, setBusy] = useState(false);

  function load() {
    api.adminGetPayment().then((r) => setReg(r.payment)).catch(setError);
  }
  useEffect(load, []);

  function openEdit(id) {
    setEditing(id);
    setDraft({ ...reg.providers[id] });
    setMsg(""); setError(null);
  }
  const setField = (k, v) => setDraft((d) => ({ ...d, [k]: v }));

  async function makeActive(id) {
    setBusy(true); setError(null); setMsg("");
    try { const r = await api.adminSetActiveProvider(id); setReg(r.payment); setMsg(`${label(id)} is now the live gateway.`); }
    catch (e) { setError(e); } finally { setBusy(false); }
  }

  async function saveProvider() {
    setBusy(true); setError(null); setMsg("");
    try {
      const r = await api.adminSaveProvider(editing, draft);
      setReg(r.payment); setEditing(null); setDraft(null);
      setMsg("Saved. New payments use this immediately — no plugin update needed.");
    } catch (e) { setError(e); } finally { setBusy(false); }
  }

  const label = (id) => (reg && reg.providers[id] && reg.providers[id].label) || id;

  if (!reg) return <div><PageHead title="Payments" /><Spinner /></div>;

  const ids = Object.keys(reg.providers);

  return (
    <div>
      <PageHead title="Payments" sub="Pick which gateway is live. Keys never leave the server — the plugin fetches the active gateway from here, so changing it needs no plugin update." />
      <ErrorNote error={error} />
      {msg && <div style={{ background: "#eef7ee", border: "1px solid #cbe5cb", color: "#2c6e2c", padding: "9px 13px", borderRadius: 9, marginBottom: 14, fontSize: 13 }}>{msg}</div>}

      <div style={{ display: "flex", flexDirection: "column", gap: 12, maxWidth: 640 }}>
        {ids.map((id) => {
          const p = reg.providers[id];
          const active = reg.active === id;
          return (
            <Card key={id} style={{ borderColor: active ? "#8bc34a" : undefined }}>
              <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
                <div style={{ flex: 1 }}>
                  <div style={{ display: "flex", alignItems: "center", gap: 7 }}>
                    <strong style={{ fontSize: 15 }}>{p.label || id}</strong>
                    {active && <Pill bg="#16361b" fg="#C8FF3D">Live</Pill>}
                    {p.enabled ? <Pill bg="#16302f" fg="#7DC4FF">Enabled</Pill> : <Pill>Disabled</Pill>}
                    {!p.hasKey && <Pill bg="#2e2713" fg="#F6B45A">No key</Pill>}
                  </div>
                  <div style={{ fontSize: 12.5, color: "#68727f", marginTop: 3 }}>{p.title}{p.description ? ` · ${p.description}` : ""}</div>
                </div>
                {!active && (
                  <button onClick={() => makeActive(id)} disabled={busy} title="Make this the live gateway"
                    style={{ display: "flex", alignItems: "center", gap: 6, background: "none", border: "1px solid #d6dbe4", borderRadius: 8, padding: "6px 11px", cursor: "pointer", fontSize: 12.5, color: "#3a4453" }}>
                    <Circle size={14} /> Set live
                  </button>
                )}
                {active && <span style={{ display: "flex", alignItems: "center", gap: 6, color: "#4e9a2f", fontSize: 12.5 }}><CheckCircle2 size={16} /> Live</span>}
                <Btn onClick={() => openEdit(id)}>Manage</Btn>
              </div>

              {editing === id && draft && (
                <div style={{ marginTop: 14, paddingTop: 14, borderTop: "1px solid #eceff5" }}>
                  {(PROVIDER_FIELDS[id] || []).map((f) => (
                    <Field key={f.key} label={f.label}>
                      <input style={inputStyle}
                        type={f.secret ? "password" : "text"}
                        value={draft[f.key] || ""}
                        onChange={(e) => setField(f.key, e.target.value)}
                        placeholder={f.secret && (f.key === "api_key" ? draft.hasKey : draft.hasSecret) ? "•••••••• (unchanged)" : f.ph}
                      />
                    </Field>
                  ))}
                  <label style={{ fontSize: 13, display: "flex", alignItems: "center", gap: 8, cursor: "pointer", margin: "4px 0 12px" }}>
                    <input type="checkbox" checked={!!draft.enabled} onChange={(e) => setField("enabled", e.target.checked)} />
                    Enabled (customers can pay via the portal and the plugin)
                  </label>
                  <div style={{ display: "flex", gap: 8 }}>
                    <Btn tone="lime" onClick={saveProvider} disabled={busy}>{busy ? "Saving…" : "Save"}</Btn>
                    <Btn onClick={() => { setEditing(null); setDraft(null); }}>Cancel</Btn>
                  </div>
                </div>
              )}
            </Card>
          );
        })}
      </div>

      <Card style={{ maxWidth: 640, marginTop: 16, background: "#f8f9fc" }}>
        <div style={{ fontSize: 12.5, color: "#55606f", lineHeight: 1.6 }}>
          <strong>How the plugin uses this.</strong> The WordPress plugin calls
          <code style={{ background: "#eceff5", padding: "1px 5px", borderRadius: 4, margin: "0 3px" }}>/product/pay-config</code>
          to learn the live gateway's name and
          <code style={{ background: "#eceff5", padding: "1px 5px", borderRadius: 4, margin: "0 3px" }}>/product/pay-start</code>
          to get a pay link the server builds. No key ever reaches the plugin, so switching gateways here is instant and needs no plugin re-upload.
        </div>
      </Card>
    </div>
  );
}
