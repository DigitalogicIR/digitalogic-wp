# Android release issuing and compatibility reports

The publisher owns immutable signed APK/manifest files. The issued WordPress database row is the sole public release authority; staging a file does not promote it. Android still requires the user's normal installation confirmation.

Initial deployment: **live on2026-11-15** from runtime commit `970df32`; reporting policy was subsequently updated in `7d83674`. Build17 was issued at11:58:45UTC through the owner command. The operational report schema/cleanup are active; no synthetic production report was submitted. Physical-device receipt is a separate app-owner acceptance step.

Latest issue: **build18 at18:00:59UTC on2026-11-15**, operation `a9f1ecab-40de-46f0-a1aa-5d697f7a2f18`. Its immutable manifest is `https://digitalogic.ir/apps/digitalogic/digitalogic-18-arm64.json`, app source `1e1ae8788855ddde2256386ee65562b2f7cae168`, APK SHA-256 `54183974c54a31f92448d66cb782c4efd49a2955e800e71f80bd99a58409f3f3`. Independent public readback confirmed bootstrap200 with exact fixed manifest/build/hash, APK range206, catalog200 and anonymous report401. Automatic reporting remains disclosed with `telemetry_opt_in_required:false`; approved OTP template757947 and its hash/enabled state are unchanged.

## Issue an existing build

The existing WordPress mobile settings page offers a `manage_options` and nonce-protected issue action. The machine owner may also run:

```sh
wp digitalogic-mobile-release issue 17 --request-id=<stable UUID> --allow-root
```

The service accepts a build number, never an arbitrary URL, APK upload or command. It checks the fixed same-origin manifest, complete APK bytes and SHA-256, the actual permanent signing certificate with `apksigner`, and actual package/version/minimum SDK with `aapt`. Both fixed tools have a 15-second timeout and bounded output.

Publisher and issuer hold the same stable `/run/lock/digitalogic-app-release.lock` inode. Deployment installs a narrow `tmpfiles.d` rule to recreate it as root:root0644 after reboot; it is never removed or replaced during an uptime. The publisher stages and validates immutable artifacts while holding the lock, releases it, then invokes the issuer with its stable operation UUID. A database advisory lock and InnoDB transaction protect the issued option, offer switch and UUID receipt. A stale administrator action cannot downgrade the issued build. Replaying an old operation returns its historical receipt without replacing a newer issue. No static `latest.json` is read as an issuance or advertisement authority.

The existing `digitalogic_mobile_update_manifest` option enables the permanent `https://digitalogic.ir/apps/digitalogic/latest.json` channel. Bootstrap adds the validated `release` object, including its exact immutable `manifest_url`, and preserves `self_update`/`update_manifest`. Clearing the option disables update offers; it does not remove the already issued public download. The settings field can enable the permanent channel or accept the current issued immutable URL for compatibility, but cannot select a different build. No native bearer endpoint can issue releases.

## One permanent release channel

The canonical Apache vhosts send only the exact `/apps/digitalogic/latest.json` and `/apps/digitalogic/latest.apk` paths to the existing WordPress front controller. The plugin reads the original request path and dispatches its existing Laravel gateway. Equivalent public routes are `/app-api/release/manifest` and `/app-api/release/apk`. GET/HEAD are allowed; other methods return405. Query parameters cannot select a build or redirect destination.

- `latest.json` returns the validated issued manifest, including optional `source_commit` and `deltas`.
- `latest.apk` returns307 to the exact immutable issued APK URL, preserving normal Range download semantics at that destination.
- Both aliases return `Cache-Control: private, no-store, max-age=0` on success, redirect and unavailable outcomes. They never return304. Missing, corrupt or unreadable authority returns503 instead of a static fallback.
- Bootstrap reads the issued row and offer switch in one direct database statement, uses their normalized revision in its bounded cache key, and checks that revision again after cache lookup/build. Concurrent changes return503 for retry instead of a mixed release snapshot. Bootstrap is also `private, no-store`; stale WordPress option caches and intermediary snapshots cannot choose the release.

Promotion changes one transaction. Independent publisher acceptance must compare permanent JSON, immutable JSON, bootstrap release/build/hash and permanent APK redirect/full hash. The legacy physical `latest.json` may be quarantined only after the dynamic route is verified; its file contents have no authority after routing is active.

### Optional delta metadata

The full immutable APK URL, checksum, byte count and permanent signature are always required. Optional `deltas` is a sequential list of at most eight entries, each containing only `algorithm`, `from_version_code`, `from_sha256`, `url`, `sha256` and `bytes`. The sole algorithm is `digitalogic-copy-v1+gzip`; the source build must be positive and lower than the target. Hashes are64 lowercase hex characters, patch bytes must be positive and smaller than the full APK, and duplicate patch names are rejected.

Each URL must exactly equal the same-origin `/apps/digitalogic/digitalogic-FROM-TO-BASE12-arm64.delta.gz` path, where BASE12 is the first12 characters of `from_sha256`. No arbitrary path, host, query, symlink or alternate ABI is accepted. Issuance verifies every local patch's complete byte count and hash while holding the publisher lock. It does not execute or apply patches. Clients verify the reconstructed full APK and retain the full-download fallback; old clients can ignore the optional list. `source_commit`, when present, must be a full40-character lowercase hexadecimal commit.

