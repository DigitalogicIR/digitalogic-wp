'use strict';
// Deterministic actual Chromium DOM acceptance. Every request is fulfilled locally;
// no WordPress customer, remote network, order, payment or notification is used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright-core');

const script = fs.readFileSync(path.join(__dirname, '../assets/js/storefront-account-activity.js'), 'utf8');
const origin = 'https://digitalogic.test';
const historyPath = '/wp-json/digitalogic/v1/account/recently-viewed';
const eventsPath = '/wp-json/digitalogic/v1/account/events';
const results = [];
const product = 123;
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function markup(version, bodyClass = '') {
	return `<!doctype html><html><body class="${bodyClass}">
		<div id="unrelated">unrelated-${version}</div>
		<div class="wd-header-cart"><span class="wd-tools-count">${version === 'old' ? 1 : 3}</span><div class="widget_shopping_cart_content" id="mini-cart">${version}-mini</div></div>
		<div class="widget_shopping_cart_content" id="side-mini-cart">${version}-side-mini</div>
		<div class="wd-products-element" id="recent"><div class="products" data-atts='{"post_type":"recently_viewed"}'><span>${version}-recent</span></div></div>
		<div class="wd-products-element" id="unrelated-products"><div class="products" data-atts='{"post_type":"products"}'>${version}-products</div></div>
		<div class="wd-wishlist-content">${version}-wishlist</div>
		<div class="wd-header-wishlist wd-with-count"><span class="wd-tools-count">${version === 'old' ? 0 : 1}</span></div>
		<div class="wd-wishlist-btn wd-action-btn wd-style-icon"><a data-product-id="${product}" class="${version === 'old' ? '' : 'added'}">${version === 'old' ? 'Add' : 'Remove'}</a></div>
		<div class="woocommerce"><form class="woocommerce-cart-form"><input aria-label="Quantity" name="quantity" value="${version === 'old' ? 1 : 3}"><span class="cart-total">${version}-total</span></form><div class="cart-collaterals">${version}-collateral</div></div>
		${bodyClass === 'woocommerce-checkout' ? `<form class="checkout"><input name="billing_address_1" value="${version}-address"></form>` : ''}
		${version === 'new' ? '<script>window.__unexpectedScriptExecuted = true;</script>' : ''}
	</body></html>`;
}

