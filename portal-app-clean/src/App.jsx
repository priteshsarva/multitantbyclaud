import React, { useEffect, useState } from "react";
import {
  LayoutDashboard, Globe, Tags, PlusCircle, Plug, Inbox, ShieldCheck, Users,
  Megaphone, ScrollText, BarChart3, Search, Store, Receipt, LogOut, KeyRound, Database,
  Mail, CreditCard, LayoutTemplate, ClipboardList, Bell,
} from "lucide-react";
import { api, getToken, setToken } from "./api.js";
import { C, Stub } from "./ui.jsx";
import Login from "./screens/Login.jsx";
import Dashboard from "./screens/Dashboard.jsx";
import MySites from "./screens/MySites.jsx";
import RequestSite from "./screens/RequestSite.jsx";
import PluginSetup from "./screens/PluginSetup.jsx";
import AdminSources from "./screens/AdminSources.jsx";
import AdminQueue from "./screens/AdminQueue.jsx";
import AdminEnrollments from "./screens/AdminEnrollments.jsx";
import Billing from "./screens/Billing.jsx";
import AdminClients from "./screens/AdminClients.jsx";
import AdminEmailSettings from "./screens/AdminEmailSettings.jsx";
import AdminPaymentSettings from "./screens/AdminPaymentSettings.jsx";
import MyStorefronts from "./screens/MyStorefronts.jsx";
import MyOrders from "./screens/MyOrders.jsx";
import CatalogueSearch from "./screens/CatalogueSearch.jsx";
import Notifications, { lastSeen } from "./screens/Notifications.jsx";
import AdminHostedSites from "./screens/AdminHostedSites.jsx";
import AdminOrders from "./screens/AdminOrders.jsx";

const clientNav = [
  ["dashboard", "Dashboard", LayoutDashboard],
  ["sites", "My sites", Globe],
  ["storefront", "My storefront", LayoutTemplate],
  ["orders", "Orders", ClipboardList],
  ["request", "Request a site", PlusCircle],
  ["plugin", "Plugin setup", Plug],
  ["analytics", "Store analytics", BarChart3],
  ["search", "Catalogue search", Search],
  ["promote", "Promote", Megaphone],
  ["wholesale", "Sell wholesale", Store],
  ["billing", "Billing", Receipt],
  ["notifications", "Notifications", Bell],
];
const adminNav = [
  ["queue", "Approval queue", Inbox],
  ["sources", "Sources", Database],
  ["enrollAdmin", "Enrollments", ShieldCheck],
  ["hostedSites", "Storefronts", LayoutTemplate],
  ["hostedOrders", "Storefront orders", ClipboardList],
  ["users", "Clients", Users],
  ["email", "Email (SMTP)", Mail],
  ["payments", "Payments", CreditCard],
  ["announce", "Announcements", Megaphone],
  ["audit", "Audit log", ScrollText],
  ["notifications", "Notifications", Bell],
];

