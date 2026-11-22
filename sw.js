const CACHE_NAME = "trip-maps-v1";
const APP_SHELL = [
  "/trip-maps/",
  "/trip-maps/index.html",
  "/trip-maps/manifest.json",
  "/trip-maps/icon.svg",
  "/trip-maps/icon-512.png"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  // Network-first for dynamic KMZ files and map tiles; cache fallback for app shell
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});
