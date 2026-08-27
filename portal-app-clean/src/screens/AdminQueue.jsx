import React, { useEffect, useState } from "react";
import { api } from "../api.js";
import { C, PageHead, Card, Btn, Badge, Spinner, ErrorNote, Empty, Modal, Field, inputStyle, fmtDate } from "../ui.jsx";

function deriveFromUrl(url) {
  let host = "";
  try { host = new URL(url).hostname; } catch { host = (url || "").replace(/^https?:\/\//, "").split("/")[0]; }
  const sub = host.split(".")[0] || "";
  const isJd = host.includes("jdwebnship");
  const name = sub.replace(/[-_]/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
  return {
    source_id: sub,
    search_key: sub,
    base_url: url && url.startsWith("http") ? url : `https://${host}/`,
    method: isJd ? "METHOD_B" : "METHOD_A",
    name,
  };
}

export default function AdminQueue() {
  const [reqs, setReqs] = useState(null);
  const [enr, setEnr] = useState(null);
  const [error, setError] = useState(null);
  const [approving, setApproving] = useState(null);

  async function load() {
    setError(null);
    try {
      // Show enrollments still needing action: pending (need Approve) AND
      // approved (need Activate). Approved ones used to vanish here — the queue
      // only fetched "pending" — leaving no way to reach the Activate step.
      const [a, pend, appr] = await Promise.allSettled([
        api.adminScrapeRequests("pending"),
        api.adminEnrollments("pending"),
        api.adminEnrollments("approved"),
      ]);
      setReqs(a.status === "fulfilled" ? (a.value.requests || []) : []);
      const merge = (r) => (r.status === "fulfilled" ? (r.value.enrollments || []) : []);
      setEnr([...merge(pend), ...merge(appr)]);
      if (pend.status === "rejected" && appr.status === "rejected") setError(pend.reason);
    } catch (e) { setError(e); }
  }
  useEffect(() => { load(); }, []);

  async function act(fn, ...args) {
    try { await fn(...args); load(); } catch (e) { alert(e.message); }
  }

  return (
    <div>
      <PageHead title="Approval queue" sub="Pending site requests and enrollments." />
      <ErrorNote error={error} />

      <Card style={{ marginBottom: 18 }}>
        <div style={{ fontWeight: 700, marginBottom: 12, fontSize: 14 }}>Scrape requests</div>
        {!reqs ? <Spinner /> : reqs.length === 0 ? <Empty msg="No pending scrape requests." /> : (
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            {reqs.map((r) => (
              <div key={r.id} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "10px 12px", border: "1px solid #eef1f6", borderRadius: 9, flexWrap: "wrap", gap: 8 }}>
                <div>
                  <div style={{ fontSize: 13.5, fontWeight: 600 }}>{r.site_url}</div>
                  <div style={{ fontSize: 12, color: "#6b7688" }}>{r.category} · {r.owner_email || ""} · {fmtDate(r.created_at)}</div>
                </div>
                <div style={{ display: "flex", gap: 7 }}>
                  <Btn small tone="lime" onClick={() => setApproving(r)}>Approve as source</Btn>
                  <Btn small tone="ghost" onClick={() => { if (confirm("Mark resolved without creating a source? Use this if the site is already a source.")) act(api.adminResolveScrapeRequest, r.id); }}>Mark resolved</Btn>
                  <Btn small tone="danger" onClick={() => { if (confirm("Reject this request?")) act(api.adminRejectScrapeRequest, r.id); }}>Reject</Btn>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card>
        <div style={{ fontWeight: 700, marginBottom: 12, fontSize: 14 }}>Enrollments awaiting action</div>
        {!enr ? <Spinner /> : enr.length === 0 ? <Empty msg="No enrollments awaiting approval or activation." /> : (
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            {enr.map((e) => (
              <div key={e.id} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "10px 12px", border: "1px solid #eef1f6", borderRadius: 9 }}>
                <div>
                  <div style={{ fontSize: 13.5, fontWeight: 600 }}>{e.domain}</div>
                  <div style={{ fontSize: 12, color: "#6b7688", display: "flex", gap: 6, alignItems: "center" }}>{e.source_id} · <Badge status={e.status} /></div>
                </div>
                <div style={{ display: "flex", gap: 7 }}>
                  {e.status === "pending" && <Btn small tone="lime" onClick={() => act(api.adminApproveEnrollment, e.id)}>Approve</Btn>}
                  {e.status === "approved" && <Btn small tone="lime" onClick={() => act(api.adminActivateEnrollment, e.id)}>Activate</Btn>}
                  <Btn small tone="danger" onClick={() => act(api.adminRejectEnrollment, e.id)}>Reject</Btn>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {approving && (
        <ApproveModal request={approving} onClose={() => setApproving(null)} onDone={() => { setApproving(null); load(); }} />
      )}
    </div>
  );
}

function ApproveModal({ request, onClose, onDone }) {
  const [form, setForm] = useState(() => deriveFromUrl(request.site_url));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  function set(k, v) { setForm((f) => ({ ...f, [k]: v })); }

  async function submit() {
    setBusy(true); setError(null);
    try {
      await api.adminApproveScrapeRequest(request.id, {
        source_id: form.source_id.trim(),
        name: form.name.trim(),
        method: form.method,
        base_url: form.base_url.trim(),
        search_key: form.search_key.trim(),
      });
      onDone();
    } catch (e) { setError(e); setBusy(false); }
  }

  return (
    <Modal title="Approve as source" onClose={onClose}>
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 14 }}>
        Creates the source, scrapes its categories, then queues a full scrape. Defaults are guessed from the URL — check them.
      </div>
      <ErrorNote error={error} />
      <Field label="Source id (unique)">
        <input style={inputStyle} value={form.source_id} onChange={(e) => set("source_id", e.target.value)} />
      </Field>
      <Field label="Name">
        <input style={inputStyle} value={form.name} onChange={(e) => set("name", e.target.value)} />
      </Field>
      <Field label="Base URL">
        <input style={inputStyle} value={form.base_url} onChange={(e) => set("base_url", e.target.value)} />
      </Field>
      <Field label="Search key (must match the subdomain in the product data)">
        <input style={inputStyle} value={form.search_key} onChange={(e) => set("search_key", e.target.value)} />
      </Field>
      <Field label="Method">
        <select style={inputStyle} value={form.method} onChange={(e) => set("method", e.target.value)}>
          <option value="METHOD_A">METHOD_A (cartpe-style /allcategory.html)</option>
          <option value="METHOD_B">METHOD_B (jdwebnship-style /categories)</option>
        </select>
      </Field>
      <div style={{ background: "#fff7e6", border: "1px solid #f0d9a8", borderRadius: 9, padding: "9px 12px", fontSize: 12, color: "#8a6d2f", marginBottom: 14 }}>
        If this site is <strong>already a source</strong>, close this and use <strong>Mark resolved</strong> instead — approving will re-scrape it.
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <Btn tone="lime" onClick={submit} disabled={busy || !form.source_id || !form.base_url}>{busy ? "Approving…" : "Create & scrape"}</Btn>
        <Btn tone="ghost" onClick={onClose}>Cancel</Btn>
      </div>
    </Modal>
  );
}
