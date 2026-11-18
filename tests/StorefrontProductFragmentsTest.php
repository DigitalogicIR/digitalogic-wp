<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/integrations/class-digitalogic-storefront-product-fragments.php';
require_once dirname(__DIR__) . '/includes/integrations/class-digitalogic-price-updated-display.php';

if (!function_exists('wc_setup_loop')) {
    function wc_setup_loop($args) { $GLOBALS['woocommerce_loop'] = $args; }
}
if (!function_exists('setup_postdata')) {
    function setup_postdata($post) { $GLOBALS['id'] = $post->ID; }
}
if (!function_exists('wc_get_template_part')) {
    function wc_get_template_part($slug, $name) {
        ($GLOBALS['digitalogic_fragment_test_template'])($slug, $name);
    }
}

final class Digitalogic_Fragment_Test_Product extends WC_Product {
    public function is_visible() {
        $visibility = $this->get_catalog_visibility();
        return 'hidden' !== $visibility
            && ('visible' === $visibility || ($GLOBALS['wp_query']->is_search ? 'search' : 'catalog') === $visibility);
    }
}

final class StorefrontProductFragmentsTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_current_user_id'] = 0;
        $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation_v1'] = 'fragment-generation-1';
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_fragment_test_template'] = static function ($slug, $name) {
            self::assertSame('content', $slug);
            self::assertSame('product', $name);
            $product = $GLOBALS['product'];
            echo '<li class="product post-' . $product->get_id() . '">';
            echo digitalogic_hide_unavailable_price_html('<span class="price">' . $product->get_price() . '</span>', $product);
            echo '</li>';
        };
    }

    private function product(int $id, array $overrides = array()): void {
        $GLOBALS['digitalogic_test_posts'][$id] = array_merge(array(
            'post_type' => 'product', 'post_status' => 'publish', 'post_password' => '',
            'meta' => array('_price' => '12345', '_stock_status' => 'instock', '_digitalogic_patris_stock_hold' => 'protected'),
        ), $overrides);
        $GLOBALS['digitalogic_test_wc_products'][$id] = new Digitalogic_Fragment_Test_Product($id);
    }

    public function test_invalid_and_oversized_ids_fail_before_any_render(): void {
        $GLOBALS['digitalogic_fragment_test_template'] = static function () { self::fail('Invalid input rendered a card.'); };
        foreach (array(null, '12', array(), range(1, 21), array(0), array(-12), array('12foo'), array('1e3'), array('01'), array(1.5), array(true), array(array(1)), array('99999999999999999999')) as $ids) {
            $result = Digitalogic_Storefront_Product_Fragments::read(array('ids' => $ids));
            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame(400, $result->get_error_data()['status']);
        }
        self::assertSame(array(12, 13), Digitalogic_Storefront_Product_Fragments::ids(array('12', 12, 13)));
    }

    public function test_only_exact_public_parent_ids_are_rendered(): void {
        $this->product(12);
        $this->product(13, array('post_status' => 'draft'));
        $this->product(14, array('post_password' => 'protected'));
        $this->product(15, array('post_type' => 'product_variation', 'post_parent' => 12));
        $this->product(16, array('catalog_visibility' => 'hidden'));
        $this->product(17, array('post_type' => 'page'));
        $before = $GLOBALS['digitalogic_test_posts'];
        $result = Digitalogic_Storefront_Product_Fragments::read(array('ids' => range(12, 18)));
        self::assertSame(array(12), array_keys((array) $result['cards']));
        self::assertSame(range(13, 18), $result['removed']);
        self::assertSame($before, $GLOBALS['digitalogic_test_posts']);
        self::assertSame(array(), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_available_price_uses_existing_filter_and_unavailable_price_stays_private(): void {
        $this->product(12);
        $this->product(13, array('meta' => array('_price' => '987654', '_stock_status' => 'outofstock')));
        $result = Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12, 13)));
        $cards = (array) $result['cards'];
        self::assertStringContainsString('12345', $cards[12]);
        self::assertStringNotContainsString('987654', $cards[13]);
        self::assertStringNotContainsString('class="price"', $cards[13]);
        self::assertSame('987654', $GLOBALS['digitalogic_test_posts'][13]['meta']['_price']);
    }

    public function test_current_customer_and_loop_globals_are_preserved(): void {
        $this->product(12);
        $GLOBALS['digitalogic_test_current_user_id'] = 42;
        $saved = array('post' => (object) array('ID' => 900), 'product' => 'original', 'woocommerce_loop' => array('loop' => 7), 'wp_query' => new WP_Query(), 'id' => 900);
        foreach ($saved as $key => $value) { $GLOBALS[$key] = $value; }
        $GLOBALS['digitalogic_fragment_test_template'] = static function () {
            self::assertSame(42, get_current_user_id());
            self::assertSame(12, $GLOBALS['post']->ID);
            self::assertSame(12, $GLOBALS['product']->get_id());
            echo '<li>customer 42</li>';
        };
        $result = Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12)));
        self::assertSame('<li>customer 42</li>', ((array) $result['cards'])[12]);
        foreach ($saved as $key => $value) { self::assertSame($value, $GLOBALS[$key]); }
    }

    public function test_render_failure_restores_globals_and_output_buffer(): void {
        $this->product(12);
        $GLOBALS['product'] = 'original';
        $level = ob_get_level();
        $GLOBALS['digitalogic_fragment_test_template'] = static function () { echo 'partial'; throw new RuntimeException('render failed'); };
        try {
            Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12)));
            self::fail('Expected failure.');
        } catch (RuntimeException $error) {
            self::assertSame('render failed', $error->getMessage());
        }
        self::assertSame('original', $GLOBALS['product']);
        self::assertSame($level, ob_get_level());
    }

    public function test_search_visibility_remains_contextual(): void {
        $this->product(12, array('catalog_visibility' => 'search'));
        self::assertSame(array(12), Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12)))['removed']);
        self::assertSame(array(), Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12), 'context' => 'search'))['removed']);
        self::assertInstanceOf(WP_Error::class, Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12), 'context' => array('search'))));
    }

    public function test_same_origin_rejects_cross_site_cross_scheme_and_cross_port(): void {
        $home = 'https://digitalogic.test/';
        self::assertTrue(Digitalogic_Storefront_Product_Fragments::same_origin(array(), $home));
        self::assertTrue(Digitalogic_Storefront_Product_Fragments::same_origin(array('HTTP_ORIGIN' => 'https://digitalogic.test:443', 'HTTP_SEC_FETCH_SITE' => 'same-origin'), $home));
        foreach (array('https://other.test', 'http://digitalogic.test', 'https://digitalogic.test:8443', 'null', 'https://user@digitalogic.test') as $origin) {
            self::assertFalse(Digitalogic_Storefront_Product_Fragments::same_origin(array('HTTP_ORIGIN' => $origin), $home));
        }
        self::assertFalse(Digitalogic_Storefront_Product_Fragments::same_origin(array('HTTP_SEC_FETCH_SITE' => 'same-site'), $home));
        self::assertFalse(Digitalogic_Storefront_Product_Fragments::same_origin(array('HTTP_SEC_FETCH_SITE' => 'cross-site'), $home));
    }

    public function test_concurrent_catalog_change_rejects_mixed_fragments(): void {
        $this->product(12);
        $GLOBALS['digitalogic_fragment_test_template'] = static function () {
            echo '<li>old generation</li>';
            $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation_v1'] = 'fragment-generation-2';
        };
        $result = Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12)));
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('digitalogic_fragments_generation_changed', $result->get_error_code());
        self::assertSame(409, $result->get_error_data()['status']);
    }

    public function test_generation_read_failure_returns_error_and_never_partial_html(): void {
        $this->product(12);
        $GLOBALS['digitalogic_fragment_test_template'] = static function () {
            echo '<li>must not escape</li>';
            unset($GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation_v1']);
        };
        $result = Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12)));
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('digitalogic_report_generation_unavailable', $result->get_error_code());
        $GLOBALS['digitalogic_fragment_test_template'] = static function () { self::fail('Generation failure must prevent rendering.'); };
        self::assertInstanceOf(WP_Error::class, Digitalogic_Storefront_Product_Fragments::read(array('ids' => array(12))));
    }
}
