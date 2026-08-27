import React, { useState, useEffect } from "react";
import { KeyRound } from "lucide-react";
import { api, setToken } from "../api.js";
import { C, Field, inputStyle, Btn, ErrorNote } from "../ui.jsx";

export default function Login({ onLogin }) {
  const [mode, setMode] = useState("login"); // login | signup
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [name, setName] = useState("");
  const [mobile, setMobile] = useState("");
  const [shopUrl, setShopUrl] = useState("");
  const [planId, setPlanId] = useState("");
  const [plans, setPlans] = useState([]);
  // optional
  const [instagram, setInstagram] = useState("");
  const [facebook, setFacebook] = useState("");
  const [whatsappNumber, setWhatsappNumber] = useState("");
  const [whatsappCommunity, setWhatsappCommunity] = useState("");
  const [showOptional, setShowOptional] = useState(false);

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (mode === "signup" && plans.length === 0) {
      api.publicPlans().then((r) => setPlans(r.plans || [])).catch(() => {});
    }
  }, [mode]);

  async function submit(e) {
    e.preventDefault();
    if (mode === "signup" && !planId) { setError(new Error("Please choose a plan")); return; }
    setBusy(true); setError(null);
    try {
      let res;
      if (mode === "login") {
        res = await api.login(email, password);
      } else {
        const social_urls = {};
        if (instagram) social_urls.instagram = instagram;
        if (facebook) social_urls.facebook = facebook;
        res = await api.signup({
          email, password, name, mobile,
          shop_url: shopUrl, plan_id: planId,
          social_urls,
          whatsapp_number: whatsappNumber || undefined,
          whatsapp_community_url: whatsappCommunity || undefined,
        });
      }
      const token = res.token || res.accessToken || res.jwt;
      if (!token) throw new Error("No token in response");
      setToken(token);
      const user = res.user || (await api.me()).user || (await api.me());
      onLogin(user);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  const isSignup = mode === "signup";

  return (
    <div style={{ minHeight: "100vh", background: C.ink, display: "flex", alignItems: "center", justifyContent: "center", padding: 20, fontFamily: "ui-sans-serif, system-ui, sans-serif" }}>
      <div style={{ background: "#fff", borderRadius: 18, padding: 30, width: "100%", maxWidth: isSignup ? 440 : 380, maxHeight: "92vh", overflowY: "auto" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 9, marginBottom: 4 }}>
          <div style={{ width: 30, height: 30, borderRadius: 8, background: C.lime, display: "flex", alignItems: "center", justifyContent: "center" }}>
            <KeyRound size={17} color={C.ink} />
          </div>
          <strong style={{ fontSize: 17 }}>Server Products</strong>
        </div>
        <p style={{ color: "#6b7688", fontSize: 13, margin: "0 0 20px" }}>
          {isSignup ? "Create your account and add your first shop." : "Sign in to your portal."}
        </p>

        <ErrorNote error={error} />
        <form onSubmit={submit}>
          {isSignup && (
            <Field label="Name">
              <input style={inputStyle} value={name} onChange={(e) => setName(e.target.value)} placeholder="Your name" />
            </Field>
          )}
          <Field label="Email">
            <input style={inputStyle} type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@store.com" />
          </Field>
          <Field label="Password">
            <input style={inputStyle} type="password" required value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" />
          </Field>

          {isSignup && (
            <>
              <Field label="Mobile number">
                <input style={inputStyle} required value={mobile} onChange={(e) => setMobile(e.target.value)} placeholder="+91 98765 43210" />
              </Field>
              <Field label="Your shop URL">
                <input style={inputStyle} required value={shopUrl} onChange={(e) => setShopUrl(e.target.value)} placeholder="https://mystore.com" />
              </Field>
              <Field label="Plan">
                <select style={inputStyle} required value={planId} onChange={(e) => setPlanId(e.target.value)}>
                  <option value="">Choose a plan…</option>
                  {plans.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} — {p.currency} {Number(p.price).toLocaleString("en-IN")}/{p.interval}
                    </option>
                  ))}
                </select>
                {plans.length === 0 && <div style={{ fontSize: 11.5, color: "#9aa3b2", marginTop: 4 }}>No plans available yet — contact the admin.</div>}
              </Field>

              <button type="button" onClick={() => setShowOptional((v) => !v)}
                style={{ background: "none", border: "none", color: C.ink, fontSize: 12.5, cursor: "pointer", padding: "4px 0", fontWeight: 600 }}>
                {showOptional ? "− Hide optional details" : "+ Add social & WhatsApp (optional)"}
              </button>

              {showOptional && (
                <div style={{ borderLeft: `2px solid #eef1f6`, paddingLeft: 12, marginTop: 6 }}>
                  <Field label="Instagram URL">
                    <input style={inputStyle} value={instagram} onChange={(e) => setInstagram(e.target.value)} placeholder="https://instagram.com/…" />
                  </Field>
                  <Field label="Facebook URL">
                    <input style={inputStyle} value={facebook} onChange={(e) => setFacebook(e.target.value)} placeholder="https://facebook.com/…" />
                  </Field>
                  <Field label="WhatsApp number">
                    <input style={inputStyle} value={whatsappNumber} onChange={(e) => setWhatsappNumber(e.target.value)} placeholder="+91 98765 43210" />
                  </Field>
                  <Field label="WhatsApp community link">
                    <input style={inputStyle} value={whatsappCommunity} onChange={(e) => setWhatsappCommunity(e.target.value)} placeholder="https://chat.whatsapp.com/…" />
                  </Field>
                </div>
              )}
            </>
          )}

          <div style={{ marginTop: 10 }}>
            <Btn type="submit" tone="lime" disabled={busy}>
              {busy ? "Please wait…" : isSignup ? "Create account" : "Sign in"}
            </Btn>
          </div>
        </form>

        <button onClick={() => { setMode(isSignup ? "login" : "signup"); setError(null); }}
          style={{ marginTop: 16, background: "none", border: "none", color: "#6b7688", fontSize: 12.5, cursor: "pointer" }}>
          {isSignup ? "Have an account? Sign in" : "Need an account? Sign up"}
        </button>
      </div>
    </div>
  );
}
