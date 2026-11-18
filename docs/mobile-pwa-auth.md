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

The verifier still enforces Google signature, issuer, audience, expiry, verified email, subject ownership, duplicate discovery, binding activation fences and token replay policy. Browser nonce proof does not weaken privileged-account handling or implicitly send an SMS. Unknown identities retain the existing explicit Google-to-phone continuation; no new user is created from an email/name match alone.

Use the configured public web client ID from bootstrap. Google Console must separately authorize `https://pwa.digitalogic.ir` as a JavaScript origin. No OAuth client secret belongs in the PWA. Browser protected reauthentication/merge remains a separate frontend acceptance gate.

## App-styled Google popup

`capabilities.browser_google_code` advertises the separate GIS authorization-code popup adapter. It requires the existing browser/native gates, verified Google audience configuration, and the canonical Digits Google server-side `clientSecretID`. No secret, access token or refresh token is delivered to browser JavaScript or retained by this adapter.

1. Prepare `POST /auth/browser/google/challenge` with `{request_id: UUID, flow: "code"}` using the normal cookie and CSRF header. It returns `{challenge_id, nonce, expires_at, flow: "code"}`. The server binds the five-minute challenge to the exact cookie generation, PWA origin, configured audience and flow. Same UUID/flow retries return the same challenge; changing flow under that UUID returns409.
2. Initialize `google.accounts.oauth2.initCodeClient` with the public client ID, `scope: "openid email profile"`, `include_granted_scopes: false`, `ux_mode: "popup"`, and `state: nonce`. Call `requestCode()` synchronously from an explicit button gesture. Loading/preparing the control never opens account selection or signs anyone in.
3. Verify the returned state locally, then send `{code, challenge_id, state, device_name}` to `POST /auth/google/code`. Normal exact-Origin, host-only-cookie and generation-CSRF checks apply before dispatch. The server validates state and purpose before reserving or exchanging anything. It fixes the token endpoint to Google's HTTPS endpoint and `redirect_uri` to `https://pwa.digitalogic.ir`; callers cannot supply either.
4. The server consumes the attempt before its one external exchange, verifies Google's signed ID token with the exact web audience, issuer, expiry, recent issuance and verified email, and passes only those verified claims into the existing canonical subject/enrollment resolver. Successful browser login rotates its app cookie and returns the existing session/profile/CSRF shape without a bearer. Unknown identities retain the existing explicit phone continuation, not email-based auto-creation.

The code flow has a separate sealed purpose. It does not remove the signed nonce requirement from either the existing browser ID-token route or native proof routes. The code exchange ignores returned OAuth access/refresh tokens. Account activation, retired aliases, duplicate detection, provider-subject ownership and issued-token replay rules are shared with the existing identity resolver.

| Result | Meaning and client action |
| --- | --- |
|400 `browser_google_invalid_request`|Malformed or extra request fields; no exchange.|
|403 `browser_google_invalid_challenge`|Wrong/expired state, purpose, audience or cookie generation; no exchange.|
|409 `browser_google_code_used`|Attempt already reserved; do not resend the code.|
|409 `browser_google_code_rejected`|Provider or verified-token rejection; start a new explicit popup.|
|503 `browser_google_exchange_uncertain`|The exchange may have consumed the code; do not replay it. Discover the browser session after any ambiguous final login response, then offer a new explicit popup if needed.|

Only challenge preparation may be retried after a fresh CSRF discovery, using the same prepared UUID and the client's account-generation fence. This exception must not create a generic replay policy for authentication, checkout or other mutations. Initial discovery and pending discovery responses must still be serialized around cookie rotation.

