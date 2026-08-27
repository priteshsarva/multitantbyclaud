import React, { useEffect, useState } from "react";
import { api } from "../api.js";
import { PageHead, Card, Btn, Badge, Field, inputStyle, Spinner, ErrorNote, Empty, fmtDate, SearchSelect } from "../ui.jsx";

export default function RequestSite() {
  const [reqs, setReqs] = useState(null);
  const [sites, setSites] = useState([]);          // user's enrollments (stores)
  const [error, setError] = useState(null);
  const [site, setSite] = useState("");
  const [enrollmentId, setEnrollmentId] = useState("");
  const [category, setCategory] = useState("");
  const [knownCats, setKnownCats] = useState([]);  // every category already in the registry
  const [busy, setBusy] = useState(false);

  async function load() {
    setError(null);
    try {
      const [r, e, s] = await Promise.all([
        api.myScrapeRequests(), api.enrollments(),
        api.sources().catch(() => ({ sources: [] })),
      ]);
      setReqs(r.requests || []);
      setSites(e.enrollments || []);
      setKnownCats([...new Set((s.sources || []).map((x) => x.category).filter(Boolean))].sort());
    } catch (e) { setError(e); }
  }
  useEffect(() => { load(); }, []);

  // cross-category allowed: a store can hold sources from multiple categories
  async function submit(e) {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      await api.createScrapeRequest(site, category, enrollmentId || undefined);
      setSite("");
      load();
    } catch (err) { setError(err); } finally { setBusy(false); }
  }

  return (
    <div>
      <PageHead title="Request a site" sub="Ask us to add a new source site. Tie it to one of your stores and it attaches automatically once approved." />
      <Card style={{ marginBottom: 18 }}>
        <ErrorNote error={error} />
        <form onSubmit={submit}>
          <Field label="For which store?">
            <select style={inputStyle} value={enrollmentId} onChange={(e) => setEnrollmentId(e.target.value)}>
              <option value="">— not tied to a store —</option>
              {sites.map((s) => <option key={s.id} value={s.id}>{s.domain}{s.category ? ` (${s.category})` : ""}</option>)}
            </select>
          </Field>
          <Field label="Site URL">
            <input style={inputStyle} required value={site} onChange={(e) => setSite(e.target.value)} placeholder="https://newsource.cartpe.in/" />
          </Field>
          <Field label="Category (anything — type your own or pick one)">
            <SearchSelect
              value={category}
              onChange={setCategory}
              allowNew
              placeholder="e.g. watches, shoes, perfumes, sunglasses…"
              options={knownCats.map((c) => ({ value: c, label: c }))}
            />
          </Field>
          <Btn type="submit" tone="lime" disabled={busy || !site.trim() || !category.trim()}>{busy ? "Submitting…" : "Submit request"}</Btn>
        </form>
      </Card>

      <Card>
        <div style={{ fontWeight: 700, marginBottom: 12, fontSize: 14 }}>My requests</div>
        {!reqs ? <Spinner /> : reqs.length === 0 ? <Empty msg="No requests yet." /> : (
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            {reqs.map((r) => (
              <div key={r.id} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "10px 12px", border: "1px solid #eef1f6", borderRadius: 9 }}>
                <div>
                  <div style={{ fontSize: 13.5, fontWeight: 600 }}>{r.site_url}</div>
                  <div style={{ fontSize: 12, color: "#6b7688" }}>{r.category} · {fmtDate(r.created_at)}</div>
                </div>
                <Badge status={r.status} />
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
