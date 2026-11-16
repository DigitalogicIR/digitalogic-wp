'use strict';

const assert = require('node:assert/strict');
const { createHash } = require('node:crypto');
const { createServer } = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright-core');

const branding = path.join(__dirname, '..', 'assets', 'js', 'branding', 'admin-branding.js');
const styles = path.join(__dirname, '..', 'assets', 'css', 'branding', 'admin-branding.css');
const phoneLibrary = path.join(__dirname, '..', 'assets', 'vendor', 'libphonenumber-js', 'libphonenumber-max.js');

async function checkAction(page, labels, direction) {
    const action = page.locator('.dg-passwordless-action:visible');
    assert.equal(await action.count(), 1);
    const button = action.getByRole('button', { name: labels.skipPassword, exact: true });
    const caption = action.getByText(labels.skipPasswordCaption, { exact: true });
    assert.equal(await caption.evaluate(element => element.tagName === 'P' && !element.closest('button')), true);
    assert.equal(await caption.locator('svg').count(), 0);
    assert.equal(await button.getAttribute('aria-describedby'), await caption.getAttribute('id'));
    assert.equal(await page.getByRole('button', { name: labels.skipPasswordCaption, exact: true }).count(), 0);
    assert.equal(await page.getByRole('link', { name: labels.skipPasswordCaption, exact: true }).count(), 0);

    const layout = await caption.evaluate(element => {
        const button = element.nextElementSibling;
        const previousAction = element.parentElement.previousElementSibling;
        const captionRect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        const colorProbe = document.createElement('span');
        colorProbe.style.color = 'var(--dg-text)';
        element.appendChild(colorProbe);
        const mainTextColor = style.color === getComputedStyle(colorProbe).color;
        colorProbe.remove();
        return {
            aboveButton: button?.matches('.dg-skip-password') && captionRect.bottom < button.getBoundingClientRect().top,
            spaceAbove: captionRect.top - previousAction.getBoundingClientRect().bottom,
            fontSize: parseFloat(style.fontSize),
            mainTextColor
        };
    });
    assert.ok(layout.aboveButton, 'The caption must be a separate element visibly above the button');
    assert.ok(layout.spaceAbove >= 18, 'Give the caption room after the preceding action');
    assert.ok(layout.fontSize >= 15 && layout.mainTextColor, 'The caption should be clearly legible');
    await checkIconAndLabel(button, direction);
    return button;
}

async function checkIconAndLabel(button, direction, expectedGap) {
    const layout = await button.evaluate(element => {
        const icon = element.querySelector('svg').getBoundingClientRect();
        const label = element.querySelector(':scope > span').getBoundingClientRect();
        const button = element.getBoundingClientRect();
        return {
            gap: Math.max(icon.left - label.right, label.left - icon.right),
            centerDifference: Math.abs((icon.top + icon.bottom - label.top - label.bottom) / 2),
            iconOnRight: icon.left > label.left,
            iconWidth: icon.width,
            groupOffset: Math.abs((Math.min(icon.left, label.left) + Math.max(icon.right, label.right) - button.left - button.right) / 2)
        };
    });
    assert.ok(layout.iconWidth >= 16, 'The action icon must be visible');
    assert.ok(layout.gap >= 8 && layout.gap <= 12, `Icon and text must be 8–12px apart (got ${layout.gap}px)`);
    if (expectedGap) { assert.ok(Math.abs(layout.gap - expectedGap) < 0.25, 'Use the shared icon gap'); }
    assert.ok(layout.centerDifference < 1, 'Icon and text must share a vertical center');
    assert.ok(layout.groupOffset < 1, 'Icon and text must be centered as a group');
    assert.equal(layout.iconOnRight, direction === 'rtl');
}

async function render(page, direction, labels, native, search = '', accountHints = {}, webAuthn = 'hybrid') {
    await page.route('https://digitalogic.test/login/**', route => route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: `<!doctype html><html lang="${direction === 'rtl' ? 'fa' : 'en'}" dir="${direction}">
            <body class="login"><div id="login"><div class="dg-digits-login-shell">
            <form class="digloginpage digits-form ${native ? '' : 'digits_form_index_section'}">
              <div class="digits-form_login">
                <div class="digits-form_heading"></div>
                <div class="digits-form_tab_wrapper">
                  <div class="digits-form_tab_container" ${native ? 'hidden' : ''}>
                    <div class="digits-form_tab_body digits-tab_active">
                        <div class="digits-mobile_wrapper">
                        <div class="digits-form_countrycode"><input name="login_digt_countrycode" value="+98"></div>
                        <input name="digits_phone">
                      </div>
                    </div>
                    <div class="digits-form_tab_body"><div class="digits-form_input_row"><div class="digits-form_input"><input name="digits_email"></div></div></div>
                  </div>
                  ${native ? `<template id="native-password-step"><div class="digits-form_tab_container">
                    <div class="digits-form_input_row">
                      <div class="digits-form_input digits_password_inp_row">
                        <input name="password" type="password" required>
                        <button class="digits_password_eye" type="button">Vendor eye</button>
                      </div>
                    </div>
                    <button class="digits-form_tab-item" data-value="otp" type="button">SMS tab</button>
                    <button class="digits-form_otp_selector" data-type="sms_otp" type="button">Request SMS</button>
                    <button class="digits-form_show_forgot_password" type="button">${labels.skipPasswordCaption}</button>
                  </div></template>` : ''}
                </div>
                <div class="digits_form-init_step_data">
                  <label class="digits-form_rememberme"><input class="digits_login_remember_me" name="rememberme" value="1" type="checkbox">Remember me</label>
                </div>
                <button class="digits-form_submit-btn" type="submit"><span class="digits-form_button-text">Continue</span></button>
                <button class="digits-form_passwordless_login" type="button">Passkey</button>
                <div class="digits-social-logins-box">
                  <div class="digits-use_social-logins"><span>Or continue with</span></div>
                  <div class="digits-social-logins"><div id="digits_google_login"><span class="digits-social-text">Google</span></div></div>
                </div>
              </div>
            </form><div class="dg-call-unified" data-call-unified data-call-available="true">
                ${accountHints.rememberedAccount?.emailDigest ? `<button type="button" data-call-lan-action
                   data-login="${accountHints.rememberedAccount.login}" data-email-digest="${accountHints.rememberedAccount.emailDigest}"
                   data-extension="101" hidden><span>Extension 101</span></button>` : ''}
                <div data-call-instructions hidden><a data-call-dial href="tel:+982112345678">Call</a></div>
            </div></div></div></body></html>`
    }));
    await page.goto('https://digitalogic.test/login/' + search);
    await page.evaluate(({ labels, accountHints, webAuthn }) => {
        Object.defineProperty(window, 'PublicKeyCredential', {
            value: class {
                static async getClientCapabilities() { return { hybridTransport: webAuthn === 'hybrid' }; }
                static async isUserVerifyingPlatformAuthenticatorAvailable() { return false; }
            },
            configurable: true
        });
        window.DigitalogicBranding = {
            labels,
            otpCursor: 'bar',
            ajaxUrl: '/email-domain',
            emailDomainNonce: 'fixture',
            emailKnownDomains: ['gmail.com'],
            rememberedAccounts: [{ login: 'known-user', avatar: '/known-avatar.svg' }],
            ...accountHints
        };
    }, { labels, accountHints, webAuthn });
    await page.route('https://digitalogic.test/known-avatar.svg', route => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path fill="blue" d="M0 0h32v32H0z"/></svg>'
    }));
    await page.addStyleTag({ path: styles });
    await page.addScriptTag({ path: phoneLibrary });
    await page.addScriptTag({ path: branding });
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    if (native) {
        await page.evaluate(() => {
            const template = document.getElementById('native-password-step');
            template.replaceWith(template.content);
            document.querySelector('[data-value="otp"]').addEventListener('click', () => {
                document.body.dataset.smsTabSelected = 'true';
            });
        });
    }
}

async function checkAccountPicker(page, direction, labels) {
    const account = { login: 'known-user', name: 'Known Account', avatar: '/known-avatar.svg', assigned: true };
    await render(page, direction, labels, false, '', {
        rememberedAccount: account, forgetAccountNonce: 'fixture', ajaxUrl: '/wp-admin/admin-ajax.php'
    });
    await page.route('**/wp-admin/admin-ajax.php', route => {
        const data = new URLSearchParams(route.request().postData());
        assert.equal(data.get('action'), 'digitalogic_forget_account_hint');
        assert.equal(data.get('login'), account.login);
        assert.equal(data.get('nonce'), 'fixture');
        return route.fulfill({ json: { success: true } });
    });

    const input = page.locator('.dg-login-identity-field:visible input[name="digits_phone"]');
    const field = page.locator('.dg-login-identity-field--picker:visible');
    const menu = page.getByRole('dialog');
    assert.equal(await field.count(), 1);
    assert.equal(await input.inputValue(), account.login);
    assert.equal(await field.locator('.dg-account-field-title').textContent(), account.name);
    assert.equal(await input.getAttribute('role'), 'combobox');

    await input.press('ArrowDown');
    assert.equal(await menu.isVisible(), true);
    assert.equal(await input.getAttribute('aria-expanded'), 'true');
    assert.equal(await menu.locator('.dg-account-menu-choice').count(), 1, 'Do not duplicate a saved workstation identity');
    await page.keyboard.press('Escape');
    assert.equal(await menu.isVisible(), false);
    assert.ok(await input.evaluate(node => document.activeElement === node));

    await field.locator('.dg-account-field-toggle').click();
    await menu.locator('.dg-account-menu-remove').click();
    await field.waitFor({ state: 'detached' });
    assert.equal(await input.inputValue(), '');
    assert.equal(await input.getAttribute('role'), null);
    assert.ok(await input.evaluate(node => document.activeElement === node));
}

