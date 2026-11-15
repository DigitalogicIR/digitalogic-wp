#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { normalizeIranNumber, validateEventId } = require('./pbx-verification-agi');

function callFile(job, id, endpoint, prefix, callerId, now) {
	if (!job || job.challenge !== id || !validateEventId(id) || typeof job.phone !== 'string'
		|| job.phone !== normalizeIranNumber(job.phone)
		|| !Number.isInteger(job.expires) || job.expires < now + 30 || job.expires > now + 120) {
		return '';
	}
	return [
		`Channel: PJSIP/${prefix}0${job.phone.slice(3)}@${endpoint}`,
		`Callerid: ${callerId}`,
		'WaitTime: 25',
		'MaxRetries: 0',
		'Context: pbx-call-verification-outbound',
		'Extension: s',
		'Priority: 1',
		`Setvar: PBX_VERIFY_ANI=${job.phone}`,
		`Setvar: PBX_VERIFY_CHALLENGE=${id}`,
		'',
	].join('\n');
}

function run(env = process.env) {
	const queue = env.PBX_VERIFY_OUTBOUND_QUEUE || '';
	const endpoint = env.PBX_VERIFY_OUTBOUND_TRUNK || '';
	const prefix = env.PBX_VERIFY_OUTBOUND_PREFIX || '';
	const callerId = env.PBX_VERIFY_OUTBOUND_CALLER_ID || '';
	const spool = env.PBX_VERIFY_ASTERISK_SPOOL || '/var/spool/asterisk';
	if (!path.isAbsolute(queue) || !path.isAbsolute(spool) || !/^[A-Za-z0-9_.-]{1,64}$/.test(endpoint)
		|| !/^\d{0,5}$/.test(prefix) || !/^\+?\d{3,15}$/.test(callerId)) {
		throw new Error('Outbound verification worker configuration is invalid.');
	}

	let failed = false;
	for (const entry of fs.readdirSync(queue, { withFileTypes: true })) {
		const id = path.basename(entry.name, '.json');
		if (!entry.isFile() || entry.name !== `${id}.json` || !validateEventId(id)) { continue; }
		const file = path.join(queue, entry.name);
		let job;
		try {
			const fd = fs.openSync(file, fs.constants.O_RDONLY | fs.constants.O_NOFOLLOW | fs.constants.O_NONBLOCK);
			try {
				const stat = fs.fstatSync(fd);
				if (stat.isFile() && stat.size <= 512) {
					job = JSON.parse(fs.readFileSync(fd, 'utf8'));
				}
			} finally {
				fs.closeSync(fd);
			}
		} catch (_) {
			// Drop malformed or unreadable jobs without a call.
		}
		try {
			fs.unlinkSync(file);
		} catch (error) {
			if (error.code !== 'ENOENT') { failed = true; }
			continue;
		}

		const body = callFile(job, id, endpoint, prefix, callerId, Math.floor(Date.now() / 1000));
		if (!body) { continue; }
		let stagingDir;
		try {
			stagingDir = fs.mkdtempSync(path.join(spool, '.digitalogic-outbound-'));
			const staged = path.join(stagingDir, 'job.call');
			fs.writeFileSync(staged, body, { mode: 0o600, flag: 'wx' });
			fs.renameSync(staged, path.join(spool, 'outgoing', `digitalogic-verify-${id}.call`));
		} catch (_) {
			failed = true;
		} finally {
			if (stagingDir) { fs.rmSync(stagingDir, { recursive: true, force: true }); }
		}
	}
	if (failed) { throw new Error('One or more outbound jobs could not be published.'); }
}

if (require.main === module) {
	try {
		run();
	} catch (_) {
		console.error('Outbound verification worker could not process its queue.');
		process.exitCode = 1;
	}
}

module.exports = { run };