The popup origin behavior and custom-header CSRF requirement follow [Google's code-model guide](https://developers.google.com/identity/oauth2/web/guides/use-code-model); the [CodeClient reference](https://developers.google.com/identity/oauth2/web/reference/js-reference) defines the user-gesture popup, state and code callback. This uses the already-authorized JavaScript origin; it is not a redirect flow or a new Cloud configuration.

### Preparation incident diagnosis (2026-11-18)

The unchanged production-equivalent WP/Laravel preparation path passed38 isolated assertions. Anonymous and authenticated challenge responses were200/no-store without `Set-Cookie` or private JSON; stale/missing CSRF was403; a delayed anonymous challenge did not replace or revoke the authenticated cookie. Revoked authority is resolved only by ordinary session discovery. The actual expiry copy is generated by the Flutter protected-route guard when it observes no current session; preparation itself has no401 or session-revocation branch. Client session/discovery and route-state regression checks remain a separate delivery gate.

The code adapter's source gate passes eight fake-HTTP/signed-JWT tests with258 assertions. The full PHP suite passes1,391 tests with15,019 assertions and35 existing platform skips. A real-kernel synthetic browser exchange and production readback remain required before reporting the new adapter as delivered; source tests do not prove a real customer Google sign-in.

## WebOTP and manual codes

Manual OTP entry works through the existing default template when `capabilities.web_otp` is false. No existing default or Android template is repurposed.

The optional selector is `sms_format: "web_otp"`. It requires the separate server-owned option `digitalogic_mobile_webotp_sms` with exactly `enabled:true`, a positive integer `template_id`, and `origin:"https://pwa.digitalogic.ir"`. The template must differ from the existing default and Android templates. Missing configuration returns top-level `code:"webotp_sms_unavailable"` with503 before reserving a challenge or sending a message. Request UUID replay remains bound to the selected format.

Enable only after inspecting the approved provider-rendered SMS: the last line must be `@pwa.digitalogic.ir #123456`, where only the six digits are substituted through the fixed `CODE` parameter. SMS.ir placeholder/literal-`#` syntax and any appended website footer must be verified in the actual provider output. Clients cannot choose a host, template, hash or provider parameters. Current default and Android delivery, rates and verification remain unchanged.

## Proxy and rollout

`capabilities.browser_auth` requires `digitalogic_mobile_browser_auth=true` and the existing native-auth gate. `browser_google` additionally requires the existing Google verifier configuration; `web_otp` additionally requires its separately approved template. Public bootstrap needs no PWA header.

Only an Origin-validated PWA request whose actual peer is127.0.0.1 or::1 can use the rightmost Apache-appended `X-Forwarded-For` address for abuse counters. Direct external peers ignore forwarded headers. Apache must append the real peer; do not make a global arbitrary-header trusted-proxy change. Authenticated HTTP responses are private/no-store. Public WebSockets carry no cookie, token, URL credential or subprotocol credential; authenticated state is refreshed over fenced HTTP.

Deployment is a narrow manifest of ten runtime files plus the browser gate. WebOTP stays disabled until separately approved. Preserve all other source/live patches, existing SMS options, update manifests, profiles and commerce records. Rollback can first disable the browser gate, then restore the exact backed-up runtime files; do not revoke unrelated native sessions.

## Acceptance status

- Source: independent browser/WebOTP/native regressions pass 52 tests / 682 assertions.
- Isolated canonical WordPress/Laravel: signed fixture Google token and seeded manual-code verification exercise the actual HTTP kernel; cookie/CSRF/account boundaries and native compatibility pass. No real identity proof, account creation, SMS, order or external network call.
- Fresh-process WooCommerce/Zero Spam acceptance verifies actual browser-cookie authority before normal provider initialization and unchanged guest enforcement. Detailed frozen-hash evidence is retained with the deployment checkpoint.
- Production PWA Google consent, delivered WebOTP and an actual browser checkout remain downstream gates; source tests are not claims of those outcomes.

### Deployment checkpoint â€” 2026-11-16

Runtime source `d335e44` (integrated main at `fe42d554`) is deployed as a ten-file artifact. The exact deployed archive is `61dd2f2de89a9f1fc816a1ea8468e5294884a6fd71648e615104890933d0f3ab`; all hashes were independently read back. The live cart file receives only its rate-scope expression change, preserving its older formatting/SQL baseline. Backup: `/var/backups/digitalogic-pwa-browser.kw29amlx`.

The frozen artifact passed 91 isolated canonical-kernel/fresh-bootstrap assertions with zero successful IP/foreign-socket connections, SMS, users or orders created. Public PWA acceptance passed 20 no-send checks: anonymous discovery, cookie flags, stable generation, Google nonce replay, stale/foreign rejection, no-store headers, and unchanged native unauthorized responses. Only an anonymous in-memory browser cookie and an expiring five-minute nonce were created; its receipt expires after ten minutes. No customer app session was created by the public checks.

`browser_auth` and `browser_google` are live; `web_otp` remains false. Default/Android SMS and released build 18 option hashes are unchanged. Google Console origin was independently configured by the PWA owner; an actual user GIS exchange remains a browser acceptance step. The separate PWA-origin WebOTP template is still an operational blocker, while manual code entry is available.

### Shopper WebSocket restoration

The live origin worker accepted `digitalogic.shopper` but a later server-file replacement had lost its negotiation and scoped projection. Direct worker and Apache probes reproduced the mismatch, so the PWA owner temporarily blocked that path. The already-reviewed shopper implementation was restored while preserving the current pricing-command and call-verification handlers.

Browser shopper sockets now accept only exact HTTPS apex/PWA Origins, remain public, ignore ambient cookies, and reject any Authorization header, duplicate Origin, query credentials or mixed subprotocols. Native connections without an Origin retain their verified bearer/session authority. Shopper connections negotiate their protocol, receive cursor metadata, accept ping only, and receive reduced allowlisted public or exact-owner invalidations. Native revocation/expiry is rechecked. Applicable legacy browser/call and pricing contracts remain separate.

The exact two-file artifact passed 30 isolated Linux canonical socket-pair assertions with no successful external/foreign-socket connection, SMS or order. Eleven focused source tests passed 91 assertions; the broader Windows lifecycle suite has ten platform-dependent Unix-socket skips, distinct from the Linux acceptance. The daemon's existing release watcher gracefully reloaded once between processing iterations; no forced restart interrupted a provider command. Origin and PWA Apache/Arvan independently negotiated `digitalogic.shopper` with HTTP101. The canonical SSE route and same-origin PWA fallback returned200/no-store. Backup: `/var/backups/digitalogic-pwa-shopper.29p09jp3`.

The public-read refinement separately permits exact unmarked catalog GET/HEAD requests without decoding or forwarding the app cookie. Protected calls still require the marker and CSRF. Its backup is `/var/backups/digitalogic-pwa-public-read.a4f2zv0f`; all final runtime hashes are in the deployment checkpoint.

### Discovery response ordering

An existing valid browser generation is read-only during `/auth/browser/session` discovery: it returns the current CSRF/profile without `Set-Cookie`. Only missing, invalid or expired/revoked authority initialization creates a replacement anonymous cookie. Login and explicit current-session logout retain their existing rotation behavior.

The previous unconditional discovery cookie write allowed a delayed anonymous response to overwrite a successfully rotated login cookie. The canonical isolated kernel reproduced the sequence: protected profile/history/events returned 200 after login, then failed after the old discovery response arrived, while the underlying native session remained valid. The new regression orders those same responses and verifies that an unchanged discovery cannot overwrite the completed login.

Clients must serialize initial discovery before authentication and await any in-flight discovery before verifying the post-login cookie round-trip. A stale response body must not replace newer local account state. This remains necessary for genuinely cookieless initialization; the server cannot retroactively cancel a `Set-Cookie` header already sent by an earlier request. Ordinary cart, profile, history and event responses do not rotate the browser authentication cookie.

The final full local PHP suite passed 1,297 tests / 11,786 assertions, with 35 platform-dependent skips on Windows. A CI fixture guard initially misread a numeric span inside the deployed WebSocket SHA-256 as a phone number. The extracted guard now exempts only complete SHA-256 values in explicitly named digest fields; eight regressions preserve phone detection, digit normalization, adjacent-value checks and locations-only diagnostics. This correction changes no production runtime.