async function checkWorkstationPasswordStep(page, direction, labels) {
    const email = 'workstation@example.com';
    const account = {
        login: 'known-user', name: 'Known Account', assigned: true, avatar: '/known-avatar.svg',
        emailDigest: createHash('sha256').update(email).digest('hex')
    };
    await render(page, direction, labels, false, '', { rememberedAccount: account });
    await page.route('**/email-domain', route => route.fulfill({ json: { success: true, data: { valid: true } } }));

    const phone = page.locator('input[name="digits_phone"]');
    const button = page.getByRole('button', { name: labels.continue, exact: true });
    const extension = page.locator('[data-call-lan-action]');
    const profile = page.locator('.dg-username-login-profile');
    const back = page.locator('.dg-username-login-back');
    assert.equal(await phone.inputValue(), account.login);
    assert.equal(await extension.isVisible(), false);

    await button.click();
    await profile.locator('img').waitFor();
    await extension.waitFor();
    const layout = await profile.evaluate(element => {
        const tab = element.parentElement;
        const avatar = element.querySelector('.dg-username-login-avatar');
        const icon = avatar.getBoundingClientRect();
        const label = element.querySelector('.dg-username-login-value').getBoundingClientRect();
        const password = tab.querySelector('.dg-password-field').getBoundingClientRect();
        const signIn = tab.querySelector('.dg-username-login-submit').getBoundingClientRect();
        const back = tab.querySelector('.dg-username-login-back').getBoundingClientRect();
        const skip = tab.querySelector('.dg-skip-password').getBoundingClientRect();
        const extension = tab.querySelector('[data-call-lan-action]').getBoundingClientRect();
        return {
            avatarAbove: icon.bottom < label.top,
            avatarCentered: Math.abs((icon.left + icon.right - label.left - label.right) / 2) < 1,
            avatarSize: icon.width,
            radius: getComputedStyle(avatar).borderRadius,
            actionsUnderPassword: [signIn, back].every(button => button.top >= password.bottom && button.top - password.bottom < 30),
            extensionUnderPasswordless: extension.top >= skip.bottom
        };
    });
    assert.deepEqual(layout, {
        avatarAbove: true, avatarCentered: true, avatarSize: 64, radius: '50%',
        actionsUnderPassword: true, extensionUnderPasswordless: true
    });

    await back.click();
    assert.equal(await extension.isVisible(), false);
    assert.equal(await extension.count(), 1);
    await phone.fill('other-user');
    await button.click();
    assert.equal(await profile.locator('img').count(), 0);
    assert.equal(await extension.isVisible(), false);
    await back.click();
    await phone.fill(email);
    await page.locator('#dg-email-domain-status[data-state="valid"]').waitFor();
    await button.click();
    await profile.locator('img').waitFor();
    await extension.waitFor();
}

async function checkEmailGate(page, labels) {
    const phone = page.locator('input[name="digits_phone"]');
    const email = page.locator('input[name="digits_email"]');
    const form = page.locator('form.digloginpage');
    const primary = form.locator('.digits-form_login > .digits-form_submit-btn');
    const status = form.locator('#dg-email-domain-status');
    const colors = new Set();
    let releaseSlow;
    let serviceCalls = 0;
    await page.route('https://digitalogic.test/email-domain', async route => {
        const domain = new URLSearchParams(route.request().postData()).get('domain');
        if (domain === 'slow.example') {
            await new Promise(resolve => { releaseSlow = resolve; });
            await route.fulfill({ json: { success: true, data: { valid: true } } });
            return;
        }
        if (domain === 'retry.example' && ++serviceCalls === 1) {
            await route.fulfill({ status: 503 });
            return;
        }
        await route.fulfill({ json: { success: true, data: { valid: domain !== 'invalid.example' } } });
    });
    async function expectState(state, enabled) {
        await status.and(page.locator(`[data-state="${state}"]`)).waitFor();
        assert.equal(await primary.evaluate(el => el.dataset.dgIdentityBlocked !== 'true'), enabled, state);
        colors.add(await status.evaluate(element => getComputedStyle(element).color));
    }
    await primary.click();
    await form.locator('.dg-auth-message[data-tone="error"] svg').waitFor();
    assert.equal(await phone.getAttribute('aria-invalid'), 'true');
    await phone.pressSequentially('09');
    assert.equal(await phone.inputValue(), '9', 'The separate +98 prefix replaces the domestic zero as soon as it is unambiguous');
    await phone.pressSequentially('12');
    assert.equal(await phone.inputValue(), '912');
    assert.equal(await phone.evaluate(input => input.selectionStart), 3);
    assert.equal(await primary.getAttribute('data-dg-identity-blocked'), 'true');
    await phone.pressSequentially('0000000');
    assert.equal(await primary.isEnabled(), true);
    assert.equal(await primary.innerText(), labels.sendSmsCode);
    await phone.fill('۰۲۱');
    assert.equal(await phone.inputValue(), '21', 'Iranian landlines also lose the domestic zero while incomplete');
    await primary.click();
    await form.locator('.dg-auth-message[data-tone="error"] svg').waitFor();
    await phone.fill('');
    await phone.pressSequentially('0044');
    assert.equal(await phone.inputValue(), '0044', 'Keep 00 available for an international number');
    await phone.fill('+12025550123');
    assert.equal(await primary.innerText(), labels.continueBySms);
    await page.locator('input[name="dg_phone_channel"][value="call"]').check();
    assert.equal(await primary.innerText(), labels.getCallCode);
    await page.evaluate(() => {
        window.DigitalogicCallLogin = { start(number) {
            document.body.dataset.callNumber = number;
            document.querySelector('[data-call-instructions]').hidden = false;
            document.querySelector('.dg-digits-login-shell').classList.add('dg-call-code-active');
            return true;
        } };
    });
    const call = page.locator('input[name="dg_phone_channel"][value="call"]');
    for (const [typed, expected] of [['+12025550123', '+12025550123'], ['+۹۸۹۱۲۰۰۰۰۰۰۰', '+989120000000']]) {
        await phone.fill(typed);
        assert.equal(await call.isEnabled(), true, typed);
        await call.check();
        assert.equal(await primary.innerText(), labels.getCallCode);
        await primary.click();
        assert.equal(await page.locator('body').getAttribute('data-call-number'), expected);
        assert.equal(await page.locator('[data-call-instructions] input[name="rememberme"]').count(), 0);
        await page.evaluate(() => {
            document.querySelector('[data-call-instructions]').hidden = true;
            document.querySelector('.dg-digits-login-shell').classList.remove('dg-call-code-active');
        });
        assert.equal(await page.getByRole('checkbox', { name: 'Remember me' }).count(), 1);
    }
    await page.locator('input[name="dg_phone_channel"][value="sms"]').check();
    assert.equal(await primary.innerText(), labels.sendSmsCode);
    await phone.fill('+982112345678');
    assert.equal(await primary.innerText(), labels.getCallCode);
    await page.locator('[data-call-unified]').evaluate(widget => { widget.dataset.callAvailable = 'false'; });
    await phone.fill('');
    await phone.fill('+982112345678');
    assert.equal(await primary.getAttribute('data-dg-identity-blocked'), 'true', 'A fixed line cannot submit when the call panel is unavailable');
    await page.locator('[data-call-unified]').evaluate(widget => { widget.dataset.callAvailable = 'true'; });
    await phone.fill('preview@');
    await primary.click();
    await status.locator('svg').waitFor();
    await email.blur();
    await expectState('format', false);
    const slowRequest = page.waitForRequest(request => request.postData()?.includes('slow.example'));
    await email.fill('preview@slow.example');
    await expectState('checking', false);
    await email.blur();
    await slowRequest;
    await primary.click();
    assert.equal(await status.getAttribute('data-tone'), 'warning');
    assert.equal(await form.locator('.dg-username-password-step').count(), 0);
    await email.fill('preview@invalid.example');
    await expectState('invalid', false);
    releaseSlow();
    await expectState('invalid', false);
    assert.equal(await email.getAttribute('aria-invalid'), 'true');
    await email.fill('preview@retry.example');
    await expectState('unavailable', false);
    await status.getByRole('button').click();
    await expectState('valid', true);
    assert.equal(await primary.innerText(), labels.continue);
    assert.equal(await email.getAttribute('aria-invalid'), 'false');
    assert.equal(colors.size, 4);
    await email.pressSequentially('7!!!@@#)(');
    assert.equal(await email.inputValue(), 'preview@retry.example');
    await email.fill('Preview+Tag@Gmail.Com');
    await email.pressSequentially('7!!!@@#)(');
    assert.equal(await email.inputValue(), 'preview+tag@gmail.com');
    await email.fill('Preview+Tag@Gmail.Com!!!@@#)(');
    assert.equal(await email.inputValue(), 'preview+tag@gmail.com', 'Normalize pasted or autofilled email');
    const envelope = form.locator('.dg-login-identity-field svg.dg-auth-icon--email');
    const geometry = await envelope.evaluate(svg => {
        const box = svg.getBoundingClientRect();
        const field = svg.closest('.dg-login-identity-field').getBoundingClientRect();
        const glyph = svg.querySelector('path').getBBox();
        return { width: box.width, height: box.height, center: (box.top + box.bottom - field.top - field.bottom) / 2, glyphRatio: glyph.width / glyph.height };
    });
    assert.equal(geometry.width, geometry.height);
    assert.ok(Math.abs(geometry.center) < 1 && geometry.glyphRatio > 1.5, 'Use a centered, wide envelope glyph');
    await expectState('valid', true);
    await email.fill('Preview+Tag@MX7.Example.com');
    assert.equal(await email.inputValue(), 'preview+tag@mx7.example.com', 'Allow digits inside domain labels');
    await expectState('valid', true);
    await primary.click();
    await form.locator('.dg-username-password-input').waitFor();
    const passwordStep = form.locator('.dg-username-password-step');
    assert.ok(await passwordStep.getByText(labels.skipPasswordCaption, { exact: true }).isVisible());
    assert.ok(await passwordStep.getByRole('button', { name: labels.skipPassword, exact: true }).isVisible());
    await form.locator('.dg-username-login-back').click();
    await email.fill('preview');
    assert.equal(await primary.isEnabled(), true);
    await phone.fill('');
    assert.equal(await primary.getAttribute('data-dg-identity-blocked'), 'true');
}

