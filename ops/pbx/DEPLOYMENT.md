# Deployment and validation

This runbook covers the caller-ID shortcut on Digitalogic's inbound TCI line.

## 1. Preflight and backups

- Confirm Node 18+, `ffmpeg`, Piper, NTP, and authoritative TCI ANI.
- Confirm AGI and DTMF debug/logging are off.
- Record active calls and wait for a maintenance window.
- Back up and hash `/etc/asterisk/extensions.conf`, the current helper/config,
  prompt symlink, and any approved BGM source.
- Export any realtime `extensions` rows that overlap `[from-tci]` or the new
  helper contexts. Digitalogic currently uses static inbound routing; if rows do
  exist, treat static and realtime definitions as one source-of-truth change.

Run repository gates:

```bash
cd ops/pbx
npm run check
npm test
bash -n bin/pbx-verification-digitalogic scripts/*.sh
```

## 2. Install immutable helper/config release

```bash
install -d -o root -g root -m 0755 /opt/digitalogic-pbx-verification/releases/1.1.0
install -o root -g root -m 0755 lib/pbx-verification-agi.js \
  /opt/digitalogic-pbx-verification/releases/1.1.0/pbx-verification-agi.js
ln -sfn releases/1.1.0 /opt/digitalogic-pbx-verification/current
install -o root -g root -m 0755 bin/pbx-verification-digitalogic \
  /usr/local/libexec/pbx-verification-digitalogic
install -o root -g asterisk -m 0640 config/pbx-verification.env.example \
  /etc/asterisk/pbx-verification-digitalogic.env
```

Keep the existing per-site base64 HMAC key in its root-owned, non-symlinked
secret file. If rotation is required, provision the new key in WordPress first,
then switch PBX and server key IDs atomically. Never place the key in the env file.

Verify both paths and both contexts exactly. `PBX_PENDING_HTTP_TIMEOUT_MS=3000`
allows for WordPress startup latency while bounding the delay for ordinary callers.

## 3. Generate BGM-mixed Persian prompts

Use a reviewed, licensed BGM file copied to an immutable PBX path. The generator
will fail if `PBX_BGM_FILE` is missing; every pending, entry, retry, success, and
failure prompt receives the same filtered/limited mix and cannot ship dry.

```bash
PBX_TTS_ENGINE=piper \
PBX_PIPER_BIN=/reviewed/path/to/piper \
PBX_PIPER_MODEL=/reviewed/path/to/fa_IR-model.onnx \
PBX_PIPER_CONFIG=/reviewed/path/to/fa_IR-model.onnx.json \
PBX_BGM_FILE=/reviewed/immutable/path/IVR-07.mp3 \
  bash scripts/generate-prompts.sh ./build/prompts-fa-IR
sudo bash scripts/install-prompts.sh digitalogic 1.1.0 ./build/prompts-fa-IR
```

Listen to every output and verify the BGM remains below speech, `*` can interrupt
the pending prompt, and both 8 kHz/16 kHz files are mono PCM. The installer creates
an immutable release and atomically switches `current`.

## 4. Restore the caller-ID shortcut

Apply the reviewed fragment to both `[from-tci]` paths. Invoke preflight before
caller ID normalization or recording. Run the shortcut only for a positive lookup.
End a verified call and keep the current operator and failover priorities for all
other outcomes. Recheck the live checksum immediately before installation.

For corporate extension login, configure the site-local
`DIGITALOGIC_LAN_LOGIN_ACCOUNTS` map (workstation IP to WordPress login) and
`DIGITALOGIC_LAN_PBX_EXTENSIONS` map (WordPress login to SIP endpoint). Install the
matching extension hook and private context from
`asterisk/digitalogic-extension-verification.conf`. The hook checks the source
endpoint before recording or dialing. Keep the existing route after the hook so
ordinary calls still reach the extension. Review each new endpoint and route
before adding an assignment.

Inside the existing `[prefix-tci-callerid]` subroutine, normalize `00989…` first,
`0989…` second, and bare `989…` third, replacing each prefix with `09`. Then strip
`021` from a same-city Tehran landline. Only after those transformations may the
existing internal callback access prefix `9` be added. For example,
`989123456789` becomes `909123456789`, and `02166754123` becomes `966754123`;
the outbound `_9X.` route removes the access digit. Non-Tehran landline prefixes
remain intact.

Do not add a public fallback digit. The private `verify` context is intentionally
unrouted until a separate Digitalogic IVR is designed and approved.

If realtime rows overlap, update/export them in the same maintenance transaction,
then compare their effective priorities with the static file before reload. Never
allow one layer to retain the old digit-2 menu.

```bash
asterisk -rx 'dialplan show from-tci'
asterisk -rx 'dialplan show pbx-call-verification-pending-digitalogic'
asterisk -rx 'dialplan show pbx-call-verification-digitalogic'
asterisk -rx 'dialplan reload'
asterisk -rx 'agi set debug off'
```

## 5. Acceptance tests

Use real PSTN ANI and redacted evidence:

- Call with an active website challenge. Verify the private keypad prompt, the
  six-digit confirmation, the WordPress session, and the original return page.
- Call without a challenge and verify the existing operator route.
- `989123456789`, `0989123456789`, and `00989123456789` all normalize to
  `09123456789` before the internal callback access prefix is added; an existing
  `09…` ANI is unchanged.
- `02166754123` becomes extension caller ID `966754123`; one-touch redial sends
  the same-city subscriber number `66754123` without the `021` prefix.
- Both s and _X inbound paths behave identically.
- Keep the private verification context unreachable from internal extensions.

Outbound voice remains a separate opt-in, authenticated trust boundary.

## Optional outbound verification

The normal inbound flow remains the default. For a site that wants to let the
user request a callback, provision a private shared queue outside the web root.
Give the WordPress and Asterisk service users a dedicated shared group. The
queue should have mode `2770` and must not be shared with upload or recording
directories. WordPress writes an expiring job after the user requests a call.
The job contains the challenge ID and normalized recipient. The six digits are
entered on the call keypad and travel only over the signed PBX callback.

Install `lib/pbx-verification-outbound.js` beside `pbx-verification-agi.js`, and
install the private `asterisk/digitalogic-outbound-verification.conf` context.
Run the worker as Asterisk from a single non-overlapping service timer with an
interval of five seconds or less. The service needs read/write access to the
private queue and access to create staging directories and publish call files on
the same filesystem as Asterisk's outgoing spool. Configure these service
variables through a server-local environment file:

- `PBX_VERIFY_OUTBOUND_QUEUE` points to the queue directory.
- `PBX_VERIFY_OUTBOUND_TRUNK` identifies the approved PJSIP endpoint.
- `PBX_VERIFY_OUTBOUND_PREFIX` is an optional numeric trunk prefix before national dialing.
- `PBX_VERIFY_OUTBOUND_CALLER_ID` is the approved numeric outbound caller ID.
- `PBX_VERIFY_ASTERISK_SPOOL` defaults to `/var/spool/asterisk`.

Verify the approved route with a designated test recipient before setting
`DIGITALOGIC_PBX_OUTBOUND_ENABLED` to boolean true and
`DIGITALOGIC_PBX_OUTBOUND_QUEUE` to the same private path in WordPress.
The worker accepts Iranian numbers, places at most one call per job, discards
stale work and binds the DTMF callback to the queued challenge. Set the WordPress
flag to false and stop the worker to disable the feature. An already placed
callback can still ring, but its verification request is rejected when disabled.
