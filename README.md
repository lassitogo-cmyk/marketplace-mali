# Marketplace Mali

Marché multi-vendeurs de Bamako : site web accessible depuis n'importe quel
téléphone, API Laravel, abonnements vendeurs payés par Orange Money.

**En ligne :** https://marketplace-mali.onrender.com/marche.html

## Rôles

| Rôle | Accès | Création du compte |
| --- | --- | --- |
| Client | Marché, panier, commandes, signalements | Inscription libre |
| Vendeur | Espace boutique (commandes, produits, abonnement) | Par le responsable uniquement |
| Livreur | Espace livraisons | Par le responsable uniquement |
| Responsable | Panneau de contrôle complet | Compte privé |

Chaque rôle est redirigé vers son interface et ne peut pas en sortir.
L'abonnement vendeur est de **10 000 FCFA / 30 jours**, payé par Orange Money
sur le compte du responsable, qui active ou désactive le compte.

## Fonctionnalités

- Catalogue avec photos multiples, variantes couleur/taille et galerie.
- Panier et commande : adresse, téléphone, paiement Orange Money (le client
  déclare l'ID de transaction, le vendeur confirme).
- Circuit complet : `en_attente` → `confirmee` → `en_livraison` → `livree`
  (ou `annulee`, avec restitution automatique du stock).
- Notifications **application + e-mail + SMS** : nouvelle commande, paiement,
  confirmation, livraison, annulation, signalements.
- **Signalement client → responsable** : le client écrit depuis sa commande,
  le responsable reçoit tout de suite (app + e-mail + SMS) et le vendeur
  concerné reçoit un SMS d'alerte. Le responsable peut répondre au client
  et/ou au vendeur, annuler la commande, bloquer le vendeur ou marquer le
  dossier traité. La réponse s'affiche chez le client et le vendeur.
- Compte personnel : nom, e-mail, téléphone, changement de mot de passe,
  alertes, installation de l'application (PWA).
- Responsable : statistiques, recherche et filtre par rôle, réinitialisation
  de mot de passe, blocage/déblocage, visibilité des produits.
- PWA installable (manifest + service worker), photos stockées en base.

## Comptes de démonstration

| Rôle | E-mail | Mot de passe |
| --- | --- | --- |
| Client | `client@demo.mali` | privé (non publié) |
| Vendeuse (Boutique Sabali) | `fatou@demo.mali` | privé (non publié) |
| Vendeur (Wax & Pagne) | `moussa@demo.mali` | privé (non publié) |
| Responsable | privé | privé |

> Les mots de passe de ces comptes ne sont **jamais écrits dans le dépôt**
> (public). Ils sont communiqués en privé et peuvent être changés à tout
> moment. En local, `php artisan db:seed` génère un mot de passe aléatoire
> affiché une seule fois, ou utilise la variable `DEMO_PASSWORD`.
> Les comptes client/vendeurs sont des comptes de **test** : changez leur
> mot de passe ou supprimez-les avant d'ouvrir le marché au public.

## Développement local

Prérequis : PHP 8.4+, Composer, MySQL (XAMPP) ou PostgreSQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve        # http://127.0.0.1:8000/marche.html
```

Base locale MySQL : `marketplace_mali`, utilisateur `marketplace` /
`mali2026`. Les données de démonstration sont dans
`database/seeders/marketplace-data.json` (import unique :
`php artisan marketplace:import --if-empty`).

## Déploiement

Le dépôt est prêt pour **Render** (Docker, plan gratuit) + **Neon**
(PostgreSQL gratuit) :

1. Créer un projet Neon et copier la chaîne
   `postgresql://…?sslmode=require`.
2. Sur Render : *New → Blueprint*, choisir ce dépôt, renseigner `DB_URL`
   et `APP_KEY` (`php artisan key:generate --show`).
3. Le conteneur applique les migrations et importe les données au premier
   démarrage (`docker/entrypoint.sh`).

Variables d'environnement principales :

```text
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # privée, jamais dans le dépôt
APP_URL=https://marketplace-mali.onrender.com
DB_CONNECTION=pgsql
DB_URL=postgresql://…?sslmode=require
MAIL_MAILER=smtp        # log par défaut
SMS_DRIVER=log          # orange si contrat Orange SMS API
ORANGE_SMS_CLIENT_ID=
ORANGE_SMS_CLIENT_SECRET=
```

> Render gratuit : le service s'endort après 15 min sans visite, la première
> ouverture suivante prend ~50 s. Les données restent dans Neon.

## Sécurité

Limitation de débit (connexion, inscription, commandes, photos), jetons
Sanctum expirant après 30 jours, CSP stricte, `X-Frame-Options: DENY`,
HSTS, `nosniff`, `APP_DEBUG=false`, versions PHP/Apache masquées, uploads
limités aux images JPG/PNG/WEBP (4 Mo), vérification du rôle et de la
propriété des données à chaque requête, journalisation des échecs de
connexion (sans mots de passe).

## Structure

```text
app/Http/Controllers/Api/   API (auth, produits, commandes, vendeur, livreur, admin)
app/Models/                 User, Store, Product, Order, Report, Notification, Media…
app/Services/               Notifier (app/e-mail/SMS), Sms, Orange Money
public/marche.html          Application web complète (une seule page)
public/sw.js                Service worker PWA
database/migrations/        Schéma de la base
database/seeders/           Données du marché (JSON)
docker/                     Configuration Apache/PHP de production
routes/api.php              Toutes les routes de l'API
```
