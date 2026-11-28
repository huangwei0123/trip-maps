const CACHE_NAME = "photoonmaps-v8";

const APP_SHELL_ASSETS = [
  "./",
  "index.html",
  "viewer.html",
  "usage.html",
  "user-guide.html",
  "styles.css",
  "manifest.json",
  "js/app.js",
  "js/map.js",
  "js/kmz.js",
  "js/auth.js",
  "js/usage.js"
];

// 1. Pre-cache core HTML, CSS, and ES modules on install
self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL_ASSETS))
  );
  // Activate immediately without waiting for old tabs to close
  self.skipWaiting();
});

// 2. Delete outdated caches (e.g., v1, v2) on activation
self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME)
          .map((oldKey) => caches.delete(oldKey))
      )
    )
  );
  self.clients.claim();
});

// 3. Network-First for app files; bypass cache for PHP APIs & dynamic trips
self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;

  const url = new URL(req.url);

  // Only handle same-origin requests; let external map tiles & CDNs behave normally
  if (url.origin !== self.location.origin) return;

  // Never cache PHP endpoints or user-saved KMZ/trip files
  if (
    url.pathname.endsWith(".php") ||
    url.pathname.includes("/trip/") ||
    url.pathname.includes("/trips/")
  ) {
    return;
  }

  // Network-First: always fetch the newest file from HostGator, update cache,
  // and fall back to cache only if offline
  event.respondWith(
    fetch(req)
      .then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          const responseClone = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(req, responseClone);
          });
        }
        return networkResponse;
      })
      .catch(() => caches.match(req))
  );
});
