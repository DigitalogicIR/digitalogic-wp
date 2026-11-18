const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright-core');

async function exercise(browser, delayed, direct = false) {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"></head>
        <body class="wp-admin" style="margin:0"><div id="wpwrap" style="max-width:100%;padding:12px;box-sizing:border-box">
        <div id="woocommerce-order-data"></div>
        <div id="digitalogic-order-tracking" class="postbox" style="width:100%;box-sizing:border-box">
          <div class="inside"><div data-dg-order-tracking-slot></div></div>
        </div></div></body></html>`);
    await page.addStyleTag({ path: path.join(__dirname, '../assets/css/admin-workspace.css') });
    await page.addScriptTag({ content: 'window.jQuery = function (callback) { callback(); };' });

    const insertControls = async () => page.evaluate(useOrderData => {
        const section = useOrderData ? document.getElementById('woocommerce-order-data') : document.createElement('section');
        if (!useOrderData) {
            section.id = 'digits-tracking-section';
        }
        section.innerHTML = '<h2>ارسال کد رهگیری پستی</h2><div class="digits-tracking-wrapper"><div class="tracking-row"><input type="text" value="POST-123"><button type="button">ارسال</button></div></div>';
        const wrapper = section.querySelector('.digits-tracking-wrapper');
        window.originalDigitsWrapper = wrapper;
        window.sendClicks = 0;
        wrapper.querySelector('button').addEventListener('click', () => { window.sendClicks += 1; });
        if (!useOrderData) {
            document.getElementById('woocommerce-order-data').appendChild(section);
        }
    }, direct);

    if (!delayed) {
        await insertControls();
    }
    await page.addScriptTag({ path: path.join(__dirname, '../assets/js/admin-order-workspace.js') });
    if (delayed) {
        await insertControls();
    }

    await page.waitForFunction(() => document.querySelectorAll('#digitalogic-order-tracking .digits-tracking-wrapper').length === 1);
    const result = await page.evaluate(() => {
        const destination = document.querySelector('[data-dg-order-tracking-slot]');
        const wrapper = destination.querySelector('.digits-tracking-wrapper');
        wrapper.querySelector('button').click();
        const pageBox = document.documentElement.getBoundingClientRect();
        const metaBox = document.getElementById('digitalogic-order-tracking').getBoundingClientRect();
        return {
            sameNode: wrapper === window.originalDigitsWrapper,
            value: wrapper.querySelector('input').value,
            sendClicks: window.sendClicks,
            total: document.querySelectorAll('.digits-tracking-wrapper').length,
            source: document.querySelectorAll('#woocommerce-order-data .digits-tracking-wrapper').length,
            destination: document.querySelectorAll('#digitalogic-order-tracking .digits-tracking-wrapper').length,
            orphanSection: document.querySelectorAll('#digits-tracking-section').length,
            orderDataPresent: document.querySelectorAll('#woocommerce-order-data').length,
            visible: getComputedStyle(document.getElementById('digitalogic-order-tracking')).display !== 'none',
            noOverflow: document.documentElement.scrollWidth <= window.innerWidth && pageBox.width <= window.innerWidth && metaBox.right <= window.innerWidth,
        };
    });

    assert.deepEqual(result, {
        sameNode: true,
        value: 'POST-123',
        sendClicks: 1,
        total: 1,
        source: 0,
        destination: 1,
        orphanSection: 0,
        orderDataPresent: 1,
        visible: true,
        noOverflow: true,
    });
    assert.deepEqual(errors, []);
    await page.close();
}

(async () => {
    const options = { headless: true };
    if (process.env.CHROME_PATH) {
        options.executablePath = process.env.CHROME_PATH;
    }
    const browser = await chromium.launch(options);
    await exercise(browser, false);
    await exercise(browser, true);
    await exercise(browser, true, true);
    await browser.close();
    console.log('PASS: Digits tracking controls move once, preserve handlers and values, handle delayed insertion, and fit the mobile sidebar.');
})().catch(error => {
    console.error(error);
    process.exit(1);
});
