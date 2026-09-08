const CACHE_NAME = 'gabnex-static-v1';
const STATIC_PATHS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/favicon.svg',
    '/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_PATHS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== CACHE_NAME)
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
        return;
    }

    const isStatic =
        url.pathname.startsWith('/build/') ||
        STATIC_PATHS.includes(url.pathname);

    if (!isStatic) {
        return;
    }

    event.respondWith(
        caches.match(request).then(
            (cached) =>
                cached ||
                fetch(request).then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches
                            .open(CACHE_NAME)
                            .then((cache) => cache.put(request, copy));
                    }

                    return response;
                }),
        ),
    );
});
