# PWA authentication

The PWA at `https://pwa.digitalogic.ir` uses the existing WordPress identity and WooCommerce cart/checkout adapters through a same-origin `/app-api` proxy. It does not create WordPress login cookies, authorize `/panel/`, or place native bearer credentials in JavaScript storage.

## Session protocol

1. Send `X-Digitalogic-PWA: 1` to `GET /app-api/auth/browser/session` with browser cookies enabled. Discovery returns `authenticated`, `session_type: "browser"`, and a 64-character hexadecimal `csrf_token`. An authenticated result also contains the existing `session_id`, ISO8601 `expires_at`, and canonical `profile`.
2. Keep the CSRF generation in tab-scoped session storage. Send it as `X-Digitalogic-CSRF` on every subsequent browser API call, including authenticated GETs. This prevents a stale tab from silently reading a different account after another tab changes the shared cookie.
3. Use the existing OTP, registration, Google, profile, cart and checkout JSON routes. Successful authentication retains their customer/session fields, removes `access_token` and `token_type`, and returns the new `csrf_token` and browser session type.
4. Rediscover after `browser_csrf_invalid` (403), apply the client account fence, and require explicit review before retrying a mutation. Never replay an uncertain order, payment, account merge or other operation under a changed account.

The server stores its app-session bearer only inside the purpose-bound, authenticated-encrypted `__Host-dgl-pwa` cookie: Secure, HttpOnly, SameSite=Strict, Path=/, no Domain. A new login rotates its generation; current-session logout rotates to an anonymous generation. Discovery checks revocation, expiry, password epoch and retired aliases using the existing native session authority. Neither a WordPress cookie alone nor a mixed browser/native Authorization header grants PWA authority.

Unsafe methods require the exact PWA Origin. Safe reads can use the exact HTTPS PWA Referer when browsers omit Origin. Cross-origin credential requests are unsupported: the deployment must use the same-origin proxy. Public catalog/bootstrap reads can still work without a browser session, and ordinary native requests preserve their existing contract.

## Google browser button

Before rendering Google Identity Services, POST `/app-api/auth/browser/google/challenge` with `{request_id: UUID}` and the browser CSRF generation. The response is `{challenge_id, nonce, expires_at}`. Pass `nonce` to GIS, then send its signed `id_token` and `challenge_id` to the existing `/auth/google` endpoint. The challenge lasts five minutes and is bound to this cookie generation. The same request UUID replays the same unexpired challenge; expired challenges return410 and require a fresh user gesture.

The verifier still enforces Google signature, issuer, audience, expiry, verified email, subject ownership, duplicate discovery, binding activation fences and token replay policy. Browser nonce proof does not weaken privileged-account handling or implicitly send an SMS. Unknown identities retain the existing explicit Google→phone continuation; no new user is created from an email/name match alone.

Use the configured public web client ID from bootstrap. Google Console must separately authorize `https://pwa.digitalogic.ir` as a JavaScript origin. No OAuth client secret belongs in the PWA. Browser protected reauthentication/merge remains a separate frontend acceptance gate.

## WebOTP and manual codes

Manual OTP entry works through the existing default template when `capabilities.web_otp` is false. No existing default or Android template is repurposed.

The optional selector is `sms_format: "web_otp"`. It requires the separate server-owned option `digitalogic_mobile_webotp_sms` with exactly `enabled:true`, a positive integer `template_id`, and `origin:"https://pwa.digitalogic.ir"`. The template must differ from the existing default and Android templates. Missing configuration returns top-level `code:"webotp_sms_unavailable"` with503 before reserving a challenge or sending a message. Request UUID replay remains bound to the selected format.

Enable only after inspecting the approved provider-rendered SMS: the last line must be `@pwa.digitalogic.ir #123456`, where only the six digits are substituted through the fixed `CODE` parameter. SMS.ir placeholder/literal-`#` syntax and any appended website footer must be verified in the actual provider output. Clients cannot choose a host, template, hash or provider parameters. Current default and Android delivery, rates and verification remain unchanged.

## Proxy and rollout

`capabilities.browser_auth` requires `digitalogic_mobile_browser_auth=true` and the existing native-auth gate. `browser_google` additionally requires the existing Google verifier configuration; `web_otp` additionally requires its separately approved template. Public bootstrap needs no PWA header.

Only an Origin-validated PWA request whose actual peer is127.0.0.1 or::1 can use the rightmost Apache-appended `X-Forwarded-For` address for abuse counters. Direct external peers ignore forwarded headers. Apache must append the real peer; do not make a global arbitrary-header trusted-proxy change. Authenticated HTTP responses are private/no-store. Public WebSockets carry no cookie, token, URL credential or subprotocol credential; authenticated state is refreshed over fenced HTTP.

Deployment is a narrow manifest of ten runtime files plus the browser gate. WebOTP stays disabled until separately approved. Preserve all other source/live patches, existing SMS options, update manifests, profiles and commerce records. Rollback can first disable the browser gate, then restore the exact backed-up runtime files; do not revoke unrelated native sessions.

## Acceptance status

- Source: independent browser/WebOTP/native regressions pass50 tests/586 assertions.
- Isolated canonical WordPress/Laravel: signed fixture Google token and seeded manual-code verification exercise the actual HTTP kernel; cookie/CSRF/account boundaries and native compatibility pass. No real identity proof, account creation, SMS, order or external network call.
- Fresh-process WooCommerce/Zero Spam acceptance verifies actual browser-cookie authority before normal provider initialization and unchanged guest enforcement. Detailed frozen-hash evidence is retained with the deployment checkpoint.
- Production PWA Google consent, delivered WebOTP and an actual browser checkout remain downstream gates; source tests are not claims of those outcomes.