async function checkBrowserManagedSignIn(browser) {
    for (const method of ['google', 'google-expired', 'fedcm', 'fedcm-retry', 'passkey', 'passkey-race']) {
        const page = await browser.newPage({ viewport: { width: 390, height: 850 } });
        try {
            const google = method.startsWith('google');
            const passkey = method.startsWith('passkey');
            const retry = method.endsWith('-retry');
            const googleExpired = method === 'google-expired';
            const providers = passkey ? [] : [{
                key: google ? 'google' : 'partner',
                label: 'Partner', clientId: 'registered-client', oneTap: true,
                fedcm: 'https://partner.test/fedcm.json', redirect: false
            }];
            const completed = [];
            let prepared = 0;
            let releaseOptions;
            const optionsReleased = new Promise(resolve => { releaseOptions = resolve; });
            await page.route('**/federation/prepare', route => route.fulfill({
                json: { nonce: ++prepared === 1 ? 'bound-challenge' : 'renewed-challenge', expires_in: 600 }
            }));
            await page.route('**/federation/complete', route => {
                completed.push(route.request().postDataJSON());
                if ((retry || googleExpired) && completed.length === 1) {
                    return route.fulfill({ status: 403, json: { code: 'federation_expired' } });
                }
                return route.fulfill({ json: { redirect: '/intended-destination' } });
            });
            await page.route('**/passkey-vendor', async route => {
                const start = route.request().postData().includes('start_passwordless_login');
                if (start && method === 'passkey-race') { await optionsReleased; }
                return route.fulfill({
                    json: start ? { success: true, data: { token: { challenge: 'nonce', rpId: 'digitalogic.test' } } }
                        : { success: true, data: { process: true, process_type: 'login', redirect: '/intended-destination' } }
                });
            });
            await render(page, 'ltr', {}, false, '', {
                federatedLogin: { prepare: '/federation/prepare', complete: '/federation/complete', providers, passkeys: passkey }
            });
            await page.evaluate(() => {
                window.digitalogicBrowserSuccess = 0;
                window.DigitalogicAuthSuccess = () => { window.digitalogicBrowserSuccess++; };
                window.GoogleFixture = {};
                window.google = { accounts: { id: {
                    initialize(options) { window.GoogleFixture.options = options; },
                    renderButton(slot) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = 'GIS fixture';
                        button.addEventListener('click', () => window.GoogleFixture.options.callback({ credential: 'signed-fixture' }));
                        slot.append(button);
                    },
                    prompt() { window.GoogleFixture.prompted = true; },
                    cancel() { window.GoogleFixture.cancelled = true; }
                } } };
                window.IdentityCredential = class {};
                window.PublicKeyCredential.isConditionalMediationAvailable = async () => true;
                window.digits_preparePublicKeyOptions = value => value;
                window.digits_preparePublicKeyCredentials = value => value;
                window.dig_script = { ajax_url: '/passkey-vendor' };
                window.DigitalogicFinishBrowserPasskey = (_form, data) => {
                    window.passkeyDestination = data.redirect;
                    return true;
                };
                window.manualPasskeyClicks = 0;
                document.addEventListener('click', event => {
                    if (event.target.closest('.digits-form_passwordless_login')) { window.manualPasskeyClicks++; }
                });
                Object.defineProperty(navigator.credentials, 'get', { value: async options => {
                    window.credentialOptions = options;
                    return options.identity ? { token: 'signed-fixture' } : { id: 'stored-passkey' };
                } });
            });
            await page.addScriptTag({ path: path.join(__dirname, '..', 'assets', 'js', 'branding', 'browser-signin.js') });
            const button = page.getByRole('button', { name: 'Sign in in the browser with Partner' });
            if (google) {
                await page.waitForFunction(() => window.GoogleFixture.prompted);
                const options = await page.evaluate(() => ({
                    nonce: window.GoogleFixture.options.nonce, auto: window.GoogleFixture.options.auto_select
                }));
                assert.deepEqual(options, { nonce: 'bound-challenge', auto: false });
                const oauth = page.locator('#digits_google_login');
                assert.equal(await oauth.isVisible(), true);
                assert.equal(await page.getByRole('button', { name: 'GIS fixture' }).count(), 0);
                await page.evaluate(() => window.GoogleFixture.options.callback({ credential: 'signed-fixture' }));
                if (googleExpired) {
                    await page.getByText('Google One Tap expired. Use the Google button to continue.').waitFor();
                    assert.equal(completed.length, 1);
                    assert.equal(prepared, 1);
                    assert.equal(await page.evaluate(() => window.digitalogicBrowserSuccess), 0);
                    await oauth.click();
                    assert.equal(await page.evaluate(() => window.GoogleFixture.cancelled), true);
                    continue;
                }
            } else if (!passkey) {
                await button.click();
                const request = await page.evaluate(() => window.credentialOptions.identity);
                assert.equal(request.mode, 'active');
                assert.deepEqual(request.providers[0].params, { nonce: 'bound-challenge' });
            } else {
                const vendorRequested = method === 'passkey-race' ? page.waitForRequest('**/passkey-vendor') : undefined;
                await page.locator('input[name="digits_phone"]').click();
                if (method === 'passkey-race') {
                    try {
                        await vendorRequested;
                        await page.locator('.dg-passkey-options summary').click();
                        const explicit = page.locator('.digits-form_passwordless_login');
                        await explicit.click();
                        await explicit.click();
                        assert.equal(await page.evaluate(() => window.manualPasskeyClicks), 0);
                    } finally { releaseOptions(); }
                    await page.waitForFunction(() => window.manualPasskeyClicks === 1);
                    assert.equal(await page.evaluate(() => window.credentialOptions), undefined);
                    continue;
                }
                await page.waitForFunction(() => window.passkeyDestination === '/intended-destination');
                assert.equal(await page.evaluate(() => window.credentialOptions.mediation), 'conditional');
                continue;
            }
            const expected = [{ nonce: 'bound-challenge', credential: 'signed-fixture' }];
            if (retry) {
                await page.getByText('A new sign-in request is ready.').waitFor();
                assert.equal(prepared, 2);
                assert.equal(completed.length, 1, 'A fresh browser response requires another selection');
                await button.click();
                expected.push({ nonce: 'renewed-challenge', credential: 'signed-fixture' });
            }
            await page.waitForFunction(() => window.digitalogicBrowserSuccess === 1);
            assert.deepEqual(completed.map(({ nonce, credential }) => ({ nonce, credential })), expected);
        } finally { await page.close(); }
    }
}

async function checkStoryDrawer(browser, direction, labels) {
    const page = await browser.newPage({ viewport: { width: 1050, height: 850 } });
    try {
        let showStories;
        const stories = new Promise(resolve => { showStories = resolve; });
        await page.route('**/fixture-stories?*', async route => {
            await stories;
            await route.fulfill({ json: ['First', 'Second'].map(title => ({ title, image: '/story-fixture.svg' })) });
        });
        await page.route('**/story-fixture.svg', route => route.fulfill({
            contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 260"><path fill="#075fa3" d="M0 0h400v260H0z"/></svg>'
        }));
        await render(page, direction, labels, false, '', { loginSlidesUrl: '/fixture-stories' });
        const shell = page.locator('.dg-has-story-drawer');
        const toggle = shell.locator('.dg-story-drawer-toggle');
        await page.waitForFunction(() => document.querySelector('.dg-story-drawer-toggle')?.getAttribute('aria-expanded') === 'true');
        assert.equal(await shell.locator('.dg-story-loading').isVisible(), true);
        showStories();
        await shell.locator('.dg-story-loading').waitFor({ state: 'hidden' });
        await shell.evaluate(node => Promise.all(node.getAnimations({ subtree: true })
            .filter(animation => ['width', 'margin-left', 'transform'].includes(animation.transitionProperty))
            .map(animation => animation.finished.catch(() => {}))));
        const expanded = await toggle.boundingBox();
        const drawer = shell.locator('.dg-story-drawer');
        const panel = await drawer.boundingBox();
        assert.ok(expanded.height > expanded.width && expanded.height < 56, 'The handle has a compact vertical shape');
        assert.ok(Math.abs(expanded.x + expanded.width / 2 - panel.x - panel.width) <= 1, 'The handle straddles the right border');
        assert.ok(Math.abs(expanded.y + expanded.height / 2 - panel.y - panel.height / 2) <= 1, 'The handle is vertically centered');
        const chevron = toggle.locator('.dg-story-toggle-chevron');
        assert.equal(await chevron.evaluate(node => getComputedStyle(node).opacity), '0');
        await toggle.hover();
        await page.waitForFunction(() => getComputedStyle(document.querySelector('.dg-story-toggle-chevron')).opacity === '1');
        await page.mouse.move(0, 0);
        const track = shell.locator('.dg-story-track');
        const gap = await track.evaluate(element => {
            const [a, b] = element.children;
            return b.getBoundingClientRect().left - a.getBoundingClientRect().right;
        });
        assert.ok(Math.abs(gap - 8) < 0.25, 'The slide spacing is included in both LTR and RTL geometry');

        const rotation = shell.locator('.dg-story-rotation');
        await page.waitForFunction(() => parseFloat(getComputedStyle(document.querySelector('.dg-story-progress path')).strokeDashoffset) < 0.97);
        const readProgress = () => rotation.locator('.dg-story-progress path').evaluate(path => parseFloat(getComputedStyle(path).strokeDashoffset));
        await rotation.evaluate(button => button.click());
        const paused = await readProgress();
        await page.waitForTimeout(125);
        assert.equal(await readProgress(), paused);
        await rotation.evaluate(button => button.click());
        await page.waitForFunction(previous => parseFloat(getComputedStyle(document.querySelector('.dg-story-progress path')).strokeDashoffset) < previous - 0.01, paused);

        await toggle.click();
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(await page.evaluate(() => localStorage.getItem('digitalogic-login-stories-collapsed')), '1');
        await page.waitForFunction(() => {
            const drawer = document.querySelector('.dg-story-drawer');
            return drawer?.clientWidth === 0 && getComputedStyle(drawer).visibility === 'hidden';
        });
        const grip = await toggle.boundingBox();
        const closedCard = await shell.boundingBox();
        assert.ok(Math.abs(grip.x + grip.width / 2 - closedCard.x) <= 1, 'The reopen handle straddles the left edge');
        assert.ok(Math.abs(grip.y + grip.height / 2 - closedCard.y - closedCard.height / 2) <= 1, 'The reopen handle is vertically centered');
        const x = grip.x + grip.width / 2;
        const y = grip.y + grip.height / 2;
        const movement = 96;
        await page.mouse.move(x, y);
        await page.mouse.down();
        await page.mouse.move(x + movement, y, { steps: 12 });
        const moved = await toggle.boundingBox();
        assert.ok(Math.abs(moved.x - grip.x - movement) < 1, 'The button follows the pointer at 1:1 scale');
        assert.equal(await drawer.evaluate(node => getComputedStyle(node).visibility), 'visible');
        await page.mouse.up();
        assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
        assert.equal(await page.evaluate(() => localStorage.getItem('digitalogic-login-stories-collapsed')), '0');
        if (direction === 'rtl') {
            await toggle.click();
            await page.waitForFunction(() => document.querySelector('.dg-story-drawer')?.clientWidth === 0);
            await page.evaluate(() => {
                window.storyVibrations = [];
                Object.defineProperty(navigator, 'vibrate', {
                    configurable: true, value: pattern => window.storyVibrations.push(pattern)
                });
            });
            const cdp = await page.context().newCDPSession(page);
            await cdp.send('Emulation.setTouchEmulationEnabled', { enabled: true });
            const button = await toggle.boundingBox();
            const touch = { x: button.x + button.width / 2, y: button.y + button.height / 2 };
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [touch] });
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ ...touch, x: touch.x + 40 }] });
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ ...touch, x: touch.x + 112 }] });
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
            assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
            const pulses = await page.evaluate(() => window.storyVibrations);
            assert.ok(pulses.includes(7) && pulses.includes(11) && pulses.some(pulse => Array.isArray(pulse) && pulse[0] === 14));
        }
        await page.evaluate(() => window.DigitalogicAuthSuccess(() => { document.body.dataset.redirected = 'yes'; }));
        await page.waitForFunction(() => document.querySelector('.dg-story-drawer-toggle')?.disabled);
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(await page.evaluate(() => localStorage.getItem('digitalogic-login-stories-collapsed')), '0');
        await page.waitForFunction(() => document.body.dataset.redirected === 'yes');
        await page.setViewportSize({ width: 390, height: 850 });
        assert.equal(await drawer.isVisible(), false);
        assert.equal(await toggle.isVisible(), false);
    } finally { await page.close(); }
}

