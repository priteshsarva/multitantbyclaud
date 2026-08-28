import React, { useEffect, useState } from "react";
import { Bell } from "lucide-react";
import { api } from "../api.js";
import { PageHead, Card, Spinner, ErrorNote, Empty, fmtDate } from "../ui.jsx";

const SEEN_KEY = "spp_notif_seen";
export const markNotificationsSeen = () => { try { localStorage.setItem(SEEN_KEY, new Date().toISOString()); } catch { /* ignore */ } };
export const lastSeen = () => { try { return localStorage.getItem(SEEN_KEY) || ""; } catch { return ""; } };

export default function Notifications() {
  const [items, setItems] = useState(null);
  const [error, setError] = useState(null);
  const [seenBefore] = useState(lastSeen());

  useEffect(() => {
    api.notifications().then((r) => setItems(r.notifications || [])).catch(setError);
    markNotificationsSeen(); // opening the page clears the unread badge
  }, []);

  if (error) return (<div><PageHead title="Notifications" /><ErrorNote error={error} /></div>);
  if (!items) return (<div><PageHead title="Notifications" /><Spinner /></div>);

  return (
    <div>
      <PageHead title="Notifications" sub="Updates about your sources and categories." />
      {items.length === 0 ? <Card><Empty msg="Nothing yet." /></Card> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          {items.map((n) => {
            const unread = !seenBefore || new Date(n.created_at) > new Date(seenBefore);
            return (
              <Card key={n.id} style={{ display: "flex", gap: 12, alignItems: "flex-start", borderLeft: unread ? "3px solid #C8FF3D" : "3px solid transparent" }}>
                <div style={{ width: 34, height: 34, borderRadius: 8, background: "#f2f4f8", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
                  <Bell size={16} color="#6b7688" />
                </div>
                <div style={{ flex: 1 }}>
                  <div style={{ fontWeight: 700, fontSize: 13.5 }}>{n.title}</div>
                  {n.body && <div style={{ fontSize: 12.5, color: "#42505f", marginTop: 3 }}>{n.body}</div>}
                  <div style={{ fontSize: 11.5, color: "#9aa3b2", marginTop: 5 }}>{fmtDate(n.created_at)}{n.audience === "admin" ? " · admin" : ""}</div>
                </div>
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}
