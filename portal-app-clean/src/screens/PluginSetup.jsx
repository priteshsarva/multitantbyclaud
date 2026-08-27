import React, { useEffect, useState } from "react";
import { Copy, Check } from "lucide-react";
import { api } from "../api.js";
import { PageHead, Card, Btn, Spinner, ErrorNote, Empty } from "../ui.jsx";

export default function PluginSetup() {
  const [enr, setEnr] = useState(null);
  const [error, setError] = useState(null);
  const [copied, setCopied] = useState(null);

  useEffect(() => {
    api.enrollments().then((r) => setEnr(r.enrollments || [])).catch(setError);
  }, []);

  function copy(k) { navigator.clipboard?.writeText(k); setCopied(k); setTimeout(() => setCopied(null), 1400); }

  return (
    <div>
      <PageHead title="Plugin setup" sub="Install the Server Products plugin, then paste your site's key." />
      <Card style={{ marginBottom: 18 }}>
        <ol style={{ margin: 0, paddingLeft: 18, color: "#42505f", fontSize: 13.5, lineHeight: 1.9 }}>
          <li>In WordPress: Plugins → Add New → Upload Plugin → choose <code>server-products.zip</code> → activate.</li>
          <li>Open the <strong>Server Products</strong> menu in the WP sidebar.</li>
          <li>Paste the enrollment key for that site (below) and save.</li>
          <li>Set your margin tiers, then click <strong>Start auto-sync</strong>.</li>
        </ol>
      </Card>

      <ErrorNote error={error} />
      {!enr ? <Spinner /> : enr.length === 0 ? <Card><Empty msg="No sites yet." /></Card> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
          {enr.map((e) => (
            <Card key={e.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
              <div>
                <div style={{ fontWeight: 700, fontSize: 14 }}>{e.domain}</div>
                <code style={{ fontSize: 12.5, color: "#42505f" }}>{e.enrollment_key}</code>
              </div>
              <Btn small tone="ghost" onClick={() => copy(e.enrollment_key)}>
                {copied === e.enrollment_key ? <Check size={14} /> : <Copy size={14} />} Copy key
              </Btn>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