async function checkStepLocation(page) {
    await page.waitForURL('**/#login');
    const phone = page.locator('[name="digits_phone"]');
    await phone.fill('known-user');
    const navigate = async (hash) => {
        await page.evaluate(value => { window.location.hash = value; }, hash);
    };
    await navigate('password');
    await page.locator('.dg-username-password-input').waitFor();
    await page.locator('.dg-username-login-back').click();
    await page.waitForURL('**/#login');
    assert.equal(await phone.inputValue(), 'known-user');

    await navigate('recover');
    await page.locator('.dg-recovery-heading').waitFor();
    await page.waitForURL('**/login/?recover=1#recover');
    await navigate('login');
    await page.waitForURL('**/#login');
    assert.equal(await page.locator('.dg-recovery-heading').count(), 0);
    assert.equal(new URL(page.url()).searchParams.has('recover'), false);

    await navigate('contact');
    await page.locator('form[data-dg-sms-identity="true"]').waitFor();
    await page.waitForURL('**/#contact');
    await navigate('code');
    await page.waitForURL('**/#contact');
    assert.equal(await page.locator('.dg-otp-editor').count(), 0, 'A link cannot create a verification challenge');
}

async function checkCountryPicker(page) {
    const phone = page.locator('[name="digits_phone"]');
    const code = page.locator('[name="login_digt_countrycode"]');
    const primary = page.locator('.digits-form_login > .digits-form_submit-btn');
    const trigger = page.locator('.dg-country-trigger');
    const menu = page.getByRole('listbox');
    const focusedCode = () => page.evaluate(() => document.activeElement.dataset.code);
    const focusCode = country => menu.locator(`[data-code="${country}"]:focus`).waitFor();

    await phone.fill('09120000000');
    await code.click();
    await code.locator('xpath=self::*[@data-dg-code-selected]').waitFor();
    const corners = await code.evaluate(input => getComputedStyle(input).borderRadius);
    assert.equal(corners, '7px');
    await code.press('ArrowDown');
    await focusCode('+98');
    await page.keyboard.press('ArrowDown');
    assert.equal(await focusedCode(), '+1');
    await page.keyboard.press('End');
    assert.equal(await focusedCode(), '+93');
    assert.ok(await menu.evaluate(list => list.scrollTop > 0), 'Keyboard navigation scrolls the list');
    await page.keyboard.press('ArrowUp');
    assert.equal(await focusedCode(), '+964');
    await page.keyboard.press('Escape');
    assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
    assert.ok(await trigger.evaluate(button => button === document.activeElement));
    assert.equal(await code.inputValue(), '+98', 'Escaping preserves the selected code');

    await code.fill('+۰');
    assert.equal(await code.inputValue(), '+', 'A country calling code cannot start with zero');
    assert.equal(await primary.getAttribute('data-dg-identity-blocked'), 'true');
    await code.fill('+999');
    await code.press('Tab');
    assert.equal(await code.getAttribute('aria-invalid'), 'true');
    assert.ok(await page.locator('#dg-country-code-status').isVisible());
    assert.equal(await primary.getAttribute('data-dg-identity-blocked'), 'true');
    await code.fill('۰۰۳۵۸');
    await code.press('Tab');
    assert.equal(await code.inputValue(), '+358', 'Normalize Persian digits and the international 00 prefix');
    assert.equal(await code.getAttribute('aria-invalid'), 'false', 'Accept allocated codes beyond the shortcut menu');
    assert.equal(await page.locator('#dg-country-code-status').isVisible(), false);

    await trigger.press('ArrowDown');
    await focusCode('+98');
    await page.keyboard.press('End');
    await page.keyboard.press('Tab');
    assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
    assert.ok(await phone.evaluate(input => input === document.activeElement), 'Tab advances to the phone number');
    await trigger.press('Enter');
    await focusCode('+98');
    await page.keyboard.press('Space');
    assert.equal(await code.inputValue(), '+98');
    assert.equal(await primary.isEnabled(), true);
    assert.ok(await phone.evaluate(input => input === document.activeElement));
    await page.locator('.dg-country-flag').click();
    assert.ok(await phone.evaluate(input => input === document.activeElement));
}

