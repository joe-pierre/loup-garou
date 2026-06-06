# SETUP.md — Loup-Garou Undu

## 1. Variables `.env` requises

### Laravel de base
```env
APP_NAME="Loup-Garou Undu"
APP_KEY=base64:...          # généré par php artisan key:generate
APP_URL=https://ton-domaine.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=loup_garou
DB_USERNAME=...
DB_PASSWORD=...
```

### Google OAuth
```env
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://ton-domaine.com/auth/google/callback
```

### Laravel Reverb (interne — serveur)
```env
REVERB_APP_ID=loup-garou-local
REVERB_APP_KEY=loup-garou-key-local
REVERB_APP_SECRET=loup-garou-secret-local
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Vite / public (client WebSocket via Nginx reverse proxy)
```env
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=ton-domaine.com   # domaine public, sans https://
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

### WebPush VAPID
```env
VAPID_PUBLIC_KEY=...    # généré par php artisan webpush:vapid
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:admin@ton-domaine.com
```

### Queue
```env
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

---

## 2. Supervisor (prod)

Deux workers à configurer dans `/etc/supervisor/conf.d/loup-garou.conf` :

```ini
[program:loup-garou-reverb]
command=php /var/www/loup-garou/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/loup-garou
user=www-data
numprocs=1
autostart=true
autorestart=true
stderr_logfile=/var/log/supervisor/reverb.err.log
stdout_logfile=/var/log/supervisor/reverb.out.log

[program:loup-garou-worker]
command=php /var/www/loup-garou/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
directory=/var/www/loup-garou
user=www-data
numprocs=2
autostart=true
autorestart=true
stderr_logfile=/var/log/supervisor/worker.err.log
stdout_logfile=/var/log/supervisor/worker.out.log
```

Après modification :
```bash
supervisorctl reread
supervisorctl update
supervisorctl start loup-garou-reverb loup-garou-worker:*
```

---

## 3. Nginx (prod)

Reverse proxy 443 → 8080 pour Reverb (WebSocket). Config minimale :

```nginx
server {
    listen 443 ssl;
    server_name ton-domaine.com;

    # SSL — à adapter selon votre setup (Let's Encrypt, etc.)
    ssl_certificate     /etc/letsencrypt/live/ton-domaine.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ton-domaine.com/privkey.pem;

    root /var/www/loup-garou/public;
    index index.php;

    # Application Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Reverb WebSocket — reverse proxy vers le port interne 8080
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;

        # Headers WebSocket requis
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }
}

# Redirection HTTP → HTTPS
server {
    listen 80;
    server_name ton-domaine.com;
    return 301 https://$host$request_uri;
}
```

---

## 4. Workflow local

```bash
# Copier et configurer l'environnement
cp .env.example .env
php artisan key:generate
# Remplir les variables DB, Google OAuth, Reverb (valeurs locales)

# Installer les dépendances
composer install
npm install

# Base de données
php artisan migrate:fresh --seed

# Générer les clés VAPID (WebPush)
php artisan webpush:vapid
# Copier les clés générées dans .env (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY)

# Lancer les 4 terminaux
# Terminal 1 — serveur Laravel
php artisan serve

# Terminal 2 — WebSocket Reverb
php artisan reverb:start

# Terminal 3 — Queue worker
php artisan queue:work

# Terminal 4 — Vite (assets)
npm run dev
```

Si le script `build_local.sh` est présent à la racine du projet, il encapsule
les étapes de build (npm run build + optimisations cache) pour un déploiement
local rapide sans les 4 terminaux séparés.

```bash
# Build assets pour un test sans npm run dev
bash build_local.sh
```

### Vérification rapide
```bash
php artisan test                    # tests unitaires + feature
php artisan reverb:start --debug    # logs WebSocket en temps réel
```