async function scenario(browser, options, check) {
	const context = await browser.newContext();
	const page = await context.newPage();
	const state = {requests: [], views: [], polls: 0, html: 0, history: 0, unfencedMiniCart: 0, served: false};
	let releasePost;
	const postGate = new Promise((resolve) => { releasePost = resolve; });
	const initial = markup('old', options.bodyClass);
	const fresh = markup('new', options.bodyClass);
	await context.route('**/*', async (route) => {
		const request = route.request();
		const url = new URL(request.url());
		state.requests.push({method: request.method(), path: url.pathname, nonce: request.headers()['x-wp-nonce'] || null});
		assert.equal(url.origin, origin, 'fixture blocks every foreign origin');
		if (url.searchParams.get('wc-ajax') === 'get_refreshed_fragments') {
			state.unfencedMiniCart++;
			return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({text: 'different-account-private-cart'})});
		}
		if (url.pathname === historyPath) {
			state.history++;
			assert.equal(request.headers()['x-wp-nonce'], 'fixture-wp-rest-nonce');
			if (request.method() === 'POST') {
				state.views.push(JSON.parse(request.postData()));
				if (options.holdPost) await postGate;
			}
			const reject = options.revokeAfterHtml && state.html > 0;
			return route.fulfill({status: reject ? 401 : 200, contentType: 'application/json', body: JSON.stringify(reject ? {code: 'website_session_required'} : {items: [], revision: 'fixture'})});
		}
		if (url.pathname === eventsPath) {
			state.polls++;
			assert.equal(request.method(), 'GET');
			assert.equal(request.headers()['x-wp-nonce'], 'fixture-wp-rest-nonce');
			if (state.served) {
				// Keep the next long-poll pending until page teardown, like a quiet server.
				await new Promise((resolve) => page.once('close', resolve));
				return route.abort().catch(() => {});
			}
			state.served = true;
			return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({events: (options.events || []).map((name, index) => ({id: index + 1, name})), cursor: 10, has_more: false, reset: options.reset === true})});
		}
		assert.equal(request.method(), 'GET', 'no cart/checkout submission is allowed');
		const documentRequest = request.isNavigationRequest();
		if (!documentRequest) state.html++;
		return route.fulfill({status: 200, contentType: 'text/html', body: documentRequest ? initial : fresh});
	});
	await page.goto(origin + (options.path || '/shop/'));
	await page.evaluate(({hidden, productId}) => {
		window.__visible = !hidden;
		Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => window.__visible ? 'visible' : 'hidden'});
		window.DigitalogicAccountActivity = {historyUrl: '/wp-json/digitalogic/v1/account/recently-viewed', eventsUrl: '/wp-json/digitalogic/v1/account/events', nonce: 'fixture-wp-rest-nonce', channel: 'a'.repeat(32), productId};
		window.__providerEvents = [];
		window.jQuery = () => {
			const api = {on: () => api, trigger: (name) => {
				window.__providerEvents.push(name);
				// Installed Woo handles BOTH events with its own unfenced AJAX request.
				if (name === 'wc_fragment_refresh' || name === 'updated_wc_div') {
					void fetch('/?wc-ajax=get_refreshed_fragments').then((response) => response.json()).then((data) => { document.querySelector('#mini-cart').textContent = data.text; });
				}
				return api;
			}};
			return api;
		};
	}, {hidden: options.hidden, productId: options.productId || 0});
	if (options.before) await options.before(page);
	await page.addScriptTag({content: script});
	try {
		await check({page, state, releasePost});
		assert.equal(state.requests.some((request) => request.method !== 'GET' && !(request.method === 'POST' && request.path === historyPath)), false, 'view is the only permitted mutation');
		assert.equal(state.unfencedMiniCart, 0, 'no provider request escapes the captured cookie/nonce boundary');
		assert.equal(await page.evaluate(() => !!window.__unexpectedScriptExecuted), false);
		results.push({name: options.name, passed: true});
	} finally {
		releasePost();
		await page.close();
		await context.close();
	}
}

