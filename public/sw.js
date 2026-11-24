// Helpigo service worker: makes the app installable and shows an offline page.
// Pages are always fetched from the network (task status must be live); only static assets are cached.
'use strict';

var CACHE = 'helpigo-v1';
var OFFLINE = './offline';

self.addEventListener('install', function (event) {
    event.waitUntil(caches.open(CACHE).then(function (cache) {
        return cache.addAll([OFFLINE, './assets/css/app.css', './assets/js/app.js', './assets/images/icon.svg']);
    }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
    event.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (key) { return key !== CACHE; }).map(function (key) { return caches.delete(key); }));
    }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) {
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(function () { return caches.match(OFFLINE); }));
        return;
    }
    if (/\/assets\//.test(request.url)) {
        // Assets carry a ?v= version, so a cached copy is always the right one.
        event.respondWith(caches.match(request).then(function (hit) {
            return hit || fetch(request).then(function (response) {
                var copy = response.clone();
                caches.open(CACHE).then(function (cache) { cache.put(request, copy); });
                return response;
            });
        }));
    }
});
