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
