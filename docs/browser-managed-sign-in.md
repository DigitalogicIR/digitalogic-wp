# Browser-managed sign-in

The branded login's Google button starts Digits Google OAuth. Google Identity Services supplies the independent One Tap prompt, which can use FedCM on eligible browsers. The WordPress Google client ID comes from the enabled Digits Google provider or the server's `DIGITALOGIC_GIS_CLIENT_ID` setting. Register the login origin, such as `https://digitalogic.ir`, under Authorized JavaScript origins for that web client in Google Cloud.

The fixed Google signing-key URL uses direct HTTPS because the WordPress-wide proxy can be unavailable for that host. If the instance requires a CONNECT proxy for that URL, set the server-local `DIGITALOGIC_GOOGLE_JWKS_PROXY` constant. TLS certificate and hostname checks remain enabled.

Google assertions resolve existing Digits associations by the verified Google subject in `auth_google_id`. Duplicate associations fail closed. An unfamiliar Google account can use the existing Google OAuth flow. A browser-managed assertion does not link accounts by email. The two modes do not request any Google API permissions beyond sign-in.

The username field can offer the browser's passkey suggestions. Digits issues the WebAuthn challenge, validates the assertion, and handles enrollment through the existing account controls. The explicit Passkey action is available on supported browsers.

## Add a trusted OIDC issuer

Set the server-side WordPress option `digitalogic_oidc_providers` to a map keyed by a stable provider name. Use the issuer's actual discovery document to verify these values. The provider must issue RS256 ID tokens with the request nonce.

| Key | Value |
| --- | --- |
| `label` | Provider name for the login button |
| `issuer` | Exact HTTPS issuer identifier from discovery |
| `client_id` | Public client ID assigned to Digitalogic |
| `jwks_uri` | HTTPS JWKS endpoint from discovery |
| `authorization_endpoint`, `token_endpoint` | HTTPS endpoints for redirect sign-in |
| `fedcm_config_url` | Optional HTTPS FedCM configuration URL from a FedCM-compatible issuer |
| `accounts` | Explicit map from OIDC subject to existing WordPress user ID |

Register `https://digitalogic.ir/wp-json/digitalogic/v1/federated-login/callback` as the exact redirect URI. The client uses Authorization Code with PKCE S256. For confidential clients, inject secrets into the server-local `DIGITALOGIC_OIDC_CLIENT_SECRETS` constant keyed by provider name. The default client authentication is `client_secret_basic`. Set `token_endpoint_auth_method` to `client_secret_post` only for providers that require it.

FedCM requires the issuer's browser-facing configuration, client registration, account endpoint, and assertion endpoint. Its assertion endpoint must return the standard signed ID token as the FedCM token for this client, with the submitted nonce. An OIDC discovery document alone does not enable FedCM. The same trusted issuer and account mapping apply to browser-managed and redirect sign-in.

The `digitalogic_federated_user_id` filter can resolve an existing mapping from another trusted account store using the exact issuer and verified subject. The existing Digitalogic OIDC server and its Git clients keep their current registration and endpoints.