### Guarded channel deployment

The2026-11-18 repair was prompted by observed issued build18 versus stale static latest build16. Source and fixture acceptance are recorded independently from live deployment:

1. Deploy the release service, controller, route table and exact WordPress dispatcher after their expected live hashes match. Keep the old bootstrap controller temporarily advertising its existing immutable issued manifest.
2. Enable the two exact Apache aliases and verify DB-selected JSON plus307 APK behavior before quarantining the old static file.
3. Deploy the bootstrap snapshot/cache fence and verify its permanent channel URL and issued manifest agree through apex and PWA.
4. Only then run the publisher's explicit issue operation. A lost reply is recovered with the identical UUID, never a newly invented retry operation.

Containment preserves immutable artifacts and receipts. Disable new offers if necessary; do not lower the active build or replace signed artifacts in place. A corrected higher build is the normal release repair. Runtime rollback backups must retain the exact predeploy hashes; reverting routes must not expose the obsolete static file as a silent authority.

Release rollback readiness: prior immutable artifacts and UUID receipts remain preserved. The issuer deliberately refuses downgrade; to contain a faulty offer, an authorized operator can clear the existing manifest option, then publish and issue a higher corrected build. This does not silently downgrade or uninstall devices. No rollback was performed during build18 acceptance.

## Operational device compatibility report

Following the owner's explicit policy change, reporting is automatic for service metrics, security operations and capability delivery. It does not create fraud decisions, identify hardware persistently or track people across services. The app discloses the bounded fields and retention. `POST /app-api/account/device-report` requires an active native session and this exact JSON shape:

```json
{"version_code":17,"sdk_int":35,"manufacturer":"samsung","model":"SM-G988B","abi":"arm64-v8a","ram_class":"standard","form_factor":"phone"}
```

Manufacturer/model are limited to64 characters; ABI, RAM class and form factor use finite allowlists. Unknown fields, missing compatibility fields and malformed values fail before writing. New clients omit `consent`; they must not fabricate a consent flag. For released-client compatibility, optional legacy `consent:true` is accepted and discarded before storage. An explicit false/null/nonboolean legacy flag is rejected400. Older clients retain their existing local behavior; the server does not force them to send.

Successful acceptance is HTTP202 `{"accepted":true,"retention_days":30}`. Anonymous/revoked sessions get401; six accepted reports per hour per session are allowed. GET/DELETE are not exposed. Bootstrap `capabilities.telemetry` remains the availability gate, while `policy.telemetry_opt_in_required:false` truthfully describes the current policy. The client sends only the allowlisted operational data and shows its privacy disclosure.

The report table stores only seven compatibility fields, UTC day, expiry and a keyed session/day contribution digest. It stores no raw user/session ID, IP, token, serial, Android ID or advertising identifier. Retries replace one contribution per session/day. Reports expire after30days, disappear from aggregates immediately at expiry and are physically deleted by the hourly cleanup. Administrator aggregates suppress cohorts smaller than five session-days. These are session-day counts, not unique people or devices.

Install `MobileDeviceReport::install()` explicitly, verify schema and scheduled cleanup, then enable `digitalogic_mobile_device_reports`. Installation alone never enables reporting. The existing native-auth switch must also remain enabled. The automatic-report compatibility change requires no schema migration and does not alter session authority, daily deduplication, rate limits, retention, aggregation or release/SMS configuration.

## Acceptance

- Focused OTP/release/report suite:27 tests/249 assertions, WordPress PHPCS and syntax clean.
- Independent release/report review:15 tests/158 assertions, no blocking finding.
- Separate WordPress/Woo/Laravel fixture:38 actual-path checks passed, using the real signed build16/17 artifacts and fixed Android verifier tools. Covered issuance, same-UUID replay, downgrade rejection, shared-lock contention, anonymous denial, real-kernel report authentication/consent/limits, daily deduplication, revoked session rejection and expiry deletion.
- The fixture created no orders, sent no SMS/mail and retained network/cache isolation. Production acceptance is recorded separately after narrow deployment.
- Transport trace: zero successful IP connections, zero foreign Unix sockets, one private fixture database connection. All six frozen runtime hashes matched before/after acceptance.
- Live readback: anonymous POST report401, GET/DELETE405; report schema1, hourly cleanup scheduled, approved Android SMS capability retained. Both fixed Android verifier commands also succeeded as the production web-service user. The tmpfiles creation preserved the existing shared-lock inode.
- Automatic operational-report policy revision:19 focused tests/198 assertions and independent15-test/172-assertion review passed. The actual isolated WordPress/Laravel kernel passed55 checks covering omitted consent, legacy true, nontrue refusal, every required/unknown field, native session authority, manufacturer limits, deduplication, rate limiting and expiry cleanup. The retained fixture needed its existing catalog-search dependency refreshed from canonical code before bootstrap acceptance; no runtime behavior was weakened.
