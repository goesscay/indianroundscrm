/* Indian Rounds CRM service worker.
   - Never touches API/auth calls or authenticated pages (data stays live and private).
   - Static build assets and icons are cached; page navigations go to the network and show /offline.html if it is unreachable. */
const CACHE = "ir-crm-v3";
const PRECACHE = ["/offline.html", "/manifest.json", "/icons/icon-192.png", "/icons/icon-512.png"];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/sanctum/") || url.pathname.startsWith("/broadcasting/")) return;

  if (req.mode === "navigate") {
    event.respondWith(fetch(req).catch(() => caches.match("/offline.html")));
    return;
  }

  // Hashed build output never changes for a given URL, so cache-first is safe; icons and images likewise.
  if (url.pathname.startsWith("/_next/static/") || url.pathname.startsWith("/icons/") || /\.(png|jpg|svg|ico|woff2?)$/.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res && res.status === 200 && res.type === "basic") { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
        return res;
      }))
    );
  }
});
