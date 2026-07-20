// Service Worker devre disi - kendini kaldirir
self.addEventListener('install', function(e) {
    self.skipWaiting();
});

self.addEventListener('activate', function(e) {
    // Tum cache'leri sil
    e.waitUntil(
        caches.keys().then(function(names) {
            return Promise.all(
                names.map(function(name) {
                    return caches.delete(name);
                })
            );
        }).then(function() {
            // Kendini kaldir
            return self.registration.unregister();
        }).then(function() {
            // Tum client'lara haber ver
            return self.clients.matchAll();
        }).then(function(clients) {
            clients.forEach(function(client) {
                client.navigate(client.url);
            });
        })
    );
});
