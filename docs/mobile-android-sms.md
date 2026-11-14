# Android SMS Retriever delivery

Status: **source prepared, not deployed or enabled**. No real SMS was sent during setup. The existing Digits/WebOTP template, browser flow, provider credential, OTP verification and rate limits remain unchanged.

## Client contract

On an explicit receive-code action, a supported release client may add `"sms_format":"android_hash"` to either existing request:

- `POST /app-api/auth/otp/request` — login, registration and Google-to-phone continuation.
- `POST /app-api/account/phone/request` — authenticated phone linking, including the phone proof used by account consolidation.

The client starts Retriever first and only requests this format when bootstrap advertises `capabilities.android_sms_retriever:true`. Older clients omit the field and keep the existing delivery path. Verification endpoints and user confirmation do not change. Autofill alone does not submit or authenticate.

The existing challenge response adds `sms_format` (`android_hash` or `default`); delivery remains `accepted` or `pending`, never a claim of handset receipt. The original request UUID, purpose, actor and phone remain the reservation identity. Replaying it never sends again; changing its format returns HTTP409 `request_conflict`. This also applies after an uncertain provider timeout. The client must retain the immutable request across retries.

Unknown/null format or supplied app-hash/template routing fields return HTTP400 `invalid_sms_format` before reservation or delivery. An explicit Android request without approved configuration returns HTTP503 `android_sms_unavailable`, with no SMS and no silent fallback. Client-supplied `app_hash`, `sms_hash`, `template`, `template_id` and `parameters` are rejected. The client cannot choose a provider template or supply the SMS hash.

## Provider template and server configuration

Create a **separate** SMS.ir verification template with the existing provider account. Its sole substitution parameter is exactly `CODE`. Confirm the dashboard's placeholder syntax before submission; if it uses `#CODE#`, the proposed text is:

```text
کد ورود دیجیتالاجیک: #CODE#
q3HQy4X3L3v
```

The hash is a fixed template literal, derived independently from package `ir.digitalogic.app` and the permanent production signing certificate. Do not add a `HASH` variable or accept a runtime client value. The six-digit rendered message fits both the UTF-8 and UTF-16BE 140-byte bound. Check the final approved/provider-rendered content for extra prefixes, suffixes and line changes before activation.

The [official Android server contract](https://developers.google.com/identity/sms-retriever/verify) requires an app hash and a message no longer than140bytes, and expressly excludes trusting hashes computed by the client. The provider API still receives only `mobile`, the approved integer `templateId`, and `parameters:[{name:"CODE",value:<server OTP>}]` through the existing one-shot SMS.ir transport. Credentials remain in the existing server-only Digits configuration.

The separate WordPress option `digitalogic_mobile_android_sms` is operator-owned:

| Field | Meaning |
| --- | --- |
| `enabled` | Boolean; set true only after provider approval and client acceptance. Missing/false disables the format. |
| `template_id` | Positive integer ID of the separately approved template. |
| `app_hash` | The approved fixed eleven-character template hash, kept on the server. |

No option is created or activated by a public request. Existing `digit_smsir2` template/mapping settings are neither changed nor replaced. Default OTP remains usable without the new option or schema.

## Schema and rollout gate

The explicit existing identity installer adds `digitalogic_mobile_otp.sms_format`, defaulting old rows to `default`, verifies the column, then records `digitalogic_mobile_otp_format_schema=1`. Identity schema2 remains compatible. New-format capability requires that schema flag, approved server configuration and the existing working provider configuration. Bootstrap cache keys include format configuration and schema, so a server configuration change changes the public representation.

Before enablement:

1. Agree the client request and fallback behavior.
2. Obtain approval of the separate provider template, exact literal hash and sole CODE parameter.
3. Review and deploy only the scoped native files; run the explicit additive schema upgrade without sending SMS.
4. Configure the approved option server-side and verify public capability readback.
5. Perform a separately authorized real handset delivery/autofill test; distinguish provider acceptance, actual receipt and successful native verification.

Setup checks use fake SQL and fake HTTP transport only. They cover unchanged default delivery, unavailable/invalid format rejection, fixed CODE parameters, format-bound replay, rate/reservation rejection, uncertain-send replay without redelivery, and authenticated phone-link scope. The actual Laravel HTTP kernel preserves the top-level HTTP503 error and performs no reservation or provider call when Android configuration is absent. The format, identity and Google-continuation suites pass **25 tests / 191 assertions**. These checks do not prove provider approval, delivery or automatic extraction on a real device.