async function checkSmsAction(page, labels) {
    let attempts = 0;
    let releaseFailure;
    const pendingFailure = new Promise(resolve => { releaseFailure = resolve; });
    await page.route('https://digitalogic.test/wp-admin/admin-ajax.php', async route => {
        if (route.request().postData().includes('digitalogic_otp_completion')) {
            return route.fulfill({ json: { success: true, data: { pending: true } } });
        }
        if (route.request().postData().includes('name="sms_otp"')) {
            assert.match(route.request().postData(), /name="rememberme"\r\n\r\n1/);
            attempts++;
            if (attempts === 1) {
                await pendingFailure;
                return route.fulfill({ status: 503, body: 'error' });
            }
            if (attempts === 2) return route.fulfill({ json: { success: false, data: { message: 'Code expired. Request a new code.' } } });
            return route.fulfill({ json: { success: true, data: { redirect: '/complete' } } });
        }
        assert.match(route.request().postData(), /name="rememberme"\r\n\r\n1/);
        return route.fulfill({ json: {
            success: true,
            data: { resend_timer: 2, html: `<div class="digits-form_tab_container"><div class="digits-form_tab_body digits-tab_active">
            <button type="button" class="digits-form_otp_selector" data-type="email_otp">Email</button>
            <button type="button" class="digits-form_otp_selector" data-type="sms_otp">SMS</button>
        </div></div>` }
        } });
    });
    await page.route('https://digitalogic.test/complete', route => route.fulfill({ body: 'Signed in' }));
    await page.evaluate(() => document.addEventListener('click', event => {
        const choice = event.target.closest('.digits-form_otp_selector');
        if (!choice) { return; }
        document.body.dataset.selectedChannel = choice.dataset.type;
        const tab = choice.parentElement;
        tab.innerHTML = '<div class="digits-form_input_row"><div class="digits-form_input digits-form_input_info digits_auto_check">' +
            '<input name="sms_otp" class="otp_input digits_otp_input-field"><input name="otp_step_1" type="hidden" value="1">' +
            '<input name="otp_token_key" type="hidden" value="fixture"></div></div>' +
            '<button type="button" class="digits-form_resend_otp digits_resend_disabled">Resend</button>';
        // Digits can add the tooltip after the tab and its code field are inserted.
        setTimeout(() => {
            tab.querySelector('.digits-form_input_info').insertAdjacentHTML('beforeend',
                '<span class="digits_otp_info"><span class="digits_otp_info_ic"></span><span class="digits_otp_info_desc">Code delivery information and phone number</span></span>');
        }, 0);
    }));
    await page.getByRole('checkbox', { name: 'Remember me' }).check();
    await page.locator('input[name="digits_phone"]').fill('09120000000');
    await page.getByRole('button', { name: labels.sendSmsCode, exact: true }).click();
    const first = page.locator('.dg-otp-slot input').first();
    await first.waitFor();
    assert.equal(await page.locator('body').getAttribute('data-selected-channel'), 'sms_otp');
    const caption = page.locator('.dg-otp-instructions');
    await caption.locator('.digits_otp_info_ic').waitFor();
    const digits = page.locator('.dg-otp-slot input:visible');
    assert.equal(await digits.count(), 6);
    assert.equal(await page.locator('.digits-form_input_info > input[name="otp_token_key"]').inputValue(), 'fixture');
    assert.equal(await page.locator('.digits-form_input_info > input[name="sms_otp"]').getAttribute('aria-hidden'), 'true');
    await page.addStyleTag({ content: '#login .dg-digits-login-shell .digits-tab_active { display: flex !important; }' });
    const { captionBox, firstBox, lastBox } = await first.evaluate(input => {
        const stack = input.closest('.dg-otp-stack');
        const digits = stack.querySelectorAll('.dg-otp-slot input');
        // Read all positions in the same animation frame as the OTP step enters.
        return {
            captionBox: stack.querySelector('.dg-otp-instructions').getBoundingClientRect().toJSON(),
            firstBox: input.getBoundingClientRect().toJSON(),
            lastBox: digits[digits.length - 1].getBoundingClientRect().toJSON()
        };
    });
    assert.ok(captionBox.y + captionBox.height + 8 <= firstBox.y, 'Caption must stay above the code even in a flex provider tab');
    assert.ok(Math.abs(firstBox.y - lastBox.y) < 1, 'Digits stay in one row');
    const resend = page.locator('.dg-otp-resend');
    await resend.and(page.locator('[aria-disabled="true"]')).waitFor();
    assert.match(await resend.evaluate(el => getComputedStyle(el).backgroundImage), /conic-gradient/);
    await page.waitForFunction(() => parseFloat(document.querySelector('.dg-otp-resend').style.getPropertyValue('--dg-resend-progress')) > 0);
    await resend.and(page.locator('[aria-disabled="false"]')).waitFor();
    assert.equal(await resend.evaluate(el => el.style.getPropertyValue('--dg-resend-progress')), '100%');
    assert.equal(await page.getByRole('checkbox', { name: 'Remember me' }).count(), 0);
    const info = caption.locator('.dg-otp-info-trigger');
    await info.hover();
    const tip = caption.locator('.digits_otp_info_desc');
    await tip.waitFor();
    const bounds = await tip.boundingBox();
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= page.viewportSize().width);
    await info.click();
    assert.equal(await info.evaluate(element => document.activeElement === element), true);
    await first.focus();
    await first.evaluate(el => {
        const data = new DataTransfer();
        data.setData('text/plain', '۱۲٣۴٥۶');
        el.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: data }));
    });
    const verify = page.getByRole('button', { name: labels.verifyCode, exact: true });
    assert.equal(await page.locator('.dg-otp-editor :focus').count(), 0);
    await page.locator('.dg-otp-progress[data-state="verifying"]').waitFor();
    releaseFailure();
    const alert = page.locator('.dg-otp-feedback');
    await alert.waitFor();
    assert.match(await alert.innerText(), /HTTP 503/);
    assert.equal(await page.locator('.dg-otp-editor[aria-invalid]').count(), 0);
    assert.equal(await page.locator('.dg-toast, .dig_popmessage').count(), 0);
    assert.equal(attempts, 1);
    await first.focus();
    await page.keyboard.insertText('۱۲۳۴۵۶');
    assert.equal(attempts, 1, 'Refilling a complete code must wait for explicit submit');
    await page.locator('.dg-otp-progress[data-state="ready"]').waitFor();
    await verify.click();
    const rejectedEditor = page.locator('.dg-otp-editor[aria-invalid="true"]');
    await rejectedEditor.waitFor();
    const rejectedBox = await rejectedEditor.boundingBox();
    const feedbackBox = await alert.boundingBox();
    const resendBox = await resend.boundingBox();
    assert.ok(rejectedBox.width >= firstBox.width * 6 && rejectedBox.y + rejectedBox.height <= feedbackBox.y,
        'Feedback must stay below the full-width PIN editor, including when Digits uses a flex tab');
    assert.ok(feedbackBox.y + feedbackBox.height <= resendBox.y, 'Resend stays below the feedback');
    assert.equal(await alert.innerText(), 'Code expired. Request a new code.');
    assert.equal(await first.evaluate(el => document.activeElement === el), true);
    assert.equal(await page.locator('.dg-toast, .dig_popmessage').count(), 0);
    await verify.click();
    await page.locator('.dg-auth-is-success > section.dg-auth-success .dg-auth-success-icon svg').waitFor();
    const spinner = page.locator('.dg-auth-success-spinner');
    await spinner.waitFor();
    assert.equal(await spinner.getAttribute('aria-hidden'), 'true');
    assert.equal(await spinner.evaluate(element => {
        const caption = element.previousElementSibling.getBoundingClientRect();
        const ring = element.getBoundingClientRect();
        return caption.bottom < ring.top && getComputedStyle(element).animationName === 'dg-fuji-spin';
    }), true);
    assert.equal(await verify.isVisible(), false);
    assert.match(page.url(), /login/);
    await page.waitForURL('**/complete');
}

async function checkOtpContinuity(page, labels, direction) {
    let sends = 0;
    let submits = 0;
    let checkedAttempt = '';
    const destination = 'https://digitalogic.test/complete';
    const token = 'same-provider-challenge';
    await page.route(destination, route => route.fulfill({ body: 'Signed in' }));
    await page.route('https://digitalogic.test/wp-admin/admin-ajax.php', route => {
        const request = route.request().postData();
        if (request.includes('digitalogic_otp_completion')) {
            assert.ok(checkedAttempt);
            assert.ok(request.includes(checkedAttempt));
            return route.fulfill({ json: { success: true, data: { redirect: destination } } });
        }
        if (request.includes('name="sms_otp"')) {
            submits++;
            assert.match(request, /name="sms_otp"\r\n\r\n123456/);
            assert.ok(request.includes(token));
            assert.match(request, /name="rememberme"\r\n\r\n1/);
            checkedAttempt = /name="dg_otp_attempt"\r\n\r\n([0-9a-f-]{36})/.exec(request)?.[1];
            return route.fulfill({ status: 403, body: 'Forbidden' });
        }
        sends++;
        return route.fulfill({ json: { success: true, data: { resend_timer: 30, html: `
            <div class="digits-form_tab_container"><div class="digits-form_body_wrapper">
              <div class="digits-form_tab_body digits-tab_active"><div class="digits-form_input_row">
                <div class="digits-form_input">
                  <input name="sms_otp" class="otp_input" maxlength="6">
                  <input type="hidden" name="otp_token_key" value="${token}">
                </div>
              </div></div>
            </div></div>` } } });
    });
    await render(page, direction, labels, false, '?redirect_to=%2Fcomplete');
    await page.getByRole('checkbox', { name: 'Remember me' }).check();
    await page.locator('input[name="digits_phone"]').fill('09120000000');
    await page.getByRole('button', { name: labels.sendSmsCode, exact: true }).click();
    const cells = page.locator('.dg-otp-slot input');
    await cells.first().waitFor();
    await cells.first().focus();
    await page.keyboard.insertText('98765');
    assert.equal(await cells.first().inputValue(), '9');

    // Reopen the same URL in the same tab. Only provider continuation data survives.
    await render(page, direction, labels, false, '?redirect_to=%2Fcomplete');
    await cells.first().waitFor();
    assert.equal(sends, 1);
    assert.equal(await cells.count(), 6);
    assert.equal(await cells.first().inputValue(), '');
    assert.equal(await page.locator('[name="otp_token_key"]').inputValue(), token);
    await cells.first().focus();
    await page.keyboard.insertText('۱۲۳۴۵۶');
    await page.locator('.dg-auth-is-success > section.dg-auth-success').waitFor();
    assert.equal(await page.locator('.dg-otp-feedback').count(), 0);
    await page.waitForURL(destination);
    assert.equal(submits, 1);
}

async function checkVerificationLabels(page, labels) {
    const phone = page.locator('input[name="digits_phone"]');
    const email = page.locator('input[name="digits_email"]');
    const primary = page.locator('.digits-form_login > .digits-form_submit-btn');
    for (const [field, value, expected, isPhone] of [
        [phone, '', labels.continue],
        [phone, 'sample-user', labels.continue],
        [phone, '۰۹۱۲', labels.continue, true],
        [phone, '۰۹۱۲۰۰۰۰۰۰۰', labels.sendSmsCode, true],
        [phone, '۰۲۱۱۲۳۴۵۶۷۸', labels.getCallCode, true],
        [phone, '+442079460018', labels.getCallCode, true],
        [phone, '+12025550123', labels.continueBySms, true],
        [phone, '+448001111', labels.continue, true],
        [phone, '+989120000000', labels.sendSmsCode, true],
        [phone, 'demo@', labels.continue],
        [email, 'demo@example.com', labels.sendEmailCode],
        [email, 'demo', labels.continue],
        [phone, '', labels.continue]
    ]) {
        await field.fill(value);
        assert.equal(await primary.innerText(), expected, value || '(empty identity)');
        await checkIdentityPrompt(page, isPhone ? labels.phonePlaceholder : labels.contactPlaceholder);
    }
    await phone.fill('09120000000');
    assert.equal(await primary.innerText(), labels.sendSmsCode);
    await page.locator('.dg-country-trigger').click();
    await page.locator('.dg-country-option[data-code="+1"]').click();
    assert.equal(await primary.innerText(), labels.continue, 'The previous SMS label must clear on country changes');
}

async function checkIdentityPrompt(page, expected) {
    const visibleIdentity = page.locator('input:is([name="digits_phone"], [name="digits_email"]):visible');
    assert.equal(await visibleIdentity.getAttribute('placeholder'), expected);
    assert.equal(await visibleIdentity.getAttribute('aria-label'), expected);
}

