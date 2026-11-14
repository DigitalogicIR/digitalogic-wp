# Android OTP rollout checkpoint

**Prepared only — no production deployment, schema change, option activation or SMS.** SMS.ir template **757947** is saved with the exact body and sole CODE parameter documented in [the contract](mobile-android-sms.md), but the release owner reports its status as **در حال بررسی**. Saving a template is not provider approval.

The [machine-readable checkpoint](mobile-android-sms-rollout.json) records the reviewed commit, five candidate file hashes, exact expected live hashes, local archive checksum and disabled configuration readback. Re-read every live hash immediately before any later rollout; abort on drift and coordinate with its owner.

## Reconciliation

The three existing native controller/store files exactly match their reviewed pre-change production baseline. The SMS.ir integration file has older comments/formatting and optional trailing commas; token comparison confirms no executable difference from that baseline. The new format service is absent. Main through `7ad2763` contains the other owner's Digits renderer and call-login fixes, but changes none of these five deployment paths. Those assets and provider/browser behavior must remain untouched.

Both backend remotes contain reviewed source `6ec7e62`; CI43595444776 passed. Related no-send tests pass27 cases/207 assertions, including the actual Laravel kernel's configured and unavailable responses. A second reviewer found no blocking source defect. The provider's rendered message/hash still requires approval and later real-device verification.

## Phase A — optional disabled runtime staging

This phase is safe independently of provider approval, **only after the release owner explicitly schedules it**. It leaves the new option, column and schema flag absent, so capability remains false and old native/default SMS delivery continues as before.

1. Recheck ownership, all expected live hashes, the absence of the new service, and disabled option/schema state. Stop on any unexpected change.
2. Back up only the four existing files with their owner/mode/hash. Validate all five frozen candidate files with PHP syntax checks and compare archive checksum.
3. Atomically replace each file in dependency order: new format service; existing SMS.ir sender; identity store; identity controller; catalog controller. Preserve production owner/mode. No full-plugin replacement, Composer update, assets, payment settings, saved-address code or unrelated patches.
4. Use the established scoped PHP/opcache reload procedure, then independently compare all five deployed hashes.
5. Read-only verification: public bootstrap is valid JSON and advertises `android_sms_retriever:false`; existing native auth remains enabled; `MobileOtpSmsFormat::available()` is false; legacy provider readiness remains true; Android option/schema/column remain absent. Keep actual OTP requests out of production setup checks; the isolated HTTP tests prove the unavailable/no-send behavior.

If verification fails, restore the four originals and remove only the exact new service after restoring its callers. Leave all database/options unchanged. Do not roll back another owner's files or the entire plugin.

## Phase B — approval-gated schema and activation

Do not perform this phase while template757947 remains pending.

1. The provider/dashboard owner independently confirms approval, exact final body, sole CODE substitution and fixed permanent-app hash `q3HQy4X3L3v`; the Flutter owner confirms capability gating, immutable retry and explicit-submit behavior.
2. Inspect current OTP table DDL and test the additive migration in the existing isolated database first. The intended change is only `sms_format varchar(20) NOT NULL DEFAULT 'default'`; existing challenges remain `default`. Use a reviewed narrow migration, not an unexamined broad installer. Set `digitalogic_mobile_otp_format_schema=1` only after exact column readback. Keep format configuration absent/disabled until this passes.
3. Apply the single server-owned option `digitalogic_mobile_android_sms` with boolean `enabled:true`, integer `template_id:757947`, and `app_hash:q3HQy4X3L3v`. Do not alter `digit_smsir2`, browser/WebOTP templates, credentials or rate limits.
4. Independently verify option/schema state, legacy readiness and public bootstrap capability true. The bootstrap cache key already includes the format configuration and schema flag.
5. Only with explicit handset-test authorization, make one normal receive-code request from the app and verify provider acceptance, actual SMS receipt, automatic extraction and user-submitted verification separately. Never read, retain or publish the code; never retry an uncertain send automatically.

Disabling the Android option stops new Android sends while default requests remain supported. Existing same-UUID receipts remain recoverable without redelivery. Once any Android challenge exists, do not remove its format column or downgrade runtime to a version that ignores stored format until all such challenges have expired and rollback is reviewed.
