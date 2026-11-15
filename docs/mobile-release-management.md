# Android release issuing and compatibility reports

The publisher owns immutable signed APK/manifest files. This adapter only advertises a previously published build; Android still requires the user's normal installation confirmation.

Deployment status: **live on2026-11-15** from runtime commit `970df32`. Build17 was issued at11:58:45UTC through the owner command. Independent HTTPS readback returned `self_update:true`, `telemetry:true`, and fixed manifest `https://digitalogic.ir/apps/digitalogic/digitalogic-17-arm64.json`. The optional report schema/cleanup are active; no synthetic production report was submitted. Physical-device receipt is a separate app-owner acceptance step.

## Issue an existing build

The existing WordPress mobile settings page offers a `manage_options` and nonce-protected issue action. The machine owner may also run:

```sh
wp digitalogic-mobile-release issue 17 --request-id=<stable UUID> --allow-root
```

The service accepts a build number, never an arbitrary URL, APK upload or command. It checks the fixed same-origin manifest, complete APK bytes and SHA-256, the actual permanent signing certificate with `apksigner`, and actual package/version/minimum SDK with `aapt`. Both fixed tools have a 15-second timeout and bounded output.

Publisher and issuer hold the same stable `/run/lock/digitalogic-app-release.lock` inode. Deployment installs a narrow `tmpfiles.d` rule to recreate it as root:root0644 after reboot; it is never removed or replaced during an uptime. A database advisory lock and InnoDB transaction protect the issued option and UUID receipt. Neither a stale administrator action nor the current publisher `latest.json` can downgrade the advertised build. Replaying an old operation returns its historical receipt without replacing a newer issue.

The existing `digitalogic_mobile_update_manifest` option becomes the fixed immutable manifest URL. Bootstrap adds the validated `release` object and preserves `self_update`/`update_manifest`. Clearing the existing manifest option still disables update offers. No native bearer endpoint can issue releases.

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
