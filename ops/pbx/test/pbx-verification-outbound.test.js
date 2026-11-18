'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { run } = require('../lib/pbx-verification-outbound');

test('outbound worker emits one private call file and discards expired or malformed jobs', (t) => {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pbx-verification-test-'));
	t.after(() => fs.rmSync(root, { recursive: true, force: true }));
	const queue = path.join(root, 'queue');
	const outgoing = path.join(root, 'outgoing');
	fs.mkdirSync(queue);
	fs.mkdirSync(outgoing);
	const now = Math.floor(Date.now() / 1000);
	const challenge = '123e4567-e89b-42d3-a456-426614174000';
	const jobs = [
		[challenge, '+989120000000', now + 100],
		['123e4567-e89b-42d3-a456-426614174001', '+989120000000', now - 1],
		['123e4567-e89b-42d3-a456-426614174002', '+989120000000\nMaxRetries: 9', now + 100],
	];
	for (const [id, phone, expires] of jobs) {
		fs.writeFileSync(path.join(queue, `${id}.json`), JSON.stringify({ challenge: id, phone, expires }));
	}
	const env = {
		PBX_VERIFY_OUTBOUND_QUEUE: queue,
		PBX_VERIFY_ASTERISK_SPOOL: root,
		PBX_VERIFY_OUTBOUND_TRUNK: 'test-trunk',
		PBX_VERIFY_OUTBOUND_CALLER_ID: '02100000000',
	};
	run(env);
	run(env);
	assert.deepEqual(fs.readdirSync(queue), []);
	assert.deepEqual(fs.readdirSync(outgoing), [`digitalogic-verify-${challenge}.call`]);
	const content = fs.readFileSync(path.join(outgoing, `digitalogic-verify-${challenge}.call`), 'utf8');
	assert.match(content, /^Channel: PJSIP\/09120000000@test-trunk$/m);
	assert.match(content, /^MaxRetries: 0$/m);
	assert.match(content, /^Context: pbx-call-verification-outbound$/m);
	assert.ok(content.includes(`Setvar: PBX_VERIFY_CHALLENGE=${challenge}`));
});
