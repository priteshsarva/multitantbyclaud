// Central API client. Token is kept in localStorage; every call attaches it.
const BASE = import.meta.env.VITE_API_URL || "http://localhost:3002";

const tokenKey = "spp_portal_token";
export const getToken = () => localStorage.getItem(tokenKey) || "";
export const setToken = (t) => (t ? localStorage.setItem(tokenKey, t) : localStorage.removeItem(tokenKey));

async function req(path, { method = "GET", body, auth = true } = {}) {
  const headers = { "Content-Type": "application/json" };
  if (auth && getToken()) headers.Authorization = `Bearer ${getToken()}`;

  const res = await fetch(BASE + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  let data = null;
  try { data = await res.json(); } catch { /* empty body */ }

  if (!res.ok) {
    const msg = (data && (data.error || data.message)) || `HTTP ${res.status}`;
    const err = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return data;
}

export const api = {
  base: BASE,

  // ---- auth ----
  login: (email, password) => req("/auth/login", { method: "POST", auth: false, body: { email, password } }),
  signup: (data) => req("/auth/signup", { method: "POST", auth: false, body: data }),
  publicPlans: () => req("/auth/plans", { auth: false }),
  me: () => req("/auth/me"),

  // ---- client: billing / invoices ----
  invoices: () => req("/portal/invoices"),
  payInvoice: (id) => req(`/portal/invoices/${id}/pay`, { method: "POST" }),
  verifyInvoice: (id) => req(`/portal/invoices/${id}/verify`),
  paymentInfo: () => req("/portal/payment-info", { auth: false }),

  // ---- admin: clients + settings ----
  adminUsers: (q) => req(`/portal/admin/users${q ? `?q=${encodeURIComponent(q)}` : ""}`),
  adminSetUserPassword: (id, password) => req(`/portal/admin/users/${id}/set-password`, { method: "POST", body: password ? { password } : {} }),
  adminGetSmtp: () => req("/portal/admin/settings/smtp"),
  adminSaveSmtp: (cfg) => req("/portal/admin/settings/smtp", { method: "PUT", body: cfg }),
  adminTestSmtp: (to) => req("/portal/admin/settings/smtp/test", { method: "POST", body: { to } }),
  adminGetPayment: () => req("/portal/admin/settings/payment"),
  adminSaveProvider: (id, cfg) => req(`/portal/admin/settings/payment/provider/${id}`, { method: "PUT", body: cfg }),
  adminSetActiveProvider: (id) => req("/portal/admin/settings/payment/active", { method: "PUT", body: { id } }),

  // ---- client: enrollments ----
  enrollments: () => req("/portal/enrollments"),
  createShop: (shop_url, plan_id) => req("/portal/shops", { method: "POST", body: { shop_url, plan_id } }),
  createEnrollment: (domain, source_id, categories) =>
    req("/portal/enrollments", { method: "POST", body: { domain, source_id, categories } }),

  // ---- client: sources on an enrollment (multi-source) ----
  enrollmentSources: (id) => req(`/portal/enrollments/${id}/sources`),
  addEnrollmentSource: (id, source_id, categories) =>
    req(`/portal/enrollments/${id}/sources`, { method: "POST", body: { source_id, categories } }),
  setEnrollmentSourceCats: (id, sourceId, categories) =>
    req(`/portal/enrollments/${id}/sources/${sourceId}`, { method: "PATCH", body: { categories } }),
  removeEnrollmentSource: (id, sourceId) =>
    req(`/portal/enrollments/${id}/sources/${sourceId}`, { method: "DELETE" }),

  // ---- client: sources + categories to pick from ----
  sources: () => req("/portal/sources"),
  sourceCategories: (sourceId) => req(`/portal/sources/${sourceId}/categories`),

  // ---- client: scrape requests ----
  myScrapeRequests: () => req("/portal/scrape-requests"),
  createScrapeRequest: (site_url, category, enrollment_id) =>
    req("/portal/scrape-requests", { method: "POST", body: { site_url, category, enrollment_id } }),

  // ---- admin: sources ----
  adminSources: () => req("/portal/admin/sources"),
  adminSourceCategories: (id) => req(`/portal/admin/sources/${id}/categories`),
  adminToggleCategory: (id, cat_name, enabled) =>
    req(`/portal/admin/sources/${id}/categories`, { method: "PATCH", body: { cat_name, enabled } }),
  adminRefreshCategories: (id, mode) =>
    req(`/portal/admin/sources/${id}/categories/refresh`, { method: "POST", body: { mode } }),
  adminRefreshAllCategories: () =>
    req("/portal/admin/sources/categories/refresh-all", { method: "POST" }),
  adminSetSourceStatus: (id, status) =>
    req(`/portal/admin/sources/${id}`, { method: "PATCH", body: { status } }),

  // ---- admin: enrollment + scrape-request queues ----
  adminEnrollments: (status) => req(`/portal/admin/enrollments${status ? `?status=${status}` : ""}`),
  adminEnrollmentOverview: () => req("/portal/admin/enrollment-overview"),
  adminVerifyDomain: (id) => req(`/portal/admin/enrollments/${id}/verify-domain`, { method: "POST" }),
  enrollmentCategoryMap: (id) => req(`/portal/enrollments/${id}/category-map`),
  saveEnrollmentCategoryMap: (id, mappings) => req(`/portal/enrollments/${id}/category-map`, { method: "POST", body: { mappings } }),
  adminApproveEnrollment: (id) => req(`/portal/admin/enrollments/${id}/approve`, { method: "POST" }),
  adminRejectEnrollment: (id) => req(`/portal/admin/enrollments/${id}/reject`, { method: "POST" }),
  adminActivateEnrollment: (id) => req(`/portal/admin/enrollments/${id}/activate`, { method: "POST" }),
  adminScrapeRequests: (status) => req(`/portal/admin/scrape-requests${status ? `?status=${status}` : ""}`),
  adminApproveScrapeRequest: (id, body) =>
    req(`/portal/admin/scrape-requests/${id}/approve`, { method: "POST", body }),
  adminResolveScrapeRequest: (id) =>
    req(`/portal/admin/scrape-requests/${id}/resolve`, { method: "POST" }),
  adminRejectScrapeRequest: (id) =>
    req(`/portal/admin/scrape-requests/${id}/reject`, { method: "POST" }),

  // ---- client: hosted storefronts ----
  myHostedSites: () => req("/portal/hosted-sites"),
  hostedOrders: (params) => req(`/portal/hosted-orders${params ? `?${new URLSearchParams(params)}` : ""}`),
  hostedAnalytics: () => req("/portal/hosted-analytics"),
  catalogue: (params) => req(`/portal/catalogue${params ? `?${new URLSearchParams(params)}` : ""}`),
  createHostedSite: (store_name, slug) => req("/portal/hosted-sites", { method: "POST", body: { store_name, slug } }),
  hostedSiteSettings: (id) => req(`/portal/hosted-sites/${id}/settings`),
  saveHostedSiteSettings: (id, settings) => req(`/portal/hosted-sites/${id}/settings`, { method: "PUT", body: settings }),
  hostedSiteOrders: (id, status) => req(`/portal/hosted-sites/${id}/orders${status ? `?status=${status}` : ""}`),
  hostedSiteOrder: (id, orderId) => req(`/portal/hosted-sites/${id}/orders/${orderId}`),
  updateHostedSiteOrderStatus: (id, orderId, status) =>
    req(`/portal/hosted-sites/${id}/orders/${orderId}`, { method: "PATCH", body: { status } }),
  hostedSiteSources: (id) => req(`/portal/hosted-sites/${id}/sources`),
  hostedSiteBrands: (id, category) => req(`/portal/hosted-sites/${id}/brands?category=${encodeURIComponent(category)}`),
  saveHostedSiteSources: (id, source_ids) =>
    req(`/portal/hosted-sites/${id}/sources`, { method: "PUT", body: { source_ids } }),
  hostedSitePresets: () => req("/portal/hosted-sites/presets"),
  applyHostedSitePreset: (id, preset) =>
    req(`/portal/hosted-sites/${id}/presets/${preset}`, { method: "POST" }),
  setHostedSiteCustomDomain: (id, domain) =>
    req(`/portal/hosted-sites/${id}/custom-domain`, { method: "PUT", body: { domain } }),
  verifyHostedSiteDomain: (id) =>
    req(`/portal/hosted-sites/${id}/verify-domain`, { method: "POST" }),
  adminVerifyCustomDomain: (id) =>
    req(`/portal/admin/hosted-sites/${id}/verify-custom-domain`, { method: "POST" }),

  // ---- admin: hosted storefronts ----
  adminHostedSites: () => req("/portal/admin/hosted-sites"),
  adminUpdateHostedSite: (id, body) => req(`/portal/admin/hosted-sites/${id}`, { method: "PATCH", body }),
  adminDeleteHostedSite: (id) => req(`/portal/admin/hosted-sites/${id}`, { method: "DELETE" }),
  adminOrders: (params) => req(`/portal/admin/orders${params ? `?${new URLSearchParams(params)}` : ""}`),
  adminOrder: (id) => req(`/portal/admin/orders/${id}`),
};