async function checkPasswordField(page, labels, touch) {
    const field = page.locator('.dg-password-field:visible');
    await field.waitFor({ timeout: 4000 });
    const input = field.locator('input');
    const toggle = field.getByRole('button');
    assert.equal(await field.count(), 1);
    assert.equal(await page.locator('.wp-hide-pw:visible, .digits_password_eye:visible').count(), 0);
    await input.fill('layout-test');
    await input.evaluate(element => element.setSelectionRange(2, 6, 'backward'));
    assert.equal(await toggle.getAttribute('aria-label'), labels.showPassword);
    if (touch) {
        await toggle.tap();
    } else {
        await toggle.click();
    }
    assert.equal(await input.getAttribute('type'), 'text');
    assert.equal(await toggle.getAttribute('aria-label'), labels.hidePassword);
    const revealed = await input.evaluate(element => [
        document.activeElement === element, element.selectionStart, element.selectionEnd, element.selectionDirection
    ]);
    assert.deepEqual(revealed, [true, 2, 6, 'backward']);
    await input.press('Tab');
    assert.ok(await toggle.evaluate(element => document.activeElement === element));
    await page.keyboard.press('Space');
    assert.equal(await input.getAttribute('type'), 'password');
    assert.equal(await toggle.getAttribute('aria-label'), labels.showPassword);
    assert.ok(await toggle.evaluate(element => document.activeElement === element));
    assert.equal(await input.inputValue(), 'layout-test');
    assert.equal(await input.evaluate(element => getComputedStyle(element).direction), 'ltr');
    assert.ok(await toggle.evaluate(element => {
        const button = element.getBoundingClientRect();
        const icon = element.querySelector('svg').getBoundingClientRect();
        const row = element.parentElement.getBoundingClientRect();
        const center = rect => (rect.top + rect.bottom) / 2;
        return Math.abs(center(icon) - center(button)) < 1 && Math.abs(center(button) - center(row)) < 1;
    }));
}

async function checkAlternatives(page, visible) {
    for (const selector of ['.digits-use_social-logins', '#digits_google_login', '.dg-passkey-options summary']) {
        assert.equal(await page.locator(selector).isVisible(), visible, selector);
    }
}

async function checkOtpEditor(page, channel) {
    await page.evaluate(name => {
        const wrapper = document.querySelector('.digits-form_tab_wrapper');
        wrapper.closest('form').classList.remove('digits_form_index_section');
        wrapper.innerHTML = `<div class="digits-form_tab_container"><div class="digits-form_input_row"><div class="digits-form_input"><input name="${name}_otp" class="otp_input" maxlength="6"></div></div></div>`;
    }, channel);
    const cells = page.locator('.dg-otp-slot input');
    await cells.first().waitFor();
    assert.equal(await cells.count(), 6);
    assert.equal(await cells.first().evaluate(element => getComputedStyle(element.closest('.dg-otp-editor')).direction), 'ltr');
    const centers = await cells.evaluateAll(elements => elements.map(element => element.getBoundingClientRect().left));
    assert.ok(centers.every((left, index) => index === 0 || left - centers[index - 1] >= 20), 'All six digits have separate positions');
    assert.ok(await cells.evaluateAll(elements => elements.every(element => {
        const surface = element.closest('.dg-auth-field');
        return element.classList.contains('dg-auth-input') && surface && parseFloat(getComputedStyle(surface).borderTopWidth) >= 1;
    })), 'Every empty OTP cell uses the shared input and visible field border');
    await cells.first().focus();
    const form = page.locator('form.digloginpage');
    const firstSlot = page.locator('.dg-otp-slot').first();
    assert.ok(await firstSlot.evaluate(slot => {
        const style = getComputedStyle(slot);
        return style.boxShadow === 'none' && style.transitionProperty.includes('border-color');
    }), 'OTP focus transitions the shared border without adding a shadow');
    const cursor = () => firstSlot.evaluate(slot => {
        const style = getComputedStyle(slot, '::after');
        return { animation: style.animationName, height: style.height };
    });
    assert.equal(await form.getAttribute('data-dg-otp-cursor'), 'bar');
    assert.deepEqual(await cursor(), { animation: 'dg-otp-bar-pulse', height: '18px' });
    await form.evaluate(element => { element.dataset.dgOtpCursor = 'circle'; });
    assert.deepEqual(await cursor(), { animation: 'dg-otp-caret', height: '12px' });
    await form.evaluate(element => { element.dataset.dgOtpCursor = 'bar'; });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    assert.deepEqual(await cursor(), { animation: 'none', height: '18px' });
    assert.equal(await firstSlot.evaluate(slot => getComputedStyle(slot).transitionProperty), 'none');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    const entry = await cells.first().evaluate(cell => {
        cell.dispatchEvent(new InputEvent('beforeinput', { inputType: 'insertText', data: '۱', cancelable: true }));
        const roll = cell.nextElementSibling;
        return [cell.value, roll?.textContent, roll?.getAttribute('aria-hidden'), roll?.firstElementChild?.getAnimations().length];
    });
    assert.deepEqual(entry, ['1', '1', 'true', 1]);
    await firstSlot.locator('.dg-otp-roll').waitFor({ state: 'detached' });
    await cells.first().focus();
    const replacement = await cells.first().evaluate(cell => {
        cell.dispatchEvent(new InputEvent('beforeinput', { inputType: 'insertText', data: '۲', cancelable: true }));
        return [cell.value, cell.nextElementSibling?.textContent];
    });
    assert.deepEqual(replacement, ['2', '12']);
    await page.keyboard.press('Backspace');
    assert.equal(await cells.first().inputValue(), '');
    assert.equal(await firstSlot.locator('.dg-otp-roll').count(), 0);
    await page.route('https://digitalogic.test/wp-admin/admin-ajax.php', route => route.fulfill({
        status: 503, body: 'Service unavailable'
    }));
    await page.keyboard.insertText('۱۲۳۴۵۶');
    assert.equal(await page.locator(`input[name="${channel}_otp"]`).inputValue(), '123456');
    await page.locator('.dg-otp-feedback').waitFor();
    assert.equal(await page.locator('.dg-otp-editor :focus').count(), 0);
}

async function checkLoginStories(browser) {
    const page = await browser.newPage({ viewport: { width: 390, height: 850 }, reducedMotion: 'reduce' });
    let requests = 0;
    let single = false;
    let titles = ['قطعات', 'ساخت برد', 'تأمین'];
    await page.route('https://digitalogic.test/login/', route => route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: '<html lang="fa-IR" dir="rtl"><body class="login login-action-login"><div id="login"><div class="dg-digits-login-shell"><div class="digits-form_container">Sign in</div></div></div></body></html>'
    }));
    await page.route('https://digitalogic.test/stories?locale=fa_IR', route => {
        requests++;
        return route.fulfill({ json: single ? [{ image: '/story.webp', alt: 'تصویر معرفی' }] : titles.map((title, index) => ({
            image: '/story.webp', image_light: '/light.webp', tab: title, title, description: 'متن از سرور',
            notes: ['نمونه'], action: 'مشاهده', link: '/product/' + index
        })) });
    });
    await page.route(/https:\/\/digitalogic\.test\/(story|light)\.webp$/, route => route.fulfill({
        contentType: 'image/gif',
        body: Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')
    }));
    await page.addInitScript(() => {
        window.DigitalogicBranding = { loginSlidesUrl: '/stories', showLoginThemeSwitcher: true, loginStoryNavThumbs: false };
    });
    async function open() {
        await page.goto('https://digitalogic.test/login/');
        await page.addStyleTag({ path: styles });
        await page.addScriptTag({ path: branding });
        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    }
    await open();
    assert.equal(requests, 0, 'The phone layout does not fetch the presentation');
    await page.setViewportSize({ width: 768, height: 1024 });
    const panel = page.locator('.dg-login-stories');
    await panel.locator('article.is-active').waitFor();
    await panel.locator('.dg-story-loading').waitFor({ state: 'hidden' });
    assert.equal(requests, 1);
    async function assertSingleRow(visibleLabels) {
        await page.waitForFunction(count => [...document.querySelectorAll('.dg-story-controls .dg-story-label')]
            .filter(label => label.getClientRects().length).length === count, visibleLabels);
        await panel.evaluate(node => Promise.all(node.getAnimations()
            .filter(animation => animation.transitionProperty === 'transform')
            .map(animation => animation.finished.catch(() => {}))));
        const controls = panel.locator('.dg-story-controls');
        const layout = await controls.evaluate(el => {
            const bounds = el.getBoundingClientRect();
            return [...el.querySelectorAll('button:not([hidden])')].map(button => {
                const rect = button.getBoundingClientRect();
                return {
                    centeredAt: (rect.top + rect.bottom) / 2,
                    inside: rect.width > 0 && rect.left >= bounds.left - 1 && rect.right <= bounds.right + 1,
                };
            });
        });
        assert.ok(layout.length > 1 && layout.every(button => button.inside && Math.abs(button.centeredAt - layout[0].centeredAt) < 1));
        assert.equal(await controls.locator('.dg-story-label:visible').count(), visibleLabels);
    }
    await assertSingleRow(0);
    await page.setViewportSize({ width: 1280, height: 1024 });
    await assertSingleRow(3);
    await page.setViewportSize({ width: 768, height: 1024 });
    assert.equal(await panel.locator('article:not([inert]) h2').textContent(), 'قطعات');
    assert.equal(await page.locator('#login > .dg-login-preferences').count(), 1);
    const image = panel.locator('article:not([inert]) .dg-story-image[src^="blob:"]');
    await image.waitFor();
    const lightSource = await image.getAttribute('src');
    const theme = page.locator('.dg-login-preferences .dg-theme-toggle-button');
    await theme.click();
    await Promise.all([
        page.waitForResponse('**/story.webp'),
        page.locator('[data-dg-theme-choice="dark"]').click()
    ]);
    await page.waitForFunction(previous => {
        const source = document.querySelector('.dg-story-card.is-active .dg-story-image')?.getAttribute('src');
        return source?.startsWith('blob:') && source !== previous;
    }, lightSource);
    assert.equal(await panel.locator('.dg-story-rotation').isVisible(), false);
    await panel.locator('.dg-story-dot').first().press('ArrowLeft');
    assert.equal(await panel.locator('article:not([inert]) h2').textContent(), 'ساخت برد');
    assert.equal(await panel.getByRole('link', { name: 'مشاهده' }).getAttribute('href'), 'https://digitalogic.test/product/1');
    assert.equal(await panel.locator('article[inert]:not([data-dg-clone])').count(), 2);
    assert.equal(await panel.locator('.dg-story-dot').count(), 3);
    assert.equal(await panel.locator('.dg-story-thumb').count(), 0);
    await panel.locator('.dg-story-dot').first().click();
    assert.equal(await panel.locator('.dg-story-dot[aria-pressed="true"]').count(), 1);
    await panel.getByRole('button', { name: 'اسلاید قبلی' }).click();
    assert.equal(await panel.locator('article:not([inert]) h2').textContent(), 'تأمین');
    const bounds = await panel.locator('.dg-story-stage').boundingBox();
    await page.mouse.move(bounds.x + 40, bounds.y + 60);
    await page.mouse.down();
    await page.mouse.move(bounds.x + 175, bounds.y + 60, { steps: 6 });
    assert.ok((await panel.locator('.dg-story-track').getAttribute('style')).includes('--dg-story-drag'));
    await page.mouse.up();
    assert.equal(await panel.locator('article:not([inert]) h2').textContent(), 'قطعات');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    const rotation = panel.locator('.dg-story-rotation');
    await rotation.waitFor();
    const spacing = await rotation.evaluate(button => {
        const box = button.getBoundingClientRect();
        return {
            fromEnd: box.left - button.parentElement.getBoundingClientRect().left,
            fromArrows: button.previousElementSibling.getBoundingClientRect().left - box.right,
        };
    });
    assert.ok(Math.abs(spacing.fromEnd) < 1 && spacing.fromArrows > 24, JSON.stringify(spacing));
    await page.setViewportSize({ width: 390, height: 850 });
    await panel.waitFor({ state: 'hidden' });
    titles = Array.from({ length: 8 }, (_, index) => 'عنوان کامل محصول ' + (index + 1));
    await page.setViewportSize({ width: 720, height: 1024 });
    await open();
    await panel.locator('article.is-active').waitFor();
    await panel.locator('.dg-story-loading').waitFor({ state: 'hidden' });
    await assertSingleRow(0);
    assert.equal(await panel.locator('.dg-story-dot:visible').count(), 8);
    assert.equal(await rotation.isVisible(), true);
    single = true;
    await page.setViewportSize({ width: 768, height: 1024 });
    await open();
    await panel.locator('article.is-active').waitFor();
    await panel.locator('.dg-story-loading').waitFor({ state: 'hidden' });
    assert.equal(await panel.locator('article img[alt="تصویر معرفی"]').count(), 1);
    assert.equal(await panel.locator('.dg-story-controls, .dg-story-stage[data-draggable]').count(), 0);
    await page.close();
}

