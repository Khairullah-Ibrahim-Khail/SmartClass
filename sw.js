/* SmartClass service worker — keeps the app installable and fast, and shows a friendly page when the connection is gone.
 * It NEVER stores pages, forms, API answers or anything signed-in (private data stays out of the phone's cache):
 *   - styles, scripts, fonts, icons (static files)  → served from the cache while a fresh copy loads in the background
 *   - page visits                                    → always from the network; only when offline: the offline page
 *   - everything else (POST, JSON, downloads)        → untouched
 * Scope = the folder this file is in (the app's folder), so it works under any domain / sub-folder. */
const VERSION = 'sc-static-v2';
const BASE = new URL('./', self.location).pathname;          // e.g. /myProject/SmartClass/
const OFFLINE = BASE + 'offline.php';
const STATIC = /\.(css|js|woff2?|ttf|png|jpg|jpeg|svg|webp|ico)(\?|$)/i;

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll([OFFLINE, BASE + 'assets/img/icon-192.png']); }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) { return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); })); }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
  const req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin || !url.pathname.startsWith(BASE)) return;
  if (url.pathname.indexOf('/assets/vendor/pyodide') !== -1) return;       // the 13 MB Python engine: the browser's own cache handles it
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE); }));
    return;
  }
  if (url.pathname.indexOf('/assets/vendor/') !== -1 && STATIC.test(url.pathname)) {   // big libraries (diagrams, charts): kept after the first use and NOT downloaded again in the background (they never change; saves mobile data)
    e.respondWith(caches.open(VERSION).then(function (cache) {
      return cache.match(req).then(function (hit) {
        return hit || fetch(req).then(function (res) { if (res && res.ok) cache.put(req, res.clone()); return res; });
      });
    }));
    return;
  }
  if (STATIC.test(url.pathname) && url.pathname.indexOf('/pages/') === -1) {
    e.respondWith(caches.open(VERSION).then(function (cache) {
      return cache.match(req).then(function (hit) {
        const fresh = fetch(req).then(function (res) { if (res && res.ok) cache.put(req, res.clone()); return res; }).catch(function () { return hit; });
        return hit || fresh;
      });
    }));
  }
});

/* Phone notifications (web push). The push is empty on purpose: ask SmartClass for the newest unread notification with the
 * person's own sign-in and show it. Signed out (or offline) → a generic line, never anyone's private text. */
self.addEventListener('push', function (e) {
  e.waitUntil((function () {
    return fetch(BASE + 'pages/notifications/latest.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { return (r.headers.get('content-type') || '').indexOf('json') !== -1 ? r.json() : {}; })
      .catch(function () { return {}; })
      .then(function (d) {
        return self.registration.showNotification(d.title || 'SmartClass', {
          body: d.message || 'You have a new notification',
          icon: BASE + 'assets/img/icon-192.png', tag: 'sc-notification', renotify: true,
          data: { url: d.url || BASE + 'pages/notifications/index.php' }
        });
      });
  })());
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  const target = new URL((e.notification.data && e.notification.data.url) || BASE, self.location.origin);
  if (target.origin !== self.location.origin) return;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (const c of list) { if (c.url.indexOf(BASE) !== -1 && 'focus' in c) { c.navigate(target.href); return c.focus(); } }
    return self.clients.openWindow(target.href);
  }));
});
