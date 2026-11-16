<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

if (!defined('DIGITALOGIC_PLUGIN_URL')) {
    define('DIGITALOGIC_PLUGIN_URL', 'https://digitalogic.test/wp-content/plugins/digitalogic-wp/');
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return !empty($GLOBALS['digitalogic_test_is_user_logged_in']);
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://digitalogic.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_validate_redirect')) {
    function wp_validate_redirect($target, $fallback = '') {
        $host = parse_url($target, PHP_URL_HOST);
        return $host === null || $host === 'digitalogic.test' ? $target : $fallback;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'test-nonce';
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale() {
        return isset($GLOBALS['digitalogic_test_locale'])
            ? (string) $GLOBALS['digitalogic_test_locale']
            : 'en_US';
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return (string) $url;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('df_digits_form_signup')) {
    function df_digits_form_signup() {
        return isset($GLOBALS['digitalogic_test_digits_signup_html'])
            ? (string) $GLOBALS['digitalogic_test_digits_signup_html']
            : '';
    }
}

if (!function_exists('digits_render_new_form')) {
    function digits_render_new_form($details) {
        echo isset($GLOBALS['digitalogic_test_digits_login_html'])
            ? (string) $GLOBALS['digitalogic_test_digits_login_html']
            : '';
    }
}

if (!function_exists('login_footer')) {
    function login_footer($input_id = '') {
        $GLOBALS['digitalogic_test_login_footer_input'] = (string) $input_id;
        $GLOBALS['digitalogic_test_login_footer_filters'] =
            $GLOBALS['digitalogic_test_filters']['login_display_language_dropdown'] ?? [];
        $GLOBALS['digitalogic_test_login_language_args'] =
            apply_filters('login_language_dropdown_args', ['selected' => determine_locale()]);
    }
}

if (!function_exists('get_available_languages')) {
    function get_available_languages() {
        return ['fa_IR', 'de_DE'];
    }
}

if (!function_exists('get_site_transient')) {
    function get_site_transient($name) {
        return $GLOBALS['digitalogic_test_site_transients'][$name] ?? false;
    }
}

require_once dirname(__DIR__) . '/includes/integrations/class-admin-branding.php';
require_once dirname(__DIR__) . '/includes/integrations/class-auth-page.php';

final class AdminBrandingAuthTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_locale']            = 'en_US';
        $GLOBALS['digitalogic_test_is_user_logged_in'] = false;
        $GLOBALS['digitalogic_test_current_user_id']    = 0;
        unset(
            $GLOBALS['digitalogic_test_filters']['login_display_language_dropdown'],
            $GLOBALS['digitalogic_test_login_footer_input'],
            $GLOBALS['digitalogic_test_login_footer_filters']
        );
    }

    public function test_client_config_exposes_the_canonical_login_fallback(): void {
        $method = new ReflectionMethod(Digitalogic_Plugin_Admin_Branding::class, 'client_config');
        $config = $method->invoke(null, true, false);

        $this->assertSame('https://digitalogic.test/login/', $config['loginUrl']);
        $this->assertSame('https://digitalogic.test/' . rest_get_url_prefix() . '/digitalogic/v1/login-slides', $config['loginSlidesUrl']);
        $this->assertSame(array(), $config['rememberedAccount']);
        $this->assertTrue($config['showLoginThemeSwitcher']);
    }

    public function test_login_reuses_only_an_existing_session_on_ordinary_entry(): void {
        $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'existing_session_destination');

        $this->assertSame('', $method->invoke(null, '/checkout/', []));
        $GLOBALS['digitalogic_test_current_user_id'] = 17;
        try {
            $this->assertSame('/checkout/', $method->invoke(null, '/checkout/', []));
            $this->assertSame(home_url('/'), $method->invoke(null, '', []));
            $this->assertSame(home_url('/'), $method->invoke(null, '/login/?wp_lang=fa_IR', []));
            $this->assertSame(home_url('/'), $method->invoke(null, 'https://other.example/checkout/', []));
            foreach (['loggedout', 'reauth', 'interim-login', 'recover'] as $flag) {
                $this->assertSame('', $method->invoke(null, '/checkout/', [$flag => '1']));
            }
        } finally {
            $GLOBALS['digitalogic_test_current_user_id'] = 0;
        }
    }

    public function test_login_stories_use_the_same_localized_cards_on_each_transport(): void {
        $request = new WP_REST_Request(['locale' => 'fa_IR']);
        $rest = Digitalogic_Plugin_Admin_Branding::login_slides($request);
        $commands = Digitalogic_Plugin_Admin_Branding::auth_presentation_commands([], 'websocket');
        $socket = $commands['digitalogic_auth_presentation'];
        $this->assertSame($rest, $socket(['locale' => 'fa_IR'])['slides']);
        $this->assertSame(['components', 'pcb', 'sourcing'], array_column($rest, 'id'));
        $this->assertStringEndsWith('/components-aluminum-light.webp', $rest[0]['image_light']);
        $this->assertNotSame(array_column($rest, 'title'), array_column($socket(['locale' => 'en_US'])['slides'], 'title'));

        $custom = [['image' => '/custom.webp', 'alt' => 'معرفی']];
        $GLOBALS['digitalogic_test_options']['digitalogic_login_story_cards'] = ['fa_IR' => $custom];
        try {
            $this->assertSame($custom, Digitalogic_Plugin_Admin_Branding::login_slides($request));
            $this->assertSame($custom, $socket(['locale' => 'fa_IR'])['slides']);
            $this->assertSame(['components', 'pcb', 'sourcing'], array_column($socket(['locale' => 'en_US'])['slides'], 'id'));
        } finally {
            unset($GLOBALS['digitalogic_test_options']['digitalogic_login_story_cards']);
        }
    }

    public function test_recovery_identity_labels_match_the_wordpress_backend(): void {
        $method = new ReflectionMethod(Digitalogic_Plugin_Admin_Branding::class, 'client_labels');
        $english = $method->invoke(null);

        $GLOBALS['digitalogic_test_locale'] = 'fa_IR';
        $persian = $method->invoke(null);

        $this->assertSame('Username or email address', $english['recoveryIdentity']);
        $this->assertSame('Back', $english['back']);
        $this->assertStringNotContainsString('mobile', strtolower($english['recoveryIdentity']));
        $this->assertSame('نام کاربری یا نشانی ایمیل', $persian['recoveryIdentity']);
        $this->assertSame('بازگشت', $persian['back']);
        $this->assertStringNotContainsString('موبایل', $persian['recoveryIdentity']);
    }

    public function test_raw_digits_registration_output_contains_a_no_javascript_login_fallback(): void {
        $GLOBALS['digitalogic_test_digits_signup_html'] = $this->raw_digits_transition();
        $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'digits_register_form_html');
        $html = $method->invoke(null);

        $this->assert_raw_login_fallback($html);
    }

    public function test_raw_canonical_login_output_contains_the_same_no_javascript_fallback(): void {
        $GLOBALS['digitalogic_test_digits_login_html'] = $this->raw_digits_transition();
        $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'digits_login_form_html');
        $html = $method->invoke(null, '');

        $this->assert_raw_login_fallback($html);
    }

    public function test_custom_footer_suppresses_remote_language_discovery_only_while_rendering(): void {
        $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'render_login_footer');
        $disable = static fn() => false;
        add_filter('digitalogic_login_show_language_switcher', $disable);
        try {
            $method->invoke(null);
        } finally {
            remove_filter('digitalogic_login_show_language_switcher', $disable);
        }
        $config = new ReflectionMethod(Digitalogic_Plugin_Admin_Branding::class, 'client_config');
        $this->assertTrue($config->invoke(null, true, false)['showLoginThemeSwitcher']);

        $this->assertSame('user_login', $GLOBALS['digitalogic_test_login_footer_input']);
        $this->assertCount(1, $GLOBALS['digitalogic_test_login_footer_filters']);
        $this->assertSame(
            '__return_false',
            $GLOBALS['digitalogic_test_login_footer_filters'][0]['callback']
        );
        $this->assertEmpty(
            $GLOBALS['digitalogic_test_filters']['login_display_language_dropdown'] ?? []
        );
    }

    public function test_enabled_language_switcher_uses_only_installed_languages_even_on_a_cache_miss(): void {
        $callback = static fn() => false;
        add_filter('digitalogic_login_show_theme_switcher', $callback);
        try {
            $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'render_login_footer');
            $method->invoke(null);

            $args = $GLOBALS['digitalogic_test_login_language_args'];
            $this->assertEmpty($GLOBALS['digitalogic_test_login_footer_filters']);
            $this->assertSame(['fa_IR', 'de_DE'], $args['languages']);
            $this->assertFalse($args['show_available_translations']);
            $this->assertNotEmpty($args['translations'], 'An empty array would trigger remote discovery.');
            $this->assertSame('en_US', $args['selected']);
            $this->assertEmpty($GLOBALS['digitalogic_test_filters']['login_language_dropdown_args'] ?? []);

            $config = new ReflectionMethod(Digitalogic_Plugin_Admin_Branding::class, 'client_config');
            $this->assertFalse($config->invoke(null, true, false)['showLoginThemeSwitcher']);
        } finally {
            remove_filter('digitalogic_login_show_theme_switcher', $callback);
        }
    }

    private function raw_digits_transition(): string {
        return '<div class="digits-form_footer">'
            . '<a href="#" class="digits-form_toggle_login_register show_login">اکنون وارد شوید</a>'
            . '</div>';
    }

    private function assert_raw_login_fallback(string $html): void {
        $this->assertStringContainsString('href="https://digitalogic.test/login/"', $html);
        $this->assertStringNotContainsString('href="#"', $html);
        $this->assertStringContainsString('class="digits-form_toggle_login_register show_login"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}
