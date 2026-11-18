const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(
	path.join(__dirname, '..', 'assets', 'js', 'product-identity.js'),
	'utf8'
);
const identityCss = fs.readFileSync(path.join(__dirname, '..', 'assets', 'css', 'product-identity.css'), 'utf8');
const experience = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'product-experience.js'), 'utf8');
const experienceCss = fs.readFileSync(path.join(__dirname, '..', 'assets', 'css', 'product-experience.css'), 'utf8');
const variationSelector = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'variation-selector.js'), 'utf8');
const storefrontIcons = fs.readFileSync(path.join(__dirname, '..', 'includes', 'integrations', 'class-digitalogic-storefront-icons.php'), 'utf8');
const productIdentityPhp = fs.readFileSync(path.join(__dirname, '..', 'includes', 'integrations', 'class-product-identity.php'), 'utf8');
const variationSelectorPhp = fs.readFileSync(path.join(__dirname, '..', 'includes', 'integrations', 'class-digitalogic-variation-selector.php'), 'utf8');
const productExperiencePhp = fs.readFileSync(path.join(__dirname, '..', 'includes', 'integrations', 'class-digitalogic-product-experience.php'), 'utf8');

test('selected variations and Woodmart fallback expose the public Product Code safely', () => {
	assert.match(source, /variation\.digitalogic_product_code/);
	assert.match(source, /settings\.singleProductCode/);
	assert.match(source, /کد کالا/);
	assert.doesNotMatch(source, /کد پاتریس/);
	assert.match(source, /value\.textContent = code/);
	assert.match(source, /itemCode\.textContent = text\(child && child\.code\)/);
	assert.match(source, /digitalogic-product-code-item__model/);
	assert.match(source, /digitalogic-product-code-item__code/);
	assert.match(source, /digitalogic-product-code-item__copy/);
	assert.match(source, /navigator\.clipboard\.writeText\(code\)/);
	assert.match(source, /reset_data hide_variation/);
	assert.match(source, /digitalogic:select-product-code/);
	assert.doesNotMatch(source, /setAttribute\('role', 'listitem'\)/);
	assert.doesNotMatch(source, /patris/i);
});

test('a generic Woo SKU is hidden for an exact Code or a variable-product unknown placeholder', () => {
	assert.match(source, /displayed === code/);
	assert.match(source, /digitalogic-duplicate-product-code-sku/);
	assert.match(source, /نامعلوم\|unknown\|n\\\/\?a/);
	assert.match(source, /markDuplicateLoopSkus/);
	assert.match(source, /markDuplicateCustomerSkus/);
	assert.match(source, /\.wd-product-sku/);
	assert.match(source, /\.digitalogic-cart-product-code/);
});

test('variation events stay scoped to their own product and dedicated slot', () => {
	assert.match(source, /\$form\.closest\('\.product'\)/);
	assert.match(source, /var identity = variationSlot\(\$form\)/);
	assert.match(source, /data-digitalogic-hidden-for-variation/);
	assert.match(source, /variationName === '' && variationCode === ''/);
	assert.doesNotMatch(source, /singleIdentity\(\) \|\| variationSlot/);
});

