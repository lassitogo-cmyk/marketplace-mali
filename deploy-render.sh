#!/usr/bin/env bash
# Déploiement Render de Marketplace Mali via l'API.
# Usage :
#   export RENDER_API_KEY=rnd_...
#   export DB_URL='postgresql://...:5432/neondb?sslmode=require'
#   ./deploy-render.sh
#
# Le service est créé depuis le dépôt public GitHub, en Docker, plan gratuit.
set -euo pipefail

: "${RENDER_API_KEY:?RENDER_API_KEY manquant (https://dashboard.render.com/u/settings#api-keys)}"
: "${DB_URL:?DB_URL manquant (chaîne Neon avec :5432 et ?sslmode=require)}"

API="https://api.render.com/v1"
REPO="https://github.com/lassitogo-cmyk/marketplace-mali"
NAME="marketplace-mali"
REGION="frankfurt"

hdr=(-H "Authorization: Bearer $RENDER_API_KEY" -H "Accept: application/json" -H "Content-Type: application/json")

echo "→ Recherche du compte Render…"
OWNER=$(curl -fsS "${hdr[@]}" "$API/owners" | jq -r '.[0].owner.id // .[0].id // empty')
[ -n "$OWNER" ] || { echo "Impossible de lire le compte Render (clé invalide ?)"; exit 1; }
echo "  compte : $OWNER"

echo "→ Création du service Docker gratuit…"
BODY=$(jq -n --arg owner "$OWNER" --arg name "$NAME" --arg repo "$REPO" --arg region "$REGION" '{
  type: "web_service",
  name: $name,
  ownerId: $owner,
  repo: $repo,
  branch: "main",
  autoDeploy: "yes",
  serviceDetails: {
    env: "docker",
    plan: "free",
    region: $region,
    healthCheckPath: "/marche.html",
    dockerfilePath: "./Dockerfile",
    dockerContext: "."
  }
}')

RESP=$(curl -fsS -X POST "${hdr[@]}" -d "$BODY" "$API/services" || true)
SID=$(echo "$RESP" | jq -r '.service.id // empty')
if [ -z "$SID" ]; then
  echo "Échec de création :"; echo "$RESP" | jq . 2>/dev/null || echo "$RESP"
  echo "→ Si le service existe déjà, récupère son identifiant :"
  curl -fsS "${hdr[@]}" "$API/services?name=$NAME" | jq -r '.[] | "\(.service.id)  \(.service.name)"'
  exit 1
fi
echo "  service : $SID"

echo "→ Variables d'environnement…"
ENVVARS=$(jq -n --arg db "$DB_URL" '[
  {key:"APP_NAME",value:"Marketplace Mali"},
  {key:"APP_ENV",value:"production"},
  {key:"APP_DEBUG",value:"false"},
  {key:"APP_KEY",value:"base64:zWaDbNE4OJ2epywJvCM18pHVgO/pnhtmfvPDW+0rV2Q="},
  {key:"APP_URL",value:"https://marketplace-mali.onrender.com"},
  {key:"APP_LOCALE",value:"fr"},
  {key:"LOG_CHANNEL",value:"stderr"},
  {key:"LOG_LEVEL",value:"warning"},
  {key:"DB_CONNECTION",value:"pgsql"},
  {key:"DB_URL",value:$db},
  {key:"SESSION_DRIVER",value:"database"},
  {key:"CACHE_STORE",value:"database"},
  {key:"QUEUE_CONNECTION",value:"database"},
  {key:"FILESYSTEM_DISK",value:"local"},
  {key:"BCRYPT_ROUNDS",value:"12"},
  {key:"MAIL_MAILER",value:"log"},
  {key:"SMS_DRIVER",value:"log"}
]')

curl -fsS -X PUT "${hdr[@]}" -d "$ENVVARS" "$API/services/$SID/env-vars" >/dev/null
echo "  variables enregistrées"

echo "→ Déploiement…"
curl -fsS -X POST "${hdr[@]}" -d '{"clearCache":"do_not_clear"}' "$API/services/$SID/deploys" \
  | jq -r '"  déploiement : \(.id)  statut : \(.status)"'

echo
echo "URL : https://marketplace-mali.onrender.com/marche.html"
echo "Suivi : https://dashboard.render.com/web/$SID"
