#!/bin/bash
set -e

echo "🚀 Déploiement en cours..."

cd /var/www/loup-garou

echo "🔧 Mise en maintenance..."
php artisan down --retry=10

echo "📥 Pull du code..."
git pull origin main

echo "📦 Installation des dépendances PHP..."
composer install --optimize-autoloader --no-dev

echo "🎨 Build des assets..."
npm ci && npm run build

echo "🗄️ Migrations..."
php artisan queue:clear --queue=default
php artisan migrate --force

echo "🧹 Vidage et reconstruction des caches..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache
php artisan event:clear
php artisan event:cache

echo "🔄 Arrêt propre des workers et Reverb..."
# Demande aux workers de finir leur job courant avant de se relancer
php artisan queue:restart

# Redémarre Reverb et Supervisor après que les workers se soient arrêtés proprement
supervisorctl restart loup-garou-reverb
supervisorctl restart loup-garou-worker:*

echo "🟢 Remise en ligne..."
php artisan up

echo "✅ Déploiement terminé !"