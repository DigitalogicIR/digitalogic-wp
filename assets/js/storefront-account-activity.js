/* Canonical account invalidations. This adapter never submits a cart or checkout form. */
(function (root, factory) {
	'use strict';
	const api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	else api.start(root);
})(typeof window === 'undefined' ? globalThis : window, function () {
	'use strict';
	const scopes = new Set(['cart', 'favorites', 'history']);
	const limit = 2 * 1024 * 1024;

	function sameOrigin(value, location) {
		try {
			const url = new URL(value, location.href);
			return url.origin === location.origin && url.protocol === 'https:' && !url.username && !url.password ? url : null;
		} catch (_) { return null; }
	}

	function refreshUrl(location) {
		const url = sameOrigin(location.href, location);
		if (!url || /\/(?:checkout|order-pay|order-received)\/?/.test(url.pathname)) return null;
		for (const key of [...url.searchParams.keys()]) {
			// Never replay a Woo action, nonce, callback or arbitrary third-party query.
			if (/^(?:add-to-cart|remove_item|undo_item|wc-ajax|action|_wpnonce|pay_for_order)$/i.test(key)) return null;
			if (!/^(?:paged|product-page|orderby|s|post_type|min_price|max_price|stock_status|per_page|shop_view|per_row|filter_[a-z0-9_-]+|query_type_[a-z0-9_-]+)$/.test(key)) url.searchParams.delete(key);
		}
		url.hash = '';
		return url;
	}

	function safeEventScope(event) {
		const match = /^(cart|favorites|history)\.updated$/.exec(String(event?.name || ''));
		return match ? match[1] : null;
	}

	function hasEdit(node, document, dirty) {
		if (!node || node.contains(document.activeElement)) return true;
		return [...node.querySelectorAll('input,select,textarea,[contenteditable]')].some((field) => {
			if (dirty.has(field)) return true;
			if (field.tagName === 'SELECT') return [...field.options].some((option) => option.selected !== option.defaultSelected);
			if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
			return 'defaultValue' in field && field.value !== field.defaultValue;
		});
	}

	function recentShelves(document) {
		return [...document.querySelectorAll('.wd-products-element')].filter((node) => {
			const products = node.querySelector('.products[data-atts],.wd-carousel[data-atts]');
			try { return JSON.parse(products?.getAttribute('data-atts') || '{}').post_type === 'recently_viewed'; }
			catch (_) { return false; }
		});
	}

	async function readBounded(response) {
		if (Number(response.headers.get('Content-Length') || 0) > limit) throw new Error('response_bound');
		if (!response.body?.getReader) {
			const text = await response.text();
			if (new TextEncoder().encode(text).length > limit) throw new Error('response_bound');
			return text;
		}
		const reader = response.body.getReader();
		const decoder = new TextDecoder();
		let size = 0;
		let text = '';
		try {
			while (true) {
				const item = await reader.read();
				if (item.done) break;
				size += item.value.byteLength;
				if (size > limit) throw new Error('response_bound');
				text += decoder.decode(item.value, {stream: true});
			}
			return text + decoder.decode();
		} finally { await reader.cancel().catch(() => {}); }
	}

	function start(window) {
		const config = window.DigitalogicAccountActivity;
		const document = window.document;
		if (!config || !document || !window.fetch || typeof config.nonce !== 'string' || !/^[a-f0-9]{32}$/.test(config.channel || '')) return;
		const historyUrl = sameOrigin(config.historyUrl, window.location);
		const eventsUrl = sameOrigin(config.eventsUrl, window.location);
		if (!historyUrl || !eventsUrl) return;
		const dirty = new WeakSet();
		const pending = new Set();
		const tab = window.crypto?.randomUUID?.() || String(Date.now()) + '-' + String(Math.random());
		const leaseKey = 'digitalogic-account-events:' + config.channel;
		const channel = window.BroadcastChannel ? new window.BroadcastChannel(leaseKey) : null;
		let stopped = false, running = false, rendering = false, recorded = false, cursor = 0, timer = 0, requestController = null;
		const visible = () => document.visibilityState === 'visible';
		const checkout = () => document.body?.classList.contains('woocommerce-checkout') || !!document.querySelector('form.checkout');
		const schedule = (delay) => { window.clearTimeout(timer); if (!stopped) timer = window.setTimeout(tick, delay); };
		const stop = () => { stopped = true; window.clearTimeout(timer); requestController?.abort(); releaseLease(); channel?.close(); };
		function ownLease() {
			try {
				const prior = JSON.parse(window.localStorage.getItem(leaseKey) || 'null');
				if (prior && prior.tab !== tab && prior.until > Date.now()) return false;
				window.localStorage.setItem(leaseKey, JSON.stringify({tab, until: Date.now() + 15000}));
				return JSON.parse(window.localStorage.getItem(leaseKey) || 'null')?.tab === tab;
			} catch (_) { return true; }
		}
		function releaseLease() {
			try { if (JSON.parse(window.localStorage.getItem(leaseKey) || 'null')?.tab === tab) window.localStorage.removeItem(leaseKey); } catch (_) {}
		}
		async function fetchTimed(url, options, consume) {
			const controller = new window.AbortController();
			const signal = options.signal;
			const abort = () => controller.abort();
			signal?.addEventListener('abort', abort, {once: true});
			if (signal?.aborted) controller.abort();
			const timeout = window.setTimeout(abort, 25000);
			try { return await consume(await window.fetch(url, {...options, signal: controller.signal})); }
			finally { window.clearTimeout(timeout); signal?.removeEventListener('abort', abort); }
		}
		async function api(url, body, signal) {
			return fetchTimed(url, {method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal, headers: {'X-WP-Nonce': config.nonce, ...(body ? {'Content-Type': 'application/json'} : {})}, ...(body ? {body: JSON.stringify(body)} : {})}, async (response) => {
				if (response.status === 401 || response.status === 403) { stop(); throw new Error('session_changed'); }
				if (!response.ok) throw new Error('request_failed');
				return JSON.parse(await readBounded(response));
			});
		}
		function notify(scope, broadcast = true) {
			if (!scopes.has(scope) || stopped) return;
			window.dispatchEvent(new window.CustomEvent('digitalogic:' + scope + '-updated', {detail: {scope}}));
			pending.add(scope);
			if (broadcast) channel?.postMessage({scope});
			Promise.resolve().then(() => { void render(); });
		}
		function replace(oldNode, newNode) {
			if (!oldNode || !newNode || hasEdit(oldNode, document, dirty)) return false;
			newNode.querySelectorAll('script').forEach((node) => node.remove());
			oldNode.replaceWith(document.importNode(newNode, true));
			return true;
		}
		async function render() {
			if (rendering || stopped || !visible() || !pending.size || checkout()) return;
			rendering = true;
			const active = new Set(pending);
			active.forEach((scope) => pending.delete(scope));
			try {
				if (active.has('history')) await api(historyUrl); // Response updates canonical provider cookies first.
				const url = refreshUrl(window.location);
				if (!url) return;
				const html = await fetchTimed(url, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'text/html'}}, async (response) => {
					if (!response.ok || !sameOrigin(response.url, window.location) || new URL(response.url).pathname !== url.pathname || !response.headers.get('Content-Type')?.includes('text/html')) throw new Error('invalid_html');
					return readBounded(response);
				});
				const fresh = new window.DOMParser().parseFromString(html, 'text/html');
				await api(historyUrl); // Recheck the captured WP cookie/nonce after the HTML read before rendering.
				if (stopped || checkout()) return;
				let updated = false;
				if (active.has('history')) {
					const oldShelves = recentShelves(document), newShelves = recentShelves(fresh);
					if (oldShelves.length === newShelves.length) oldShelves.forEach((node, index) => {
						if (!replace(node, newShelves[index])) pending.add('history'); else updated = true;
					});
				}
				if (active.has('favorites')) {
					for (const selector of ['.wd-wishlist-content', '.wd-header-wishlist']) {
						const oldNodes = [...document.querySelectorAll(selector)], newNodes = [...fresh.querySelectorAll(selector)];
						if (oldNodes.length === newNodes.length) oldNodes.forEach((node, index) => {
							if (!replace(node, newNodes[index])) pending.add('favorites'); else updated = true;
						});
					}
					const newButtons = [...fresh.querySelectorAll('.wd-wishlist-btn')];
					for (const node of document.querySelectorAll('.wd-wishlist-btn')) {
						const id = node.querySelector('[data-product-id]')?.getAttribute('data-product-id');
						if (!/^[1-9][0-9]*$/.test(id || '')) continue;
						const other = newButtons.find((candidate) => candidate.className === node.className && candidate.querySelector('[data-product-id]')?.getAttribute('data-product-id') === id);
						if (other && !replace(node, other)) pending.add('favorites');
					}
				}
				if (active.has('cart') && document.body.classList.contains('woocommerce-cart')) {
					const oldCart = document.querySelector('.woocommerce-cart-form')?.closest('.woocommerce') || document.querySelector('.woocommerce');
					const newCart = fresh.querySelector('.woocommerce-cart-form')?.closest('.woocommerce') || fresh.querySelector('.woocommerce');
					if (!replace(oldCart, newCart)) pending.add('cart');
				}
				if (active.has('cart')) {
					// Woo's wc_fragment_refresh AND updated_wc_div would launch another,
					// unfenced request. Use only this already session-verified HTML instead.
					for (const selector of ['.wd-header-cart', '.widget_shopping_cart_content']) {
						const outerOnly = (node) => selector !== '.widget_shopping_cart_content' || !node.closest('.wd-header-cart');
						const oldNodes = [...document.querySelectorAll(selector)].filter(outerOnly);
						const newNodes = [...fresh.querySelectorAll(selector)].filter(outerOnly);
						if (oldNodes.length !== newNodes.length) { pending.add('cart'); continue; }
						oldNodes.forEach((node, index) => { if (!replace(node, newNodes[index])) pending.add('cart'); });
					}
				}
				if (updated && window.jQuery) {
					window.jQuery(document).trigger('wdRecentlyViewedProductLoaded').trigger('wood-images-loaded');
				}
			} catch (_) { active.forEach((scope) => pending.add(scope)); }
			finally { rendering = false; }
		}
		async function tick() {
			if (stopped || running) return;
			if (!visible()) { releaseLease(); schedule(2000); return; }
			running = true;
			requestController = new window.AbortController();
			try {
				if (!recorded && Number.isInteger(config.productId) && config.productId > 0) {
					recorded = true;
					try { await api(historyUrl, {product_id: config.productId}, requestController.signal); notify('history'); } catch (_) {}
				}
				if (stopped || !visible()) return;
				void render();
				if (!ownLease()) { schedule(4000); return; }
				const url = new URL(eventsUrl); url.searchParams.set('cursor', String(cursor)); url.searchParams.set('wait', '10');
				const result = await api(url, null, requestController.signal);
				if (!Number.isSafeInteger(result.cursor) || result.cursor < 0 || !Array.isArray(result.events) || result.events.length > 100) throw new Error('invalid_events');
				cursor = result.cursor;
				if (result.reset === true) scopes.forEach((scope) => notify(scope));
				else for (const event of result.events) { const scope = safeEventScope(event); if (scope) notify(scope); }
				schedule(result.has_more === true ? 0 : 250);
			} catch (_) { schedule(3000); }
			finally { running = false; requestController = null; }
		}
		channel?.addEventListener('message', (message) => { if (scopes.has(message.data?.scope)) notify(message.data.scope, false); });
		document.addEventListener('input', (event) => { if (event.target?.matches?.('input,select,textarea,[contenteditable]')) dirty.add(event.target); });
		document.addEventListener('change', (event) => { if (event.target?.matches?.('input,select,textarea,[contenteditable]')) dirty.add(event.target); });
		document.addEventListener('focusout', () => window.setTimeout(() => { void render(); }, 100));
		document.addEventListener('visibilitychange', () => { if (!visible()) { requestController?.abort(); releaseLease(); } else schedule(0); });
		window.addEventListener('pagehide', () => { window.clearTimeout(timer); requestController?.abort(); releaseLease(); });
		window.addEventListener('pageshow', () => schedule(0));
		if (window.jQuery) window.jQuery(document.body).on('updated_wc_div wc_fragments_refreshed', () => { void render(); });
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => schedule(0), {once: true}); else schedule(0);
	}
	return {start, sameOrigin, refreshUrl, safeEventScope, hasEdit, recentShelves, readBounded};
});
