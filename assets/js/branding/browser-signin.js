(function () {
    'use strict';

    const config = window.DigitalogicBranding?.federatedLogin;
    if (!config || !window.isSecureContext) { return; }

    function mount() {
        const form = document.querySelector('.dg-digits-login-shell form.digloginpage');
        if (!form) { return; }
        const context = new URL(window.location.href);
        const persian = /^fa(?:[-_]|$)/i.test(document.documentElement.lang);
        const text = (fa, en) => persian ? fa : en;
        const oauthControls = form.querySelector('.digits-social-logins-box');
        const methods = document.createElement('div');
        methods.className = 'dg-browser-signin';
        const feedback = document.createElement('p');
        feedback.className = 'dg-browser-signin-feedback';
        feedback.setAttribute('role', 'status');
        feedback.hidden = true;
        methods.append(feedback);
        (oauthControls || form.querySelector('.digits-form_login') || form).append(methods);

        let google;
        let passkeyRequest;
        let completing = false;
        let userInteracted = false;
        const forbidden = ['reauth', 'interim-login', 'recover'].some(key => context.searchParams.has(key));
        if (forbidden) { return; }

        function initialStep() {
            return (!window.location.hash || window.location.hash === '#login')
                && !form.dataset.dgSmsIdentity && !form.closest('.dg-auth-is-success, [data-dg-recovery]')
                && !form.classList.contains('dg-username-step-active');
        }

        function tell(message) {
            feedback.textContent = message;
            feedback.hidden = !message;
        }

        const unavailable = text('ورود از طریق مرورگر انجام نشد. روش دیگری را امتحان کنید.', 'Browser sign-in could not finish. Try another sign-in method.');
        const unlinked = text('این حساب به ورود دیجیتالاجیک متصل نیست. از روش عادی ورود استفاده کنید.', 'This identity is not linked to a Digitalogic account. Use your regular sign-in method.');
        const redirectTo = () => context.searchParams.get('redirect_to') || form.elements.digits_redirect_page?.value || '';
        const remembered = () => Boolean(form.querySelector('input[name="rememberme"]:checked'));

        async function post(url, data) {
            url = new URL(url, window.location.href);
            if (url.origin !== context.origin) { throw new Error('origin'); }
            const response = await window.fetch(url, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
                signal: window.AbortSignal?.timeout?.(17000)
            });
            const payload = await response.json();
            if (!response.ok) { throw new Error(payload?.code || 'request'); }
            return payload;
        }

        function prepare(provider, mode = 'browser') {
            return post(config.prepare, {
                provider: provider.key, mode, redirect_to: redirectTo(), remember: remembered(),
                flow: window.DigitalogicAuthRealtime?.flowId?.()
            });
        }

        function finish(destination) {
            const target = new URL(destination, context);
            if (target.origin !== context.origin || target.username || target.password) { throw new Error('redirect'); }
            const resume = () => window.location.assign(target.href);
            if (typeof window.DigitalogicAuthSuccess === 'function') { window.DigitalogicAuthSuccess(resume); }
            else { resume(); }
        }

        if (config.resume) {
            finish(config.resume);
            return;
        }

        async function accept(nonce, credential) {
            if (!initialStep() || completing || typeof credential !== 'string' || !credential) { return; }
            completing = true;
            cancelPasskey();
            tell('');
            try {
                const response = await post(config.complete, { nonce, credential, remember: remembered() });
                if (typeof response.redirect !== 'string' || !response.redirect) { throw new Error('redirect'); }
                finish(response.redirect);
            } catch (error) {
                completing = false;
                tell(error.message === 'federation_account_unlinked' ? unlinked : unavailable);
            }
        }

        function addButton(label, onClick) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dg-browser-signin-option';
            button.textContent = label;
            button.addEventListener('click', onClick);
            methods.insertBefore(button, feedback);
            return button;
        }

        function cancelPasskey() {
            passkeyRequest?.abort();
            passkeyRequest = undefined;
        }

        function stopBackground() {
            google?.cancel();
            if (!completing) { cancelPasskey(); }
        }

        function syncStep() {
            methods.hidden = !initialStep();
            if (methods.hidden) { stopBackground(); }
        }

        window.addEventListener('hashchange', syncStep);
        new MutationObserver(syncStep).observe(form, { attributes: true, attributeFilter: ['class', 'data-dg-sms-identity'] });
        syncStep();
        if (context.searchParams.has('federation_error')) { tell(unavailable); }

        function loadGoogle() {
            if (window.google?.accounts?.id) { return Promise.resolve(window.google.accounts.id); }
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://accounts.google.com/gsi/client';
                script.async = true;
                script.addEventListener('load', () => window.google?.accounts?.id ? resolve(window.google.accounts.id) : reject(new Error('google')));
                script.addEventListener('error', reject);
                document.head.append(script);
            });
        }

        async function enableGoogle(provider) {
            try {
                const [sdk, request] = await Promise.all([loadGoogle(), prepare(provider)]);
                if (!request.nonce) { return; }
                google = sdk;
                google.initialize({
                    client_id: provider.clientId, nonce: request.nonce, auto_select: false, button_auto_select: false,
                    use_fedcm_for_prompt: true, use_fedcm_for_button: true, itp_support: true,
                    callback: ({ credential }) => void accept(request.nonce, credential)
                });
                if (context.searchParams.has('loggedout')) { google.disableAutoSelect(); }
                const slot = document.createElement('div');
                slot.className = 'dg-gis-button';
                methods.insertBefore(slot, feedback);
                google.renderButton(slot, {
                    type: 'standard', size: 'large', shape: 'pill', text: 'signin_with',
                    theme: document.body.dataset.dgTheme === 'dark' ? 'filled_black' : 'outline',
                    locale: persian ? 'fa' : 'en', width: Math.max(180, Math.min(350, Math.floor(methods.clientWidth)))
                });
                form.querySelector('#digits_google_login')?.setAttribute('aria-label',
                    text('حساب کاربری گوگل، ورود در پنجره جداگانه', 'Google account, sign in in a separate window'));
                if (provider.oneTap && initialStep() && !context.searchParams.has('loggedout') && !userInteracted
                    && !form.querySelector('input[name="digits_phone"]')?.value && !form.querySelector('input[name="digits_email"]')?.value) {
                    google.prompt();
                }
            } catch (error) { /* The existing Google OAuth action is available if GIS cannot initialize. */ }
        }

        function enableProvider(provider) {
            if (provider.redirect) {
                addButton(text('ورود با ' + provider.label, 'Sign in with ' + provider.label), async event => {
                    const button = event.currentTarget;
                    button.disabled = true;
                    stopBackground();
                    try {
                        const data = await prepare(provider, 'redirect');
                        const destination = new URL(data.authorization_url);
                        if (destination.protocol !== 'https:') { throw new Error('scheme'); }
                        window.location.assign(destination.href);
                    } catch (error) { tell(unavailable); button.disabled = false; }
                });
            }
            if (!provider.fedcm || !window.IdentityCredential || typeof navigator.credentials?.get !== 'function') { return; }
            let nonce;
            let deadline;
            const button = addButton(text('ورود در مرورگر با ' + provider.label, 'Sign in in the browser with ' + provider.label), async () => {
                if (!nonce || Date.now() >= deadline) { void ready(); return; }
                stopBackground();
                button.disabled = true;
                try {
                    const assertion = await navigator.credentials.get({
                        identity: { mode: 'active', providers: [{ configURL: provider.fedcm, clientId: provider.clientId, params: { nonce } }] }
                    });
                    if (assertion?.token) { await accept(nonce, assertion.token); }
                } catch (error) {
                    if (!['AbortError', 'NotAllowedError'].includes(error.name)) { tell(unavailable); }
                } finally { button.disabled = false; }
            });
            async function ready() {
                button.disabled = true;
                try {
                    const request = await prepare(provider);
                    nonce = request.nonce;
                    deadline = Date.now() + request.expires_in * 1000;
                    button.disabled = false;
                } catch (error) { button.hidden = true; }
            }
            void ready();
        }

        function identityField(target) {
            return target.matches?.('input[name="digits_phone"], input[name="digits_email"]');
        }

        form.addEventListener('input', event => {
            if (identityField(event.target)) { userInteracted = true; google?.cancel(); }
        });
        form.addEventListener('click', event => {
            if (event.target.closest('.digits-form_passwordless_login, #digits_google_login')) {
                userInteracted = true;
                stopBackground();
            }
        }, true);

        async function enablePasskeyAutofill(target) {
            const native = window.PublicKeyCredential;
            if (!initialStep() || completing || passkeyRequest || !form.querySelector('.digits-form_passwordless_login')
                || form.closest('.dg-digits-login-shell')?.dataset.dgIdentity === 'phone'
                || typeof native?.isConditionalMediationAvailable !== 'function' || typeof window.digits_preparePublicKeyOptions !== 'function'
                || typeof window.digits_preparePublicKeyCredentials !== 'function' || !window.dig_script?.ajax_url) { return; }
            const endpoint = new URL(window.dig_script.ajax_url, context.href);
            if (endpoint.origin !== context.origin) { return; }
            const controller = new AbortController();
            passkeyRequest = controller;
            try {
                if (!await native.isConditionalMediationAvailable() || controller.signal.aborted) { return; }
                const input = new FormData(form);
                for (const field of form.elements) {
                    if (field.name && field.type !== 'hidden' && field.name !== 'rememberme') { input.delete(field.name); }
                }
                input.set('sub_action', 'start_passwordless_login');
                const send = async body => (await window.fetch(endpoint, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store', body, signal: controller.signal
                })).json();
                const start = await send(input);
                if (!start.success || !start.data?.token || controller.signal.aborted || !initialStep()) { return; }
                const publicKey = window.digits_preparePublicKeyOptions(start.data.token.public_key || start.data.token);
                if (publicKey.allowCredentials?.length) { return; }
                target.dataset.dgPasskeyAutofill = 'true';
                target.autocomplete = 'username webauthn';
                const credential = await navigator.credentials.get({ publicKey, mediation: 'conditional', signal: controller.signal });
                if (!credential || !initialStep() || completing) { return; }
                completing = true;
                input.set('sub_action', 'complete_passwordless_login');
                input.set('cred', encodeURIComponent(JSON.stringify(window.digits_preparePublicKeyCredentials(credential))));
                const flow = window.DigitalogicAuthRealtime?.flowId?.();
                if (flow) { input.set('dg_auth_flow', flow); }
                if (remembered()) { input.set('rememberme', '1'); }
                else { input.delete('rememberme'); }
                const finishPasskey = await send(input);
                if (!finishPasskey.success || !window.DigitalogicFinishBrowserPasskey?.(form, finishPasskey.data)) { throw new Error('passkey'); }
            } catch (error) {
                if (!controller.signal.aborted && completing) { completing = false; tell(unavailable); }
            } finally {
                delete target.dataset.dgPasskeyAutofill;
                if (target.autocomplete === 'username webauthn') { target.autocomplete = target.name === 'digits_email' ? 'email' : 'username'; }
                if (passkeyRequest === controller) { passkeyRequest = undefined; }
            }
        }

        if (config.passkeys) {
            const activatePasskeys = event => {
                if (!identityField(event.target) || !initialStep()) { return; }
                userInteracted = true;
                google?.cancel();
                void enablePasskeyAutofill(event.target);
            };
            form.addEventListener('pointerdown', activatePasskeys);
            form.addEventListener('focusin', activatePasskeys);
            form.addEventListener('submit', stopBackground, true);
        }

        for (const provider of config.providers) {
            if (provider.key === 'google') { void enableGoogle(provider); }
            else { enableProvider(provider); }
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', mount, { once: true }); }
    else { mount(); }
})();
