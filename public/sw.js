const STATIC_CACHE = 'undu-static-v1';

// Assets compilés Vite (CSS/JS/fonts) + icônes — cache-first, jamais les vues de jeu
const STATIC_PATH_PATTERNS = [/^\/build\//, /^\/icons\//, /\.(?:woff2?|ttf)$/];

// Jamais de cache sur le temps réel : broadcasting auth, état de partie, API
const NEVER_CACHE_PATTERNS = [/^\/broadcasting\/auth/, /^\/game\/[^/]+\/state/, /^\/api\//];

function isStaticAsset(url) {
    return STATIC_PATH_PATTERNS.some(pattern => pattern.test(url.pathname));
}

function isNeverCached(url) {
    return NEVER_CACHE_PATTERNS.some(pattern => pattern.test(url.pathname));
}

self.addEventListener('install', event => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(key => key !== STATIC_CACHE).map(key => caches.delete(key)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin || isNeverCached(url)) {
        return; // laisser le navigateur gérer nativement (pas de cache)
    }

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(cache =>
                cache.match(request).then(cached => cached ?? fetch(request).then(response => {
                    cache.put(request, response.clone());
                    return response;
                }))
            )
        );
        return;
    }

    // Routes dynamiques (vues de jeu) — network-first, pas de cache agressif
    event.respondWith(
        fetch(request).catch(() => caches.match(request))
    );
});

self.addEventListener('push', event => {
    const data = event.data ? event.data.json() : {};

    const title   = data.title  ?? 'Loup-Garou Undu';
    const options = {
        body:  data.body  ?? '',
        icon:  data.icon  ?? '/images/icon-192.png',
        badge: data.badge ?? '/images/badge-72.png',
        data:  { url: data.url ?? '/' },
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();

    const url = event.notification.data?.url ?? '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
            for (const client of windowClients) {
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
