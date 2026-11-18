/* global caches, self */
'use strict';

const cacheName = 'digitalogic-login-assets-v1';
const loginPath = new URL(self.registration.scope).pathname;
const supportedTypes = {
    js: /javascript|ecmascript/i,
    css: /^text\/css\b/i,
    woff: /font|octet-stream/i,
    woff2: /font|octet-stream/i,
    svg: /^image\/svg\+xml\b/i,
    webp: /^image\/webp\b/i,
    png: /^image\/png\b/i,
    jpg: /^image\/jpeg\b/i,
    jpeg: /^image\/jpeg\b/i,
    avif: /^image\/avif\b/i
};
const destinations = new Set(['script', 'style', 'font', 'image']);
const inflight = new Map();

function publicAsset(value) {
    if (typeof value !== 'string') return null;
    let url;
    try { url = new URL(value, self.location.origin); } catch (error) { return null; }
    if (url.origin !== self.location.origin || url.username || url.password || url.hash) return null;
    const directory = /^\/(?:wp-(?:includes|admin)\/(?:css|js|fonts)\/|wp-content\/(?:plugins|mu-plugins|themes|uploads)\/)/;
    if (!directory.test(url.pathname) || /%(?:2f|5c)/i.test(url.pathname)) return null;
    const extension = url.pathname.match(/\.([a-z0-9]+)$/i)?.[1].toLowerCase();
    if (!Object.hasOwn(supportedTypes, extension)) return null;
    if (url.search && !/^\?ver=[\w.-]+$/.test(url.search)) return null;
    return { url: url.href, type: supportedTypes[extension], versioned: Boolean(url.search),
        image: /^(?:svg|webp|png|jpe?g|avif)$/.test(extension) };
}

async function remember(cache, asset, response) {
    if (response.ok && !response.redirected && asset.type.test(response.headers.get('Content-Type') || '') &&
        !/\b(?:private|no-store)\b/i.test(response.headers.get('Cache-Control') || '') &&
        !response.headers.get('Vary')?.match(/(?:\*|\bcookie\b|\bauthorization\b)/i)) {
        await cache.put(asset.url, response.clone());
    }
}

function prefetch(cache, asset) {
    if (inflight.has(asset.url)) return inflight.get(asset.url);
    const task = (async () => {
        if (await cache.match(asset.url)) return;
        const response = await fetch(asset.url, { credentials: 'omit', redirect: 'error' });
        await remember(cache, asset, response);
    })().finally(() => inflight.delete(asset.url));
    inflight.set(asset.url, task);
    return task;
}

self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('message', event => {
    const clientUrl = event.source?.url && new URL(event.source.url);
    if (event.data?.type !== 'DIGITALOGIC_PREFETCH_LOGIN_ASSETS' ||
        !clientUrl || clientUrl.origin !== self.location.origin || clientUrl.pathname !== loginPath ||
        !Array.isArray(event.data.urls)) return;

    event.waitUntil((async () => {
        const cache = await caches.open(cacheName);
        const assets = Array.from(new Set(event.data.urls.slice(0, 100))).map(publicAsset).filter(Boolean);
        await Promise.allSettled(assets.map(asset => prefetch(cache, asset)));
        const entries = await cache.keys();
        await Promise.all(entries.slice(0, Math.max(0, entries.length - 120)).map(entry => cache.delete(entry)));
    })());
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const artwork = request.destination === '' && request.credentials === 'omit' && request.headers.get('Accept') === 'image/*';
    if (request.method !== 'GET' || (!artwork && !destinations.has(request.destination)) ||
        request.cache === 'reload' || request.cache === 'no-store') return;
    const asset = publicAsset(request.url);
    if (!asset || (artwork && !asset.image)) return;

    event.respondWith((async () => {
        const cache = await caches.open(cacheName).catch(() => null);
        const existing = await cache?.match(asset.url).catch(() => undefined);
        if (!existing && !artwork) return fetch(request);
        if (existing && asset.versioned) return existing;
        // Revalidate URLs without a content version through the browser HTTP cache.
        try {
            const response = await fetch(asset.url, { credentials: 'omit', redirect: 'error', cache: 'no-cache' });
            if (cache) {
                const saved = remember(cache, asset, response).catch(() => {});
                if (artwork) event.waitUntil(saved);
                else await saved;
            }
            return response;
        } catch (error) {
            if (existing) return existing;
            throw error;
        }
    })());
});
