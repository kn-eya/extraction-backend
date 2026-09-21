Readme · MD
Belgium Business Data Extractor

Plateforme de recherche d'entreprises belges basée sur OpenStreetMap, Elasticsearch et une interface React moderne.

Afficher l'image Afficher l'image Afficher l'image Afficher l'image Afficher l'image Afficher l'image

🎯 Fonctionnalités
🔍 Recherche avancée : secteur, ville, province, région, code postal, rayon
📊 Dashboard : statistiques, évolution des imports, répartition
🗺️ Cartographie interactive
📧 Notifications : import, recherche, nouveaux contacts
🔐 Authentification : Sanctum + 2FA (TOTP)
📦 Export : Excel, CSV, JSON
🌐 Enrichissement automatique : téléphone, email, site web, réseaux sociaux
🔁 Détection de doublons
🏗️ Architecture
text
Overpass API ──▶ extraction:test ──▶ PostgreSQL
                                        │
                                        ├──▶ Photon (fill-cities)
                                        │
                                        └──▶ Elasticsearch (index-all)
                                                  │
                                                  ▼
                                           Frontend React
Composant	Technologie
Backend	Laravel 12 + PHP 8.4
Frontend	React 18 + TypeScript + Tailwind
Base de données	PostgreSQL 18
Recherche	Elasticsearch 8.11
Cache / Queue	Redis
Conteneurisation	Docker + Sail
📦 Prérequis
Docker Desktop (avec WSL2 sur Windows)
PHP 8.4 (pour composer install depuis l'hôte)
Composer 2.x
Node.js 20+ et npm
🚀 Installation
1. Cloner le projet
bash
git clone https://github.com/kn-eya/extraction-backend.git extraction_backend
git clone https://github.com/kn-eya/extraction-frontend.git extraction_frontend
cd extraction_backend
2. Configurer l'environnement
bash
cp .env.example .env

Éditez ensuite .env avec vos identifiants (base de données, mail, Elasticsearch). Ne commitez jamais ce fichier.

Port du frontend : le conteneur Sail peut occuper le port 5173 sur l'hôte. Si Vite bascule sur 5174, définissez VITE_PORT=5180 dans .env (ou retirez ce mapping dans compose.yaml) et vérifiez que config/cors.php et SANCTUM_STATEFUL_DOMAINS contiennent l'origine réellement utilisée par le frontend.

3. Démarrer Docker
bash
docker compose up -d
4. Installer les dépendances (depuis l'hôte, plus rapide)
bash
composer install
5. Migrer la base et créer l'administrateur
bash
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test php artisan tinker

Dans Tinker (choisissez votre propre mot de passe, ne gardez pas de valeur par défaut) :

php
\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);

$user = \App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@example.com',
    'password' => \Illuminate\Support\Facades\Hash::make('MOT_DE_PASSE_FORT'),
]);
$user->assignRole('admin');
exit;
6. Créer l'index Elasticsearch
bash
docker compose exec laravel.test php artisan elasticsearch:create-index
7. Lancer le frontend
bash
cd ../extraction_frontend
npm install
npm run dev

Le frontend est alors disponible sur http://localhost:5173 (ou le port indiqué par Vite) et le backend sur http://localhost.

🧪 Peuplement de la base
bash
# 1. Extraire les entreprises depuis Overpass (2-4h)
docker compose exec laravel.test php artisan extraction:test --sans-nominatim

# 2. Remplir villes/provinces/CP (Photon, ~1h)
docker compose exec laravel.test php artisan extraction:fill-cities --limit=100000

# 3. Remplir contacts (Overpass, 1-2h)
docker compose exec laravel.test php artisan extraction:fill-contacts

# 4. Nettoyer les entreprises hors Belgique
docker compose exec laravel.test php artisan extraction:clean-foreign

# 5. Corriger les régions
docker compose exec laravel.test php artisan extraction:fix-regions

# 6. Calculer le statut "ouvert"
docker compose exec laravel.test php artisan companies:refresh-open-status

# 7. Indexer dans Elasticsearch
docker compose exec laravel.test php artisan elasticsearch:index-all
📖 Documentation
Ressource	Contenu
docs/TECHNIQUE.md	Architecture, base de données, commandes
docs/SOURCES_DONNEES.md	Overpass, Photon, Nominatim, Brevo
docs/MANUEL.md	Manuel utilisateur
docs/DEPLOIEMENT.md	Guide de déploiement
Swagger UI · api-docs.json	API REST (Swagger UI : en local, backend démarré)

Pour régénérer la documentation de l'API :

bash
docker compose exec laravel.test php artisan l5-swagger:generate
🔐 Sécurité
Authentification : Laravel Sanctum
2FA : Google2FA (TOTP)
Rate limiting : 5 tentatives/min sur /login
Hachage des mots de passe : Bcrypt
CORS configuré
Validation des entrées via Form Requests
Protection XSS : échappement natif de React côté frontend, validation côté API
Journalisation des actions : spatie/laravel-activitylog
🧪 Tests
bash
docker compose exec laravel.test php artisan test
docker compose exec laravel.test php artisan test --coverage
📊 Stack technique détaillée
Composant	Version	Rôle
Laravel	12.x	Framework backend
PHP	8.4	Langage backend
PostgreSQL	18	Base de données
Elasticsearch	8.11	Moteur de recherche
Redis	8.x	Cache, queue, sessions
React	18.x	Frontend
Tailwind CSS	4.x	Styles
Vite	voir package.json	Build frontend
⚖️ Données, licences et conformité
OpenStreetMap : les données sont issues d'OpenStreetMap et publiées sous licence ODbL. Attribution obligatoire : © OpenStreetMap contributors. La licence MIT ci-dessous ne couvre que le code source, pas les données.
Nominatim / Photon : respecter les conditions d'usage (Nominatim : 1 requête/seconde maximum, User-Agent identifiable ; Photon public : usage raisonnable).
RGPD : la base peut contenir des coordonnées professionnelles (téléphone, email). Leur usage doit respecter le RGPD (finalité, information des personnes, droit d'opposition et de suppression).
📄 Licence

MIT (code source uniquement, voir la section « Données, licences et conformité »)