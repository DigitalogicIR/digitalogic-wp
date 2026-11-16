'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const api = require('../assets/js/storefront-account-activity.js');
const location = {href: 'https://digitalogic.test/shop/?orderby=price&utm_source=fixture', origin: 'https://digitalogic.test'};

test('only same-origin HTTPS routes are accepted', () => {
	assert.equal(api.sameOrigin('/wp-json/digitalogic/v1/account/events', location).host, 'digitalogic.test');
	for (const url of ['https://other.invalid/', 'http://digitalogic.test/', 'javascript:alert(1)', 'https://user:password@digitalogic.test/']) assert.equal(api.sameOrigin(url, location), null);
});

test('HTML refresh never replays Woo actions or checkout', () => {
	assert.equal(api.refreshUrl(location).href, 'https://digitalogic.test/shop/?orderby=price');
	for (const path of ['/checkout/', '/checkout/order-pay/4/', '/cart/?remove_item=fixture', '/shop/?add-to-cart=123', '/cart/?wc-ajax=update_order_review', '/cart/?_wpnonce=fixture']) {
		assert.equal(api.refreshUrl({...location, href: location.origin + path}), null);
	}
	assert.equal(api.refreshUrl({...location, href: location.origin + '/shop/?filter_color=blue&query_type_color=and'}).search, '?filter_color=blue&query_type_color=and');
});

test('only three exact account invalidation names trigger website changes', () => {
	for (const scope of ['cart', 'favorites', 'history']) assert.equal(api.safeEventScope({name: scope + '.updated'}), scope);
	for (const name of ['product.updated', 'order.updated', 'mobile.cart.updated', 'cart.updated.anything']) assert.equal(api.safeEventScope({name}), null);
});

test('focused, edited and changed form fields prevent DOM replacement', () => {
	const field = {tagName: 'INPUT', type: 'number', value: '2', defaultValue: '1'};
	const root = {contains: () => false, querySelectorAll: () => [field]};
	const dirty = new WeakSet();
	assert.equal(api.hasEdit(root, {activeElement: null}, dirty), true);
	field.value = '1';
	assert.equal(api.hasEdit(root, {activeElement: null}, dirty), false);
	dirty.add(field);
	assert.equal(api.hasEdit(root, {activeElement: null}, dirty), true);
	assert.equal(api.hasEdit({...root, contains: () => true}, {activeElement: field}, new WeakSet()), true);
});

test('recent shelf recognition requires the real Woodmart structured descriptor', () => {
	const shelf = (data) => ({querySelector: () => ({getAttribute: () => data})});
	const recent = shelf('{"post_type":"recently_viewed"}');
	const nodes = [recent, shelf('{"post_type":"products"}'), shelf('broken')];
	assert.deepEqual(api.recentShelves({querySelectorAll: () => nodes}), [recent]);
});

test('bounded HTML/JSON reads reject oversized responses', async () => {
	const small = {headers: new Map(), text: async () => 'fixture'};
	assert.equal(await api.readBounded(small), 'fixture');
	await assert.rejects(api.readBounded({headers: new Map([['Content-Length', String(3 * 1024 * 1024)]]), text: async () => 'unused'}), /response_bound/);
	await assert.rejects(api.readBounded({headers: new Map(), text: async () => 'x'.repeat(2 * 1024 * 1024 + 1)}), /response_bound/);
});
