{{-- PWA — manifest + meta iOS (Safari ne lit pas manifest.json pour l'installation) --}}
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#111827">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Undu">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">

{{-- Enregistrement PWA du service worker — indépendant de registerPush() (resources/js/push-notifications.js),
     qui n'enregistre le même /sw.js que si la clé VAPID est présente. navigator.serviceWorker.register()
     est idempotent (même scope/script → même registration, pas de double instance) : voir DECISIONS.md
     "Enregistrement du service worker PWA indépendant du flux WebPush". --}}
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(err => {
            console.warn('[PWA] Échec enregistrement service worker :', err);
        });
    }
</script>
