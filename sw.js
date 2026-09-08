/**
 * Deliberately minimal — a service worker with a fetch handler is
 * required for Chrome/Edge to consider this site installable at all,
 * but this one does NOT cache anything. Every request just passes
 * straight through to the network, exactly as if this file didn't
 * exist. Given how many real stale-cache incidents this exact project
 * has already worked through (CDN caching, browser caching, phone
 * carrier caching), the service worker itself should never become
 * one more place a code update can get invisibly stuck.
 *
 * If offline support is ever genuinely wanted later, that's a
 * deliberate future addition — not something to bolt on quietly here.
 */

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    event.respondWith(fetch(event.request));
});