import React, { useEffect, useState } from "react";
import { api } from "../api.js";
import { C, PageHead, Card, Btn, Badge, Spinner, ErrorNote, Empty, fmtDate } from "../ui.jsx";

const money = (inv) => `${inv.currency || "INR"} ${Number(inv.amount || 0).toLocaleString("en-IN")}`;
const isUnpaid = (s) => s === "created" || s === "pending";

export default function Billing() {
  const [invoices, setInvoices] = useState(null);
  const [error, setError] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [note, setNote] = useState("");

  async function load() {
    setError(null);
    try { setInvoices((await api.invoices()).invoices || []); }
    catch (e) { setError(e); }
  }

  // First load, and if we just came back from the gateway (?paid=<id>), verify it.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const paidId = params.get("paid");
    (async () => {
      if (paidId) {
        setNote("Confirming your payment…");
        try {
          const r = await api.verifyInvoice(paidId);
          setNote(r.paid ? "Payment confirmed — your shop is active." : "Payment not confirmed yet. If you paid, it may take a moment.");
        } catch { setNote(""); }
        // clean the URL so a refresh doesn't re-verify
        window.history.replaceState({}, "", window.location.pathname);
      }
      load();
    })();
  }, []);

  async function pay(inv) {
    setBusyId(inv.id); setError(null);
    try {
      const { payment_url } = await api.payInvoice(inv.id);
      if (payment_url) window.location.href = payment_url;  // hand off to the gateway
      else { setError(new Error("Could not start payment — no payment URL returned.")); setBusyId(null); }
    } catch (e) { setError(e); setBusyId(null); }
  }

  return (
    <div>
      <PageHead title="Billing" sub="Your invoices. Pay online to activate or renew a shop." />
      {note && (
        <div style={{ background: "#eef7ee", border: "1px solid #cbe5cb", color: "#2c6e2c", padding: "10px 14px", borderRadius: 9, marginBottom: 14, fontSize: 13 }}>{note}</div>
      )}
      <ErrorNote error={error} />

      {!invoices ? <Spinner /> : invoices.length === 0 ? (
        <Card><Empty msg="No invoices yet. Once a shop is approved, its invoice appears here." /></Card>
      ) : (
        <Card style={{ padding: 0, overflow: "hidden" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
            <thead>
              <tr style={{ background: "#f7f8fb", color: "#6b7688", textAlign: "left" }}>
                <th style={th}>Invoice</th><th style={th}>Shop</th><th style={th}>Amount</th>
                <th style={th}>Period</th><th style={th}>Due</th><th style={th}>Status</th><th style={th}></th>
              </tr>
            </thead>
            <tbody>
              {invoices.map((inv) => (
                <tr key={inv.id} style={{ borderTop: "1px solid #eef1f6" }}>
                  <td style={td}><span style={{ fontWeight: 600 }}>{inv.invoice_no || `#${inv.id}`}</span></td>
                  <td style={td}>{inv.domain || "—"}</td>
                  <td style={{ ...td, fontWeight: 600 }}>{money(inv)}</td>
                  <td style={{ ...td, color: "#6b7688", fontSize: 12 }}>
                    {inv.period_start ? `${fmtDate(inv.period_start)} → ${fmtDate(inv.period_end)}` : "—"}
                  </td>
                  <td style={{ ...td, color: "#6b7688" }}>{inv.due_date ? fmtDate(inv.due_date) : "—"}</td>
                  <td style={td}><Badge status={inv.status} /></td>
                  <td style={{ ...td, textAlign: "right" }}>
                    {inv.status === "paid"
                      ? <span style={{ color: "#2c6e2c", fontSize: 12, fontWeight: 600 }}>Paid</span>
                      : isUnpaid(inv.status)
                        ? <Btn small tone="lime" onClick={() => pay(inv)} disabled={busyId === inv.id}>
                            {busyId === inv.id ? "Starting…" : "Pay now"}
                          </Btn>
                        : <span style={{ color: "#9aa3b2", fontSize: 12 }}>{inv.status}</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}

const th = { padding: "10px 14px", fontWeight: 600, fontSize: 12 };
const td = { padding: "10px 14px" };
