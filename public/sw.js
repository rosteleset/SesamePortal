var CACHE = 'artelmik-v3';
var STATIC_ASSETS = [
  '/assets/styles.css',
  '/assets/app.js',
  '/assets/favicon.svg',
  '/manifest.json'
];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(STATIC_ASSETS); }));
  self.skipWaiting();
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET') return;
  var url = new URL(e.request.url);
  if (url.origin !== self.location.origin) return;

  if (url.pathname === '/sw.js') {
    e.respondWith(fetch(e.request));
    return;
  }

  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/viewer/preview')) {
    e.respondWith(fetch(e.request));
    return;
  }

  if (url.pathname.startsWith('/assets/') || url.pathname === '/manifest.json') {
    e.respondWith(
      fetch(e.request).then(function (resp) {
        if (resp && resp.status === 200) {
          var clone = resp.clone();
          caches.open(CACHE).then(function (c) { c.put(e.request, clone); });
        }
        return resp;
      }).catch(function () {
        return caches.match(e.request);
      })
    );
    return;
  }

  e.respondWith(
    fetch(e.request).then(function (resp) {
      if (resp && resp.status === 200 && resp.type === 'basic') {
        var clone = resp.clone();
        caches.open(CACHE).then(function (c) { c.put(e.request, clone); });
      }
      return resp;
    }).catch(function () {
      return caches.match(e.request).then(function (cached) {
        return cached || caches.match('/') || new Response('Offline', { status: 503, statusText: 'Offline' });
      });
    })
  );
});