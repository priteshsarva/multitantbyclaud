import React from "react";

export const C = {
  ink: "#0E1726", surface: "#161F33", surface2: "#1E293F", line: "#283449",
  paper: "#F7F8FB", text: "#E8ECF4", dim: "#93A1B8",
  lime: "#C8FF3D", amber: "#F6B45A", coral: "#FF6B6B", sky: "#7DC4FF",
};

export function PageHead({ title, sub, action }) {
  return (
    <div style={{ display: "flex", alignItems: "flex-end", justifyContent: "space-between", marginBottom: 20 }}>
      <div>
        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 700, color: "#1b2230" }}>{title}</h1>
        {sub && <p style={{ margin: "4px 0 0", color: "#6b7688", fontSize: 13.5 }}>{sub}</p>}
      </div>
      {action}
    </div>
  );
}

export function Card({ children, style }) {
  return (
    <div style={{ background: "#fff", border: "1px solid #e6e9f0", borderRadius: 14, padding: 18, ...style }}>
      {children}
    </div>
  );
}

const badgeColors = {
  active: ["#16361b", "#C8FF3D"], approved: ["#16302f", "#7DC4FF"],
  pending: ["#2e2713", "#F6B45A"], expired: ["#321717", "#FF6B6B"],
  rejected: ["#321717", "#FF6B6B"], paused: ["#2a2f3a", "#93A1B8"],
};
export function Badge({ status }) {
  const [bg, fg] = badgeColors[status] || ["#eef1f6", "#6b7688"];
  return (
    <span style={{ background: bg, color: fg, fontSize: 11.5, fontWeight: 700, padding: "3px 9px", borderRadius: 999, textTransform: "capitalize" }}>
      {status}
    </span>
  );
}

export function Btn({ children, onClick, tone = "primary", disabled, type = "button", small }) {
  const tones = {
    primary: { bg: C.ink, fg: "#fff" },
    lime: { bg: C.lime, fg: "#0E1726" },
    ghost: { bg: "transparent", fg: "#1b2230", border: "1px solid #d4d9e3" },
    danger: { bg: "#fff", fg: "#c0392b", border: "1px solid #e4b7b1" },
  };
  const t = tones[tone] || tones.primary;
  return (
    <button type={type} onClick={onClick} disabled={disabled}
      style={{
        background: t.bg, color: t.fg, border: t.border || "none",
        padding: small ? "6px 12px" : "9px 16px", borderRadius: 10, cursor: disabled ? "not-allowed" : "pointer",
        fontSize: 13.5, fontWeight: 600, opacity: disabled ? 0.55 : 1,
      }}>
      {children}
    </button>
  );
}

export function Field({ label, children }) {
  return (
    <label style={{ display: "block", marginBottom: 12 }}>
      <span style={{ display: "block", fontSize: 12.5, fontWeight: 600, color: "#55606f", marginBottom: 5 }}>{label}</span>
      {children}
    </label>
  );
}

export const inputStyle = {
  width: "100%", padding: "9px 11px", borderRadius: 9, border: "1px solid #d4d9e3",
  fontSize: 13.5, boxSizing: "border-box", background: "#fff", color: "#1b2230",
};

/**
 * SearchSelect — a typeable dropdown (combobox).
 * Type to filter the full list; click or Enter to pick.
 *
 * props:
 *   value      currently selected option value ('' for none)
 *   onChange   (newValue) => void
 *   options    [{ value, label, hint? }]  — hint renders dim, right-aligned
 *   placeholder
 *   allowNew   true = free text is a valid value (used for categories);
 *              committed lowercase/trimmed on Enter or blur
 *   disabled
 */
