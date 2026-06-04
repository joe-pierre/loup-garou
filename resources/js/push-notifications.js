/**
 * push-notifications.js — Enregistrement service worker + souscription WebPush
 *
 * Appeler registerPush() une fois l'utilisateur authentifié.
 * La clé VAPID publique est injectée depuis la meta tag :
 *   <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
 */

export async function registerPush() {
    if (! ('serviceWorker' in navigator) || ! ('PushManager' in window)) {
        return;
    }

    try {
        const registration = await navigator.serviceWorker.register('/sw.js');

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            return;
        }

        const vapidKey = document.querySelector('meta[name="vapid-public-key"]')?.content;
        if (! vapidKey) {
            return;
        }

        const existingSubscription = await registration.pushManager.getSubscription();
        if (existingSubscription) {
            return;
        }

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly:      true,
            applicationServerKey: urlBase64ToUint8Array(vapidKey),
        });

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        await fetch('/push/subscriptions', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-TOKEN':     csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(subscription.toJSON()),
        });
    } catch (err) {
        console.warn('[Push] Impossible d\'enregistrer les notifications push :', err);
    }
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}