async function checkStoryAssetProgress(browser) {
    const frame = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');
    const pending = new Map();
    const server = createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/stories') {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify([
                { title: 'Known length', image: '/known.gif', thumbnail: '/thumb.gif' },
                { title: 'Unknown length', image: '/unknown.gif' }
            ]));
        } else if (url.pathname.endsWith('.gif')) {
            response.setHeader('Content-Type', 'image/gif');
            if (url.pathname !== '/unknown.gif') { response.setHeader('Content-Length', frame.length); }
            if (url.pathname === '/thumb.gif') { response.end(frame); return; }
            pending.set(url.pathname, response);
            response.write(frame.subarray(0, 12));
        } else {
            response.setHeader('Content-Type', 'text/html');
            response.end('<body class="login"><div id="login"><div class="dg-digits-login-shell"><div class="digits-form_container">Sign in</div></div></div></body>');
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const page = await browser.newPage({ viewport: { width: 1050, height: 840 }, reducedMotion: 'no-preference' });
    try {
        await page.addInitScript(() => {
            window.DigitalogicBranding = { loginSlidesUrl: '/stories', loginStoryNavThumbs: true };
        });
        await page.goto('http://127.0.0.1:' + server.address().port);
        await page.addStyleTag({ path: styles });
        await page.addScriptTag({ path: branding });
        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
        await page.waitForFunction(expected => {
            const meter = document.querySelector('.dg-story-card.is-active progress');
            return meter?.value === expected && meter.max > meter.value;
        }, frame.length + 12);
        assert.equal(await page.locator('.dg-story-loading').isVisible(), false);
        const active = page.locator('.dg-story-card.is-active');
        const preview = active.locator('.dg-story-preview[src^="blob:"]');
        const photo = active.locator('.dg-story-image');
        await preview.waitFor();
        assert.equal(await preview.evaluate(node => getComputedStyle(node).filter), 'blur(4px)');
        assert.equal(await photo.evaluate(node => getComputedStyle(node).opacity), '0');
        assert.equal(await active.locator('progress').getAttribute('max'), String(frame.length * 2));
        await page.locator('.dg-story-dot').nth(1).click();
        await active.getByText('12 bytes').waitFor();
        assert.equal(await active.locator('progress').getAttribute('value'), null);
        await page.locator('.dg-story-dot').first().click();
        await photo.evaluate(node => {
            node.addEventListener('transitionrun', event => {
                if (event.propertyName === 'opacity') {
                    node.dataset.fadingOverPreview = getComputedStyle(node.previousElementSibling).visibility;
                }
            }, { once: true });
        });
        for (const response of pending.values()) { response.end(frame.subarray(12)); }
        await active.locator('.dg-story-transfer').waitFor({ state: 'hidden' });
        await preview.waitFor({ state: 'hidden' });
        assert.equal(await photo.getAttribute('data-fading-over-preview'), 'visible');
        assert.equal(await photo.evaluate(node => getComputedStyle(node).opacity), '1');
        assert.equal(await page.locator('.dg-story-thumb-image[src^="blob:"]:visible').count(), 2);
    } finally {
        await page.close();
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}

async function checkLoginSwitchers(browser) {
    for (const showTheme of [false, true]) {
        for (const showLanguage of [false, true]) {
            const page = await browser.newPage({ viewport: { width: 390, height: 800 } });
            const language = '<div class="language-switcher"><form id="language-switcher" method="get">' +
                '<select id="language-switcher-locales" name="wp_lang"><option value="fa_IR">فارسی</option>' +
                '<option value="en_US">English</option></select><input type="hidden" name="redirect_to" value="/shop/">' +
                '<input type="submit" value="Change"></form></div>';
            await page.route('https://digitalogic.test/login/**', route => route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: '<html lang="fa" dir="rtl"><body class="login"><div id="login">' +
                    '<div class="dg-native-auth-card">Sign in</div><p id="backtoblog">' +
                    '<a class="dg-auth-home" data-dg-exit-icon="back" href="/shop/">بازگشت به صفحهٔ قبلی</a></p></div>' +
                    (showLanguage ? language : '') + '</body></html>'
            }));
            await page.goto('https://digitalogic.test/login/');
            await page.evaluate(enabled => { window.DigitalogicBranding = { showLoginThemeSwitcher: enabled }; }, showTheme);
            await page.addStyleTag({ path: styles });
            await page.addScriptTag({ path: branding });
            await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
            const theme = page.locator('.dg-theme-toggle-button');
            const picker = page.locator('.dg-language-trigger');
            const footer = page.locator('#login > .dg-login-preferences');
            assert.equal(await theme.count(), Number(showTheme));
            assert.equal(await picker.count(), Number(showLanguage));
            assert.equal(await footer.locator('#backtoblog a').getAttribute('href'), '/shop/');
            assert.equal(await footer.locator('.dg-auth-icon--back').count(), 1);
            const card = await page.locator('.dg-native-auth-card').boundingBox();
            const footerBox = await footer.boundingBox();
            assert.ok(footerBox.y > card.y + card.height);
            for (const direction of ['ltr', 'rtl']) {
                await page.evaluate(dir => { document.documentElement.dir = dir; }, direction);
                for (const width of [320, 390, 768, 1280]) {
                    await page.setViewportSize({ width, height: 850 });
                    const { row, start, end } = await footer.evaluate((element, dir) => {
                        const rtl = dir === 'rtl';
                        const footerRect = element.getBoundingClientRect();
                        const row = Array.from(element.querySelectorAll(':is(.dg-auth-home, .dg-theme-toggle-button, .dg-language-trigger)'), control => {
                            const rect = control.getBoundingClientRect();
                            return {
                                start: rtl ? rect.right : rect.left,
                                end: rtl ? rect.left : rect.right,
                                center: (rect.top + rect.bottom) / 2,
                                fits: rect.left >= 0 && rect.right <= innerWidth && rect.height >= 44
                            };
                        });
                        return { row, start: rtl ? footerRect.right : footerRect.left, end: rtl ? footerRect.left : footerRect.right };
                    }, direction);
                    assert.equal(row.length, 1 + Number(showTheme) + Number(showLanguage));
                    assert.ok(row.every(control => control.fits && Math.abs(control.center - row[0].center) <= 1), JSON.stringify(row));
                    assert.ok(Math.abs(row[0].start - start) <= 1, 'The exit link belongs at the start of the row');
                    if (row.length > 1) {
                        assert.ok(Math.abs(row.at(-1).end - end) <= 1, 'The settings belong at the end of the row');
                    }
                }
            }
            if (showTheme) {
                await theme.click();
                assert.equal(await page.locator('[data-dg-theme-choice="auto"]').getAttribute('aria-pressed'), 'true');
                await page.emulateMedia({ colorScheme: 'dark' });
                await page.waitForFunction(() => document.documentElement.dataset.dgTheme === 'dark');
                await page.locator('[data-dg-theme-choice="dark"]').click();
                assert.equal(await page.locator('html').getAttribute('data-dg-theme'), 'dark');
                await theme.click();
                await page.locator('[data-dg-theme-choice="light"]').click();
                assert.equal(await page.locator('html').getAttribute('data-dg-theme'), 'light');
            }
            if (showLanguage) {
                await picker.click();
                await page.locator('.dg-language-menu').getByRole('option', { name: 'English' }).click();
                await page.waitForURL(url => url.searchParams.get('wp_lang') === 'en_US' &&
                    url.searchParams.get('redirect_to') === '/shop/');
            }
            await page.close();
        }
    }
}

