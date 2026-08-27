import React, { useEffect, useState } from "react";
import { Globe, Copy, Check, Plus, Trash2, ChevronDown, ChevronRight } from "lucide-react";
import { api } from "../api.js";
import { C, PageHead, Card, Btn, Badge, Spinner, ErrorNote, Empty, Modal, Field, inputStyle, fmtDate, SearchSelect } from "../ui.jsx";

export default function MySites() {
  const [enr, setEnr] = useState(null);
  const [error, setError] = useState(null);
  const [open, setOpen] = useState({}); // enrollmentId -> bool
  const [copied, setCopied] = useState(null);
  const [addingShop, setAddingShop] = useState(false);

  async function load() {
    setError(null);
    try { setEnr((await api.enrollments()).enrollments || []); }
    catch (e) { setError(e); }
  }
  useEffect(() => { load(); }, []);

  function copyKey(k) {
    navigator.clipboard?.writeText(k);
    setCopied(k);
    setTimeout(() => setCopied(null), 1400);
  }

  if (error) return (<div><PageHead title="My sites" /><ErrorNote error={error} /></div>);
  if (!enr) return (<div><PageHead title="My sites" /><Spinner /></div>);

  return (
    <div>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 12 }}>
        <PageHead title="My sites" sub="Each shop has its own key and invoice, and can pull sources from any category." />
        <Btn tone="lime" onClick={() => setAddingShop(true)}><Plus size={15} style={{ verticalAlign: "-2px" }} /> Add shop</Btn>
      </div>
      {addingShop && <AddShopModal onClose={() => setAddingShop(false)} onDone={() => { setAddingShop(false); load(); }} />}
      {enr.length === 0 ? <Card><Empty msg="No sites yet. Add one to get a key." /></Card> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          {enr.map((e) => (
            <Card key={e.id} style={{ padding: 0, overflow: "hidden" }}>
              <div style={{ padding: 16, display: "flex", alignItems: "center", justifyContent: "space-between" }}>
                <div style={{ display: "flex", alignItems: "center", gap: 11 }}>
                  <Globe size={18} color="#9aa3b2" />
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 14.5 }}>{e.domain}</div>
                    <div style={{ fontSize: 12, color: "#6b7688", marginTop: 2 }}>
                      renews {fmtDate(e.renewal_date)} · expires {fmtDate(e.expiry_date)}
                    </div>
                  </div>
                </div>
                <Badge status={e.status} />
              </div>

              {/* key row */}
              <div style={{ padding: "0 16px 14px", display: "flex", alignItems: "center", gap: 8 }}>
                <code style={{ flex: 1, background: "#0E1726", color: "#C8FF3D", padding: "8px 12px", borderRadius: 9, fontSize: 12.5, overflow: "hidden", textOverflow: "ellipsis" }}>
                  {e.enrollment_key}
                </code>
                <Btn small tone="ghost" onClick={() => copyKey(e.enrollment_key)}>
                  {copied === e.enrollment_key ? <Check size={14} /> : <Copy size={14} />}
                </Btn>
              </div>

              {/* sources toggle */}
              <button onClick={() => setOpen((o) => ({ ...o, [e.id]: !o[e.id] }))}
                style={{ width: "100%", textAlign: "left", padding: "11px 16px", background: "#f7f8fb", border: "none", borderTop: "1px solid #eef1f6", cursor: "pointer", fontSize: 13, fontWeight: 600, color: "#42505f", display: "flex", alignItems: "center", gap: 7 }}>
                {open[e.id] ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
                Sources & categories
              </button>
              {open[e.id] && <SourcePanel enrollment={e} />}
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

function SourcePanel({ enrollment }) {
  const [sources, setSources] = useState(null);
  const [error, setError] = useState(null);
  const [adding, setAdding] = useState(false);
  const [grouping, setGrouping] = useState(false);

  async function load() {
    setError(null);
    try { setSources((await api.enrollmentSources(enrollment.id)).sources || []); }
    catch (e) { setError(e); }
  }
  useEffect(() => { load(); }, [enrollment.id]);

  async function remove(sourceId) {
    if (!confirm(`Remove ${sourceId} from this site?`)) return;
    try { await api.removeEnrollmentSource(enrollment.id, sourceId); load(); }
    catch (e) { alert(e.message); }
  }

  const existingCategory = sources && sources.length ? sources[0].category : null;

  return (
    <div style={{ padding: 16, background: "#fbfcfe", borderTop: "1px solid #eef1f6" }}>
      <ErrorNote error={error} />
      {!sources ? <Spinner msg="Loading sources…" /> : (
        <>
          {sources.length === 0 ? <Empty msg="No sources on this site yet." /> : (
            <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
              {sources.map((s) => (
                <div key={s.source_id} style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", padding: "10px 12px", background: "#fff", border: "1px solid #eef1f6", borderRadius: 9 }}>
                  <div>
                    <div style={{ fontWeight: 600, fontSize: 13.5 }}>
                      {s.name || s.source_id} <span style={{ color: "#9aa3b2", fontWeight: 500 }}>· {s.category}</span>
                    </div>
                    <div style={{ marginTop: 5, display: "flex", flexWrap: "wrap", gap: 5 }}>
                      {(s.categories && s.categories.length ? s.categories : ["(all categories)"]).map((c) => (
                        <span key={c} style={{ background: "#eef1f6", color: "#55606f", fontSize: 11.5, padding: "2px 8px", borderRadius: 999 }}>{c}</span>
                      ))}
                    </div>
                  </div>
                  <button onClick={() => remove(s.source_id)} title="Remove" style={{ background: "none", border: "none", cursor: "pointer", color: "#c0859a" }}>
                    <Trash2 size={15} />
                  </button>
                </div>
              ))}
            </div>
          )}
          <div style={{ marginTop: 12, display: "flex", gap: 8, flexWrap: "wrap" }}>
            <Btn small tone="lime" onClick={() => setAdding(true)}><Plus size={14} style={{ verticalAlign: "-2px" }} /> Add source</Btn>
            {sources.length > 0 && (
              <Btn small tone="ghost" onClick={() => setGrouping(true)}>Group categories</Btn>
            )}
          </div>
        </>
      )}

      {adding && (
        <AddSourceModal
          enrollmentId={enrollment.id}
          lockCategory={null}
          onClose={() => setAdding(false)}
          onDone={() => { setAdding(false); load(); }}
        />
      )}

      {grouping && (
        <CategoryGroupingModal
          enrollmentId={enrollment.id}
          onClose={() => setGrouping(false)}
        />
      )}
    </div>
  );
}

function CategoryGroupingModal({ enrollmentId, onClose }) {
  const [data, setData] = useState(null);        // [{source_id, name, categories:[{cat_name, product_count, canonical}]}]
  const [canon, setCanon] = useState({});        // `${source_id}|${cat_name}` -> canonical
  const [suggestions, setSuggestions] = useState([]);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api.enrollmentCategoryMap(enrollmentId).then((r) => {
      const srcs = r.sources || [];
      setData(srcs);
      setSuggestions(r.canonicals || []);
      const init = {};
      srcs.forEach((s) => s.categories.forEach((c) => { init[`${s.source_id}|${c.cat_name}`] = c.canonical || ""; }));
      setCanon(init);
    }).catch(setError);
  }, [enrollmentId]);

  async function save() {
    setSaving(true); setSaved(false); setError(null);
    try {
      const mappings = Object.entries(canon).map(([k, canonical]) => {
        const [source_id, cat_name] = k.split("|");
        return { source_id, cat_name, canonical };
      });
      await api.saveEnrollmentCategoryMap(enrollmentId, mappings);
      setSaved(true);
    } catch (e) { setError(e); } finally { setSaving(false); }
  }

  return (
    <Modal title="Group categories for this shop" onClose={onClose}>
      <ErrorNote error={error} />
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 12 }}>
        Give categories a <strong>unified name</strong> to group them in your store — e.g. set both
        “Mens Watch” and “Men's Watch” to <strong>Men Watch</strong>. Leave blank to keep the original.
      </div>
      <div style={{ display: "flex", gap: 8, marginBottom: 12 }}>
        <Btn small tone="lime" onClick={save} disabled={saving || !data}>{saving ? "Saving…" : "Save grouping"}</Btn>
        {saved && <span style={{ fontSize: 12.5, color: "#2e7d32", alignSelf: "center" }}>Saved ✓ — new syncs use these names</span>}
      </div>
      <datalist id="store-canon">
        {suggestions.map((s) => <option key={s} value={s} />)}
      </datalist>

      {!data ? <Spinner /> : data.length === 0 ? <Empty msg="No sources yet." /> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 14, maxHeight: "55vh", overflowY: "auto" }}>
          {data.map((s) => (
            <div key={s.source_id}>
              <div style={{ fontSize: 12.5, fontWeight: 700, marginBottom: 6 }}>{s.name}</div>
              {s.categories.length === 0 ? (
                <div style={{ fontSize: 12, color: "#9aa3b2" }}>No categories discovered.</div>
              ) : (
                <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                  {s.categories.map((c) => {
                    const key = `${s.source_id}|${c.cat_name}`;
                    return (
                      <div key={key} style={{ display: "flex", alignItems: "center", gap: 9 }}>
                        <div style={{ flex: 1, minWidth: 0, fontSize: 12.5, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                          {c.cat_name} <span style={{ color: "#9aa3b2" }}>· {c.product_count}</span>
                        </div>
                        <span style={{ color: "#c2c8d2", fontSize: 12 }}>→</span>
                        <input
                          list="store-canon"
                          value={canon[key] || ""}
                          onChange={(e) => setCanon((m) => ({ ...m, [key]: e.target.value }))}
                          placeholder="unified name"
                          style={{ ...inputStyle, flex: 1, minWidth: 90, padding: "5px 9px", fontSize: 12.5 }}
                        />
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </Modal>
  );
}

function AddShopModal({ onClose, onDone }) {
  const [shopUrl, setShopUrl] = useState("");
  const [planId, setPlanId] = useState("");
  const [plans, setPlans] = useState([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => { api.publicPlans().then((r) => setPlans(r.plans || [])).catch(() => {}); }, []);

  async function submit() {
    if (!shopUrl.trim()) { setError(new Error("Shop URL required")); return; }
    if (!planId) { setError(new Error("Please choose a plan")); return; }
    setBusy(true); setError(null);
    try { await api.createShop(shopUrl.trim(), planId); onDone(); }
    catch (e) { setError(e); setBusy(false); }
  }

  return (
    <Modal title="Add a shop" onClose={onClose}>
      <ErrorNote error={error} />
      <div style={{ fontSize: 12.5, color: "#6b7688", marginBottom: 12 }}>
        Add another store. It starts <strong>pending admin approval</strong>; once approved you'll get an
        invoice to activate it, and it gets its own enrollment key.
      </div>
      <Field label="Shop URL">
        <input style={inputStyle} value={shopUrl} onChange={(e) => setShopUrl(e.target.value)} placeholder="https://mystore2.com" />
      </Field>
      <Field label="Plan">
        <select style={inputStyle} value={planId} onChange={(e) => setPlanId(e.target.value)}>
          <option value="">Choose a plan…</option>
          {plans.map((p) => (
            <option key={p.id} value={p.id}>{p.name} — {p.currency} {Number(p.price).toLocaleString("en-IN")}/{p.interval}</option>
          ))}
        </select>
        {plans.length === 0 && <div style={{ fontSize: 11.5, color: "#9aa3b2", marginTop: 4 }}>No plans available — contact the admin.</div>}
      </Field>
      <div style={{ display: "flex", gap: 8, marginTop: 16 }}>
        <Btn tone="lime" onClick={submit} disabled={busy}>{busy ? "Adding…" : "Add shop"}</Btn>
        <Btn tone="ghost" onClick={onClose}>Cancel</Btn>
      </div>
    </Modal>
  );
}

function AddSourceModal({ enrollmentId, lockCategory, onClose, onDone }) {
  const [allSources, setAllSources] = useState(null);
  const [attached, setAttached] = useState([]);   // source_ids already on this site
  const [sourceId, setSourceId] = useState("");
  const [cats, setCats] = useState(null);          // available categories for chosen source
  const [picked, setPicked] = useState([]);        // chosen category names
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  // "request new" mode (site not in the list)
  const [mode, setMode] = useState("pick");        // pick | request
  const [newUrl, setNewUrl] = useState("");
  const [reqCategory, setReqCategory] = useState(lockCategory || "");
  const [requested, setRequested] = useState(false);

  useEffect(() => {
    Promise.all([
      api.sources(),
      api.enrollmentSources(enrollmentId).catch(() => ({ sources: [] })),
    ]).then(([all, mine]) => {
      // ALL sources, every category — stores are cross-category now, so a watch
      // store may add a shoe or perfume source. No lockCategory filtering.
      setAttached((mine.sources || []).map((s) => s.source_id));
      setAllSources(all.sources || []);
    }).catch(setError);
  }, []);

  useEffect(() => {
    setCats(null); setPicked([]);
    if (!sourceId) return;
    api.sourceCategories(sourceId).then((r) => setCats(r.categories || [])).catch(setError);
  }, [sourceId]);

  function toggle(name) {
    setPicked((p) => (p.includes(name) ? p.filter((x) => x !== name) : [...p, name]));
  }

  // attach an existing source — instant, no admin
  async function attach() {
    if (!sourceId) return;
    setBusy(true); setError(null);
    try { await api.addEnrollmentSource(enrollmentId, sourceId, picked); onDone(); }
    catch (e) { setError(e); setBusy(false); }
  }

  // request a brand-new site — goes to admin, tied to this store
  async function request() {
    if (!newUrl.trim()) return;
    setBusy(true); setError(null);
    try {
      await api.createScrapeRequest(newUrl.trim(), reqCategory, enrollmentId);
      setRequested(true); setBusy(false);
    } catch (e) { setError(e); setBusy(false); }
  }

  if (requested) {
    return (
      <Modal title="Request sent" onClose={onDone}>
        <div style={{ fontSize: 13.5, color: "#42505f", lineHeight: 1.6 }}>
          We’ve sent <strong>{newUrl}</strong> to the admin for setup. Once it’s approved it will
          attach to this site automatically and its products will start syncing.
        </div>
        <div style={{ marginTop: 16 }}><Btn tone="lime" onClick={onDone}>Done</Btn></div>
      </Modal>
    );
  }

  const availSources = (allSources || []).filter((s) => !attached.includes(s.id));
  const knownCats = [...new Set((allSources || []).map((s) => s.category).filter(Boolean))].sort();

  return (
    <Modal title="Add a site" onClose={onClose}>
      <ErrorNote error={error} />

      {/* mode toggle */}
      <div style={{ display: "flex", gap: 6, marginBottom: 14 }}>
        <button type="button" onClick={() => setMode("pick")}
          style={tabStyle(mode === "pick")}>Choose existing</button>
        <button type="button" onClick={() => setMode("request")}
          style={tabStyle(mode === "request")}>Not listed? Request new</button>
      </div>

      {mode === "pick" ? (
        <>
          <Field label="Site (type to search)">
            {!allSources ? <div style={{ fontSize: 13, color: "#9aa3b2" }}>Loading…</div> :
              availSources.length === 0 ? (
                <div style={{ fontSize: 12.5, color: "#9aa3b2" }}>
                  No more sites available to add. Use “Request new”.
                </div>
              ) : (
                <SearchSelect
                  value={sourceId}
                  onChange={setSourceId}
                  placeholder={`Search ${availSources.length} sites by name, id or category…`}
                  options={availSources.map((s) => ({
                    value: s.id,
                    label: s.name || s.id,
                    hint: `${s.category}${s.product_count ? ` · ${s.product_count} products` : ""}`,
                  }))}
                />
              )}
          </Field>

          {sourceId && (
            <Field label="Categories (leave none for all)">
              {!cats ? <div style={{ fontSize: 13, color: "#9aa3b2" }}>Loading categories…</div> :
                cats.length === 0 ? <div style={{ fontSize: 12.5, color: "#9aa3b2" }}>No categories discovered — all products will sync.</div> : (
                  <div style={{ display: "flex", flexWrap: "wrap", gap: 7 }}>
                    {cats.map((c) => {
                      const on = picked.includes(c.cat_name);
                      return (
                        <button key={c.cat_name} type="button" onClick={() => toggle(c.cat_name)}
                          style={{ border: on ? `1px solid ${C.ink}` : "1px solid #d4d9e3", background: on ? C.ink : "#fff", color: on ? "#fff" : "#42505f", padding: "5px 11px", borderRadius: 999, fontSize: 12.5, cursor: "pointer" }}>
                          {c.cat_name} <span style={{ opacity: 0.6 }}>{c.product_count}</span>
                        </button>
                      );
                    })}
                  </div>
                )}
            </Field>
          )}

          <div style={{ display: "flex", gap: 8, marginTop: 16 }}>
            <Btn tone="lime" onClick={attach} disabled={!sourceId || busy}>{busy ? "Adding…" : "Add to this site"}</Btn>
            <Btn tone="ghost" onClick={onClose}>Cancel</Btn>
          </div>
        </>
      ) : (
        <>
          <Field label="Site URL">
            <input style={inputStyle} value={newUrl} onChange={(e) => setNewUrl(e.target.value)}
              placeholder="https://newsite.example.com" />
          </Field>
          <Field label="Category (anything — type your own or pick one)">
            <SearchSelect
              value={reqCategory}
              onChange={setReqCategory}
              allowNew
              placeholder="e.g. watches, shoes, perfumes, sunglasses…"
              options={knownCats.map((c) => ({ value: c, label: c }))}
            />
          </Field>
          <div style={{ fontSize: 12, color: "#6b7688", marginTop: 4 }}>
            New sites need a one-time admin setup. We’ll attach it to this store automatically once approved.
          </div>
          <div style={{ display: "flex", gap: 8, marginTop: 16 }}>
            <Btn tone="lime" onClick={request} disabled={!newUrl.trim() || !reqCategory.trim() || busy}>{busy ? "Sending…" : "Send request"}</Btn>
            <Btn tone="ghost" onClick={onClose}>Cancel</Btn>
          </div>
        </>
      )}
    </Modal>
  );
}

function tabStyle(active) {
  return {
    flex: 1, padding: "8px 10px", borderRadius: 8, cursor: "pointer", fontSize: 12.5, fontWeight: 600,
    border: active ? `1px solid ${C.ink}` : "1px solid #d4d9e3",
    background: active ? C.ink : "#fff", color: active ? "#fff" : "#42505f",
  };
}
