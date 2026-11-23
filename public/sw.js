/* Marketplace Mali — service worker minimal.
   Permet l'installation sur l'écran d'accueil et affiche l'application
   même quand la connexion est momentanément coupée.
   Les appels /api/ ne sont jamais mis en cache. */
const CACHE = 'marketplace-mali-v12';
const SHELL = ['/marche.html', '/manifest.json', '/logo-96.png', '/logo.png', '/icon-192.png', '/icon-512.png'];

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE).then(cache => cache.addAll(SHELL)).catch(() => {})
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/media/')) return;

  // Application installée : on sert la page enregistrée tout de suite,
  // puis on la met à jour en arrière-plan pour la prochaine ouverture.
  if (request.mode === 'navigate' || url.pathname === '/marche.html'){
    event.respondWith((async () => {
      const cache = await caches.open(CACHE);
      const hit = await cache.match('/marche.html');
      const net = fetch(request)
        .then(response => {
          if (response && response.ok && response.type === 'basic') {
            cache.put('/marche.html', response.clone());
          }
          return response;
        })
        .catch(() => hit);
      return hit || net;
    })());
    return;
  }

  // Réseau d'abord : l'application reste toujours à jour.
  event.respondWith(
    fetch(request)
      .then(response => {
        if (response && response.ok && response.type === 'basic') {
          const copy = response.clone();
          caches.open(CACHE).then(cache => cache.put(request, copy)).catch(() => {});
        }
        return response;
      })
      .catch(() =>
        caches.match(request).then(hit => hit || caches.match('/marche.html'))
      )
  );
});