(async () => {
    const browser = await chromium.launch(process.env.DG_BROWSER_CHANNEL ? { channel: process.env.DG_BROWSER_CHANNEL } : {});
    try {
        await checkLoginSwitchers(browser);
        await checkLoginStories(browser);
        await checkStoryAssetProgress(browser);
        await checkBrowserManagedSignIn(browser);
        for (const [direction, width, skipPassword, skipPasswordCaption] of [
            ['rtl', 390, 'ورود بدون رمز عبور', 'رمز خود را فراموش کرده اید؟'],
            ['ltr', 1024, 'Sign in without a password', 'Forgot your password?']
        ]) {
            const labels = {
                skipPassword, skipPasswordCaption,
                identityPlaceholder: direction === 'rtl' ? 'نام کاربری، ایمیل یا شماره تلفن' : 'Username, email, or phone number',
                contactPlaceholder: direction === 'rtl' ? 'ایمیل یا شماره تلفن' : 'Email address or phone number',
                phonePlaceholder: direction === 'rtl' ? 'شماره تلفن' : 'Phone number',
                showPassword: direction === 'rtl' ? 'نمایش رمز عبور' : 'Show password',
                hidePassword: direction === 'rtl' ? 'پنهان کردن رمز عبور' : 'Hide password',
                requiredPassword: direction === 'rtl' ? 'رمز عبور را وارد کنید.' : 'Enter your password.',
                passkeyLogin: direction === 'rtl' ? 'ورود با Passkey' : 'Sign in with a passkey',
                sendSmsCode: direction === 'rtl' ? 'ارسال کد پیامکی' : 'Send SMS code',
                sendEmailCode: direction === 'rtl' ? 'ارسال کد به ایمیل' : 'Send email code',
                continue: direction === 'rtl' ? 'ادامه' : 'Continue',
                getCallCode: direction === 'rtl' ? 'ادامه با تماس تلفنی' : 'Continue by phone call',
                continueBySms: direction === 'rtl' ? 'ادامه با پیامک' : 'Continue by SMS',
                verifyCode: direction === 'rtl' ? 'تأیید کد' : 'Verify code'
            };
            for (const native of [true, false]) {
                const page = await browser.newPage({ viewport: { width, height: 800 }, hasTouch: direction === 'rtl' });
                await render(page, direction, labels, native);
                await checkAlternatives(page, !native);
                if (!native) {
                    const remember = page.getByRole('checkbox', { name: 'Remember me' });
                    assert.equal(await remember.count(), 1);
                    await checkIdentityPrompt(page, labels.identityPlaceholder);
                    const identity = page.locator('input[name="digits_phone"]');
                    await identity.fill('۰۹۱۲');
                    await checkIdentityPrompt(page, labels.phonePlaceholder);
                    await identity.fill('');
                    await checkIdentityPrompt(page, labels.identityPlaceholder);
                    const shell = page.locator('.dg-digits-login-shell');
                    const cardHeight = (await shell.boundingBox()).height;
                    await page.locator('.dg-passkey-options summary').click();
                    await checkIconAndLabel(page.getByRole('button', { name: labels.passkeyLogin, exact: true }), direction, 10);
                    assert.ok(Math.abs((await shell.boundingBox()).height - cardHeight) < 1, 'Opening other methods must not resize the card');
                    await identity.fill('09120000000');
                    await page.locator('.dg-phone-channels').getByRole('radio').last().waitFor();
                    assert.ok(Math.abs((await shell.boundingBox()).height - cardHeight) < 1, 'Showing verification choices must not resize the card');
                    if (direction === 'rtl') {
                        await page.setViewportSize({ width, height: 320 });
                        await identity.fill('');
                        await page.locator('.dg-passkey-options summary').click();
                        await page.waitForFunction(() => {
                            const card = document.querySelector('.dg-digits-login-shell');
                            return card.getBoundingClientRect().bottom <= innerHeight &&
                                !card.matches('[data-dg-reserve-phone], [data-dg-reserve-passkey]');
                        });
                        await page.setViewportSize({ width, height: 800 });
                    }
                    await identity.fill('fixture-user');
                    await page.getByRole('button', { name: labels.continue, exact: true }).click();
                    assert.equal(await remember.count(), 0);
                    await checkAlternatives(page, false);
                    const profile = page.locator('.dg-username-login-summary');
                    const avatar = profile.locator('.dg-username-login-avatar');
                    assert.equal(await avatar.locator('img').count(), 0);
                    assert.equal(await avatar.locator('svg').isVisible(), true);
                    await page.locator('.dg-username-login-back').click();
                    assert.equal(await remember.count(), 1);
                    await checkAlternatives(page, true);
                    await page.locator('input[name="digits_phone"]').fill('known-user');
                    await page.getByRole('button', { name: labels.continue, exact: true }).click();
                    assert.equal(await avatar.locator('img').count(), 0, 'A remembered identity alone cannot display a profile photo');
                    assert.equal(await avatar.locator('svg').isVisible(), true);
                }
                await checkPasswordField(page, labels, direction === 'rtl');
                const password = page.locator('input.dg-password-input:visible');
                await password.fill('   ');
                await password.press('Enter');
                assert.equal(await page.locator('.dg-digits-inline-message').innerText(), labels.requiredPassword);
                assert.ok(await password.evaluate(element => document.activeElement === element));
                await password.fill('layout-test');
                const button = await checkAction(page, labels, direction);
                await button.click();
                await checkAlternatives(page, false);
                if (native) {
                    assert.equal(await page.locator('body').getAttribute('data-sms-tab-selected'), 'true');
                    assert.equal(await page.locator('input[type="password"]').inputValue(), '');
                } else {
                    assert.equal(await page.getByRole('checkbox', { name: 'Remember me' }).count(), 1);
                    assert.equal(await page.locator('form').getAttribute('data-dg-sms-identity'), 'true');
                    assert.equal(await page.locator('input[name="digits_phone"]').evaluate(element => document.activeElement === element), true);
                    await checkIdentityPrompt(page, labels.contactPlaceholder);
                    await checkVerificationLabels(page, labels);
                    await page.locator('.dg-auth-back').click();
                    await checkAlternatives(page, true);
                    await checkIdentityPrompt(page, labels.phonePlaceholder);
                    assert.equal(await page.locator('.dg-auth-back').isVisible(), false);
                    assert.equal(await page.locator('.digits-form_tab_container').count(), 1, 'Digits must have only the identity tab after returning');
                    await page.locator('input[name="digits_phone"]').fill('known-user');
                    await page.getByRole('button', { name: labels.continue, exact: true }).click();
                    await page.locator('.dg-skip-password:visible').click();
                }
                await checkOtpEditor(page, native ? 'sms' : 'email');
                await page.waitForURL('**/#code');
                assert.equal(await page.locator('.digits-form_login > .digits-form_submit-btn').innerText(), labels.verifyCode);
                await checkAlternatives(page, false);
                await page.close();
            }
            const unsupported = await browser.newPage({ viewport: { width, height: 800 } });
            await render(unsupported, direction, labels, false, '', {}, 'unsupported');
            assert.equal(await unsupported.locator('.dg-passkey-options').count(), 0, 'No empty other-methods disclosure');
            assert.equal(await unsupported.locator('.digits-form_passwordless_login').isVisible(), false);
            assert.equal(await unsupported.locator('#digits_google_login').isVisible(), true);
            await unsupported.close();
            const recovery = await browser.newPage({ viewport: { width, height: 800 } });
            await checkStoryDrawer(browser, direction, labels);
            await render(recovery, direction, labels, false, '?recover=1');
            await checkAlternatives(recovery, false);
            await checkIdentityPrompt(recovery, labels.contactPlaceholder);
            await recovery.locator('.dg-auth-back').click();
            await checkAlternatives(recovery, true);
            await checkIdentityPrompt(recovery, labels.identityPlaceholder);
            assert.equal(await recovery.locator('.dg-auth-back').isVisible(), false);
            assert.equal(new URL(recovery.url()).searchParams.has('recover'), false);
            await recovery.close();
            const emailGate = await browser.newPage({ viewport: { width, height: 800 } });
            await render(emailGate, direction, labels, false);
            await checkEmailGate(emailGate, labels);
            await emailGate.close();
            const smsAction = await browser.newPage({ viewport: { width, height: 800 } });
            await render(smsAction, direction, labels, false);
            await checkCountryPicker(smsAction);
            await checkSmsAction(smsAction, labels);
            await smsAction.close();
            const accountPicker = await browser.newPage({ viewport: { width, height: 800 } });
            await checkAccountPicker(accountPicker, direction, labels);
            await accountPicker.close();
            const workstation = await browser.newPage({ viewport: { width, height: 800 } });
            await checkWorkstationPasswordStep(workstation, direction, labels);
            await workstation.close();
            const location = await browser.newPage({ viewport: { width, height: 800 } });
            await render(location, direction, labels, false);
            await checkStepLocation(location);
            await location.close();
            const continuity = await browser.newPage({ viewport: { width, height: 800 } });
            await checkOtpContinuity(continuity, labels, direction);
            await continuity.close();
        }
        console.log('Passwordless actions, OTP, and first-step-only sign-in options passed in RTL and LTR.');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