export default function App() {
  const [user, setUser] = useState(null);
  const [booting, setBooting] = useState(true);
  const [nav, setNav] = useState("dashboard");
  const [unread, setUnread] = useState(0);

  // unread notification count (vs the client-side last-seen timestamp)
  useEffect(() => {
    if (!user) return;
    api.notifications().then((r) => {
      const seen = lastSeen();
      setUnread((r.notifications || []).filter((n) => !seen || new Date(n.created_at) > new Date(seen)).length);
    }).catch(() => {});
  }, [user]);
  useEffect(() => { if (nav === "notifications") setUnread(0); }, [nav]);

  // restore session from a stored token
  useEffect(() => {
    if (!getToken()) { setBooting(false); return; }
    api.me()
      .then((r) => { const u = r.user || r; setUser(u); setNav(u.role === "admin" ? "queue" : "dashboard"); })
      .catch(() => setToken(""))
      .finally(() => setBooting(false));
  }, []);

  if (booting) return <div style={{ minHeight: "100vh", background: C.ink }} />;
  if (!user) return <Login onLogin={(u) => { setUser(u); setNav(u.role === "admin" ? "queue" : "dashboard"); }} />;

  const role = user.role === "admin" ? "admin" : "client";
  const items = role === "admin" ? adminNav : clientNav;

  function signOut() { setToken(""); setUser(null); }

  function render() {
    if (role === "client") {
      switch (nav) {
        case "dashboard": return <Dashboard me={user} />;
        case "sites": return <MySites />;
        case "storefront": return <MyStorefronts />;
        case "orders": return <MyOrders />;
        case "request": return <RequestSite />;
        case "plugin": return <PluginSetup />;
        case "analytics": return <Stub title="Store analytics" note="Needs the plugin page-view tracker + an analytics endpoint." />;
        case "search": return <CatalogueSearch />;
        case "promote": return <Stub title="Promote" note="Ad marketplace — not built yet." />;
        case "wholesale": return <Stub title="Sell wholesale" note="Phase 2 — wholesale listings." />;
        case "billing": return <Billing />;
        case "notifications": return <Notifications />;
        default: return null;
      }
    }
    switch (nav) {
      case "queue": return <AdminQueue />;
      case "sources": return <AdminSources />;
      case "enrollAdmin": return <AdminEnrollments />;
      case "hostedSites": return <AdminHostedSites />;
      case "hostedOrders": return <AdminOrders />;
      case "users": return <AdminClients />;
      case "email": return <AdminEmailSettings />;
      case "payments": return <AdminPaymentSettings />;
      case "announce": return <Stub title="Announcements" note="Needs an announcements endpoint." />;
      case "audit": return <Stub title="Audit log" note="The audit_log table exists; needs a read endpoint." />;
      case "notifications": return <Notifications />;
      default: return null;
    }
  }

  return (
    <div style={{ background: C.paper, minHeight: "100vh", color: "#1b2230", fontFamily: "ui-sans-serif, system-ui, sans-serif" }}>
      <div style={{ display: "flex", minHeight: "100vh" }}>
        <aside style={{ width: 244, background: C.ink, color: C.text, padding: "22px 14px", display: "flex", flexDirection: "column", flexShrink: 0 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 9, padding: "0 6px" }}>
            <div style={{ width: 30, height: 30, borderRadius: 8, background: C.lime, display: "flex", alignItems: "center", justifyContent: "center" }}>
              <KeyRound size={17} color={C.ink} />
            </div>
            <strong style={{ fontSize: 15.5 }}>Server Products</strong>
          </div>

          <nav style={{ marginTop: 24, display: "flex", flexDirection: "column", gap: 3 }}>
            {items.map(([key, label, Icon]) => {
              const on = nav === key;
              return (
                <button key={key} onClick={() => setNav(key)}
                  style={{ display: "flex", alignItems: "center", gap: 11, padding: "9px 12px", borderRadius: 10, border: "none", cursor: "pointer", textAlign: "left", fontSize: 13.5, fontWeight: on ? 600 : 500, background: on ? C.surface2 : "transparent", color: on ? "#fff" : C.dim, borderLeft: on ? `2px solid ${C.lime}` : "2px solid transparent" }}>
                  <Icon size={16} color={on ? C.lime : C.dim} /> {label}
                  {key === "notifications" && unread > 0 && (
                    <span style={{ marginLeft: "auto", background: C.lime, color: C.ink, fontSize: 11, fontWeight: 800, minWidth: 18, height: 18, borderRadius: 999, display: "flex", alignItems: "center", justifyContent: "center", padding: "0 5px" }}>{unread}</span>
                  )}
                </button>
              );
            })}
          </nav>

          <div style={{ marginTop: "auto", paddingTop: 14, borderTop: `1px solid ${C.line}` }}>
            <div style={{ fontSize: 12, color: C.dim, padding: "0 6px 10px" }}>
              {user.email} · <span style={{ color: role === "admin" ? C.lime : C.sky }}>{role}</span>
            </div>
            <button onClick={signOut} style={{ display: "flex", alignItems: "center", gap: 10, background: "none", border: "none", color: C.dim, cursor: "pointer", fontSize: 13, padding: "0 6px" }}>
              <LogOut size={16} /> Sign out
            </button>
          </div>
        </aside>

        <main style={{ flex: 1, minWidth: 0, padding: "26px 32px" }}>
          {render()}
        </main>
      </div>
    </div>
  );
}
