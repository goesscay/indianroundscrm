/* Simple PWA service worker for Indian Rounds CRM - caches static assets for offline access */
const CACHE = "ir-crm-v2";
const APP_SHELL = ["/manifest.json", "/logo.png"];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const req = event.request;
  const url = new URL(req.url);
  // Never cache API / auth calls (need fresh data) or Next.js build output
  if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/sanctum/") || url.pathname.startsWith("/_next/")) return;
  if (req.method !== "GET" || req.mode === "navigate") return;

  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req).then((res) => {
        if (res && res.status === 200 && res.type === "basic") {
          const clone = res.clone();
          caches.open(CACHE).then((c) => c.put(req, clone));
        }
        return res;
      }).catch(() => cached);
      return cached || network;
    })
  );
});