test('product Code cards keep stable button semantics and resist theme button overrides', () => {
	assert.match(identityCss, /\.digitalogic-product-code-grid\s*\{[\s\S]*grid-template-columns:/);
	assert.match(identityCss, /body:not\(\.wp-admin\) \.digitalogic-product-code-item__select\s*\{[\s\S]*background:\s*transparent\s*!important/);
	assert.match(identityCss, /\.digitalogic-product-code-item\.is-selected\s*\{[\s\S]*border:\s*2px solid var\(--dgl-color-primary/);
	assert.match(identityCss, /\.digitalogic-product-code-item\.is-unavailable\s*\{[\s\S]*opacity:\s*\.58/);
	assert.match(source, /child\.available !== false/);
	assert.match(source, /child\.priceText/);
	assert.match(source, /child\.price_text/);
	assert.match(source, /digitalogic-product-code-item__price/);
	assert.match(source, /select\.disabled = true/);
	assert.match(source, /digitalogic-product-code-item__availability/);
	assert.match(identityCss, /@media \(max-width:\s*640px\)[\s\S]*grid-template-columns:\s*1fr/);
	assert.match(identityCss, /\.digitalogic-product-code-item__price-value/);
});

test('server-rendered model cards reconcile with final localized price data', () => {
	assert.match(source, /label\.className = 'digitalogic-product-code-list__label'/);
	assert.match(
		source,
		/if \(identity\) \{[\s\S]*?if \(childCodes\.length\) \{[\s\S]*?fillIdentity\(identity, name, code, isVariable, childCodes, legacyChildReferences\);/
	);
});

test('contextual highlights separate model text from a copyable product Code', () => {
	assert.match(experience, /variation\.digitalogic_product_name \|\| variation\.digitalogic_persian_name/);
	assert.match(experience, /data-digitalogic-context-product-code/);
	assert.match(experience, /navigator\.clipboard\.writeText\(code\)/);
	assert.match(experienceCss, /\.dgl-highlight--product-code/);
	assert.match(experienceCss, /\.dgl-highlight__copy-button/);
});

test('every existing Product Code indicator uses the shared linear barcode contract', () => {
	const barcodePath = storefrontIcons.match(/BARCODE_PATH = '([^']+)'/);
	assert.ok(barcodePath, 'the shared barcode path must remain explicit and reviewable');
	assert.match(barcodePath[1], /^M3 5v14(?:M\d+ 5v14)+$/);
	assert.match(storefrontIcons, /trim\( 'digitalogic-barcode-icon ' \. \$additional_class \)/);
	assert.match(storefrontIcons, /aria-hidden="true" focusable="false"/);
	assert.match(productIdentityPhp, /'barcodeIconPath'\s*=>\s*Digitalogic_Storefront_Icons::barcode_path\(\)/);
	assert.match(productIdentityPhp, /Digitalogic_Storefront_Icons::barcode_svg\(\)/);
	assert.match(source, /productCodeIcon\(settings\.barcodeIconPath\)/);
	assert.match(source, /setAttribute\('class', 'digitalogic-barcode-icon'\)/);
	assert.match(variationSelectorPhp, /'barcodeIconPath'\s*=>\s*Digitalogic_Storefront_Icons::barcode_path\(\)/);
	assert.match(variationSelector, /svgIcon\(data\.barcodeIconPath \|\| '', 'digitalogic-barcode-icon'\)/);
	assert.match(productExperiencePhp, /array\( 'barcode', 'کد کالا', '', 'product_code', true, 'product-code' \)/);
	assert.match(productExperiencePhp, /barcode_svg\( 'dgl-icon' \)/);
	assert.match(productExperiencePhp, /data-digitalogic-copy-product-code>[\s\S]*self::icon\( 'copy' \)/);

	const productCodeRenderers = [source, variationSelector, productIdentityPhp, productExperiencePhp].join('\n');
	assert.doesNotMatch(productCodeRenderers, /M7 3h10a2 2 0 0 1 2 2v14l-7-3-7 3/);
	assert.doesNotMatch(productCodeRenderers, /M5 4h11l3 3v13H5zM15 4v4h4/);
});

test('quantity and add-to-cart controls use the branded blue purchase system', () => {
	assert.match(experienceCss, /\[class\*="quantity-input-product-"\][\s\S]*background:\s*#fff\s*!important/);
	assert.match(experienceCss, /:where\(\.minus, \.plus\)[\s\S]*--dgl-color-primary/);
	assert.match(experienceCss, /\.single_add_to_cart_button::before[\s\S]*mask:/);
	assert.match(experienceCss, /\.single_add_to_cart_button::before[\s\S]*position:\s*static\s*!important/);
	assert.ok(
		experienceCss.lastIndexOf('body.single-product.dgl-product-experience .dgl-product-benefits {\n  display: grid;') >
			experienceCss.lastIndexOf('body.single-product.dgl-product-experience .dgl-product-benefits {\n  display: flex;'),
		'the final benefits layout must remain a grid instead of falling back to flex',
	);
	assert.match(experienceCss, /\.dgl-benefit\s*\{[\s\S]*display:\s*grid;[\s\S]*grid-template-columns:\s*34px minmax\(0, 1fr\)/);
});