export function SearchSelect({ value, onChange, options = [], placeholder, allowNew = false, disabled = false }) {
  const [open, setOpen] = React.useState(false);
  const [q, setQ] = React.useState("");
  const [hi, setHi] = React.useState(0);
  const boxRef = React.useRef(null);

  const selected = options.find((o) => o.value === value);
  const shown = open ? q : (selected ? selected.label : (value || ""));

  const filtered = React.useMemo(() => {
    const needle = q.trim().toLowerCase();
    if (!needle) return options;
    return options.filter((o) =>
      o.label.toLowerCase().includes(needle) ||
      String(o.value).toLowerCase().includes(needle) ||
      (o.hint || "").toLowerCase().includes(needle));
  }, [q, options]);

  // close on outside click (mousedown fires before the input loses focus)
  React.useEffect(() => {
    if (!open) return;
    const away = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) close(); };
    document.addEventListener("mousedown", away);
    return () => document.removeEventListener("mousedown", away);
  });

  function commitNew() {
    const v = q.trim().toLowerCase();
    if (v) onChange(v);
  }
  function close() {
    if (allowNew && q.trim()) commitNew();
    setOpen(false); setQ("");
  }
  function pick(v) { onChange(v); setOpen(false); setQ(""); }

  function onKey(e) {
    if (!open) return;
    if (e.key === "ArrowDown") { e.preventDefault(); setHi((h) => Math.min(h + 1, filtered.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setHi((h) => Math.max(h - 1, 0)); }
    else if (e.key === "Enter") {
      e.preventDefault();
      if (filtered[hi]) pick(filtered[hi].value);
      else if (allowNew) { commitNew(); setOpen(false); setQ(""); }
    }
    else if (e.key === "Escape") { setOpen(false); setQ(""); }
  }

  return (
    <div ref={boxRef} style={{ position: "relative" }}>
      <input
        style={inputStyle}
        disabled={disabled}
        placeholder={placeholder}
        value={shown}
        onFocus={() => { setOpen(true); setQ(""); setHi(0); }}
        onChange={(e) => { setOpen(true); setQ(e.target.value); setHi(0); }}
        onKeyDown={onKey}
      />
      {open && (
        <div style={{
          position: "absolute", top: "calc(100% + 4px)", left: 0, right: 0, zIndex: 60,
          background: "#fff", border: "1px solid #d4d9e3", borderRadius: 9,
          maxHeight: 230, overflowY: "auto", boxShadow: "0 8px 24px rgba(14,23,38,0.12)",
        }}>
          {filtered.length === 0 && !allowNew && (
            <div style={{ padding: "10px 12px", fontSize: 12.5, color: "#9aa3b2" }}>No matches.</div>
          )}
          {filtered.length === 0 && allowNew && q.trim() && (
            <div onMouseDown={() => { commitNew(); setOpen(false); setQ(""); }}
              style={{ padding: "9px 12px", fontSize: 13, cursor: "pointer", color: "#1b2230" }}>
              Use “<strong>{q.trim().toLowerCase()}</strong>”
            </div>
          )}
          {filtered.map((o, i) => (
            <div key={o.value}
              onMouseDown={() => pick(o.value)}
              onMouseEnter={() => setHi(i)}
              style={{
                display: "flex", justifyContent: "space-between", gap: 10, alignItems: "baseline",
                padding: "9px 12px", fontSize: 13, cursor: "pointer",
                background: i === hi ? "#f2f4f9" : "#fff",
                fontWeight: o.value === value ? 700 : 400,
              }}>
              <span>{o.label}</span>
              {o.hint && <span style={{ fontSize: 11.5, color: "#9aa3b2", whiteSpace: "nowrap" }}>{o.hint}</span>}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

export function Modal({ title, children, onClose }) {
  return (
    <div onClick={onClose} style={{ position: "fixed", inset: 0, background: "rgba(14,23,38,0.55)", display: "flex", alignItems: "center", justifyContent: "center", padding: 20, zIndex: 50 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: "#fff", borderRadius: 16, width: "100%", maxWidth: 480, maxHeight: "88vh", overflow: "auto", padding: 22 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 16 }}>
          <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700 }}>{title}</h3>
          <button onClick={onClose} style={{ background: "none", border: "none", fontSize: 20, cursor: "pointer", color: "#9aa3b2" }}>×</button>
        </div>
        {children}
      </div>
    </div>
  );
}

export function Empty({ msg }) {
  return <div style={{ padding: "28px 16px", textAlign: "center", color: "#9aa3b2", fontSize: 13.5 }}>{msg}</div>;
}

export function Spinner({ msg = "Loading…" }) {
  return <div style={{ padding: "28px 16px", textAlign: "center", color: "#9aa3b2", fontSize: 13.5 }}>{msg}</div>;
}

export function ErrorNote({ error }) {
  if (!error) return null;
  return (
    <div style={{ background: "#fdecea", color: "#c0392b", border: "1px solid #f3c6bf", padding: "10px 14px", borderRadius: 10, fontSize: 13, marginBottom: 14 }}>
      {String(error.message || error)}
    </div>
  );
}

export function Stub({ title, note }) {
  return (
    <div>
      <PageHead title={title} />
      <Card style={{ textAlign: "center", padding: 40 }}>
        <div style={{ fontSize: 32, marginBottom: 10 }}>🚧</div>
        <div style={{ fontWeight: 600, color: "#1b2230", marginBottom: 6 }}>Not wired yet</div>
        <div style={{ color: "#6b7688", fontSize: 13.5, maxWidth: 420, margin: "0 auto" }}>
          {note || "This screen's backend endpoint hasn't been built. It's stubbed so the navigation is complete."}
        </div>
      </Card>
    </div>
  );
}

// days until a date (negative if past)
export function daysUntil(iso) {
  if (!iso) return null;
  const ms = new Date(iso).getTime() - Date.now();
  return Math.ceil(ms / 86400000);
}
export function fmtDate(iso) {
  if (!iso) return "—";
  try { return new Date(iso).toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" }); }
  catch { return iso; }
}

// public URL for a hosted storefront, given its slug
export function storeUrl(slug) {
  const tmpl = import.meta.env.VITE_STORE_BASE_URL || "http://localhost:5175/?store={slug}";
  return tmpl.replace("{slug}", slug);
}