(async () => {
	const browser = await chromium.launch({headless: true, ...(process.env.CHROME_PATH ? {executablePath: process.env.CHROME_PATH} : {})});
	try {
		await scenario(browser, {name: 'three account invalidations refresh only exact canonical fragments', bodyClass: 'woocommerce-cart', path: '/cart/', events: ['cart.updated', 'favorites.updated', 'history.updated']}, async ({page, state}) => {
			await page.waitForFunction(() => document.querySelector('.cart-total').textContent === 'new-total');
			assert.equal(await page.locator('#recent').textContent(), 'new-recent');
			assert.equal(await page.locator('.wd-wishlist-content').textContent(), 'new-wishlist');
			assert.equal(await page.locator('.wd-header-wishlist .wd-tools-count').textContent(), '1');
			assert.equal(await page.locator('.wd-wishlist-btn a').getAttribute('class'), 'added');
			assert.equal(await page.locator('.wd-wishlist-btn a').textContent(), 'Remove');
			assert.equal(await page.locator('.woocommerce-cart-form input').inputValue(), '3');
			assert.equal(await page.locator('#unrelated').textContent(), 'unrelated-old');
			assert.equal(await page.locator('#unrelated-products').textContent(), 'old-products');
			assert.equal(await page.locator('#mini-cart').textContent(), 'new-mini');
			assert.equal(await page.locator('#side-mini-cart').textContent(), 'new-side-mini');
			assert.equal(await page.locator('.wd-header-cart .wd-tools-count').textContent(), '3');
			const events = await page.evaluate(() => window.__providerEvents);
			assert.ok(!events.includes('wc_fragment_refresh') && !events.includes('updated_wc_div'));
			assert.ok(events.includes('wdRecentlyViewedProductLoaded'));
			assert.equal(state.html, 1, 'same batch coalesces into one read-only HTML refresh');
		});
		await scenario(browser, {name: 'edited cart survives inbound updates', bodyClass: 'woocommerce-cart', path: '/cart/', events: ['cart.updated'], before: async (page) => { await page.locator('[name=quantity]').fill('7'); await page.locator('body').click({position: {x: 1, y: 1}}); }}, async ({page, state}) => {
			await page.waitForFunction(() => document.querySelector('#mini-cart').textContent === 'new-mini');
			await delay(200);
			assert.ok(state.html > 0);
			assert.equal(await page.locator('[name=quantity]').inputValue(), '7');
			assert.equal(await page.locator('.cart-total').textContent(), 'old-total');
		});
		await scenario(browser, {name: 'focused cart survives then refreshes after focus leaves', bodyClass: 'woocommerce-cart', path: '/cart/', events: ['cart.updated'], before: async (page) => page.locator('[name=quantity]').focus()}, async ({page}) => {
			await page.waitForFunction(() => document.querySelector('#mini-cart').textContent === 'new-mini');
			await delay(200);
			assert.equal(await page.locator('[name=quantity]').inputValue(), '1');
			await page.locator('[name=quantity]').blur();
			await page.waitForFunction(() => document.querySelector('.cart-total').textContent === 'new-total');
		});
		await scenario(browser, {name: 'checkout is never replaced or automatically submitted', bodyClass: 'woocommerce-checkout', path: '/checkout/', events: ['cart.updated', 'favorites.updated', 'history.updated']}, async ({page, state}) => {
			await page.waitForFunction(() => window.DigitalogicAccountActivity !== undefined);
			await delay(350);
			assert.ok(state.polls > 0);
			assert.equal(state.html, 0);
			assert.equal(state.history, 0);
			assert.equal(await page.locator('form.checkout input').inputValue(), 'old-address');
			assert.deepEqual(await page.evaluate(() => window.__providerEvents), []);
		});
		await scenario(browser, {name: 'cookie generation change blocks all private DOM and unfenced mini-cart requests', bodyClass: 'woocommerce-cart', path: '/cart/', events: ['cart.updated', 'favorites.updated'], revokeAfterHtml: true}, async ({page, state}) => {
			await page.waitForFunction(() => window.__providerEvents.length === 0);
			await delay(350);
			assert.equal(state.html, 1);
			assert.equal(state.history, 1);
			assert.equal(await page.locator('.wd-wishlist-content').textContent(), 'old-wishlist');
			assert.equal(await page.locator('.wd-wishlist-btn a').textContent(), 'Add');
			assert.equal(await page.locator('#mini-cart').textContent(), 'old-mini');
			assert.equal(await page.locator('#side-mini-cart').textContent(), 'old-side-mini');
			assert.equal(await page.locator('.wd-header-cart .wd-tools-count').textContent(), '1');
			assert.equal(await page.locator('.cart-total').textContent(), 'old-total');
			assert.equal(state.unfencedMiniCart, 0);
			assert.deepEqual(await page.evaluate(() => window.__providerEvents), []);
		});
		await scenario(browser, {name: 'hidden product is not recorded; visible visit records once with single-flight polling', hidden: true, productId: product, holdPost: true}, async ({page, state, releasePost}) => {
			await delay(120);
			assert.equal(state.views.length, 0);
			assert.equal(state.polls, 0);
			await page.evaluate(() => { window.__visible = true; document.dispatchEvent(new Event('visibilitychange')); });
			await page.waitForFunction(() => document.visibilityState === 'visible');
			for (let i = 0; i < 40 && state.views.length === 0; i++) await delay(10);
			assert.deepEqual(state.views, [{product_id: product}]);
			await page.evaluate(() => { document.dispatchEvent(new Event('visibilitychange')); document.dispatchEvent(new Event('visibilitychange')); });
			await delay(80);
			assert.equal(state.polls, 0);
			releasePost();
			for (let i = 0; i < 40 && state.polls === 0; i++) await delay(10);
			assert.equal(state.views.length, 1);
			assert.equal(state.polls, 1);
		});
		await scenario(browser, {name: 'unrelated public events do not refresh private sections', events: ['product.updated', 'order.updated']}, async ({state}) => {
			await delay(350);
			assert.ok(state.polls > 0);
			assert.equal(state.html, 0);
			assert.equal(state.history, 0);
		});
		console.log(JSON.stringify({browser: 'Chromium', source: 'local synthetic provider DOM; no live account', checks: results.length, passed: results}, null, 2));
	} finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
