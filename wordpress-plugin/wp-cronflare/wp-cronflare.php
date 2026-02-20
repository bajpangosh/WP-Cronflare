<?php
/**
 * Plugin Name: WP Cronflare
 * Plugin URI: https://github.com/Squarebow/Cloudflare-Worker-for-Wordpress-Cron
 * Description: User-friendly Cloudflare Worker + WordPress cron setup helper with endpoint protection and diagnostics.
 * Version: 1.1.0
 * Author: Squarebow
 * License: MIT
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Cronflare_Plugin
{
    private const OPTION_KEY = 'wp_cronflare_settings';
    private const TEST_TRANSIENT_KEY = 'wp_cronflare_test_result';
    private const SETUP_TRANSIENT_KEY = 'wp_cronflare_setup_result';
    private const OAUTH_TEST_TRANSIENT_KEY = 'wp_cronflare_oauth_test_result';
    private const PAGE_SLUG = 'wp-cronflare';
    private const CF_API_BASE = 'https://api.cloudflare.com/client/v4';

    private static $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'protect_wp_cron'], 1);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'maybe_handle_oauth_callback']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_wp_cronflare_run_test', [$this, 'handle_run_test']);
        add_action('admin_post_wp_cronflare_auto_setup', [$this, 'handle_auto_setup']);
        add_action('admin_post_wp_cronflare_oauth_start', [$this, 'handle_oauth_start']);
        add_action('admin_post_wp_cronflare_oauth_disconnect', [$this, 'handle_oauth_disconnect']);
        add_action('admin_post_wp_cronflare_oauth_test', [$this, 'handle_oauth_test']);

        $plugin_basename = plugin_basename(__FILE__);
        add_filter("plugin_action_links_{$plugin_basename}", [$this, 'add_plugin_action_links']);
    }

    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)),
            esc_html__('Settings', 'wp-cronflare')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    public function register_admin_page(): void
    {
        add_options_page(
            __('WP Cronflare', 'wp-cronflare'),
            __('WP Cronflare', 'wp-cronflare'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'wp_cronflare_settings_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->get_default_settings(),
            ]
        );
    }

    public function sanitize_settings($input): array
    {
        $defaults = $this->get_default_settings();
        $output = $defaults;

        if (!is_array($input)) {
            return $output;
        }

        $output['secret_key'] = isset($input['secret_key']) ? sanitize_text_field($input['secret_key']) : '';
        $output['require_auth'] = !empty($input['require_auth']) ? 1 : 0;
        $output['allow_local_loopback'] = !empty($input['allow_local_loopback']) ? 1 : 0;
        $output['cloudflare_api_token'] = isset($input['cloudflare_api_token']) ? sanitize_text_field($input['cloudflare_api_token']) : '';
        $output['cf_oauth_client_id'] = isset($input['cf_oauth_client_id']) ? sanitize_text_field($input['cf_oauth_client_id']) : '';
        $output['cf_oauth_client_secret'] = isset($input['cf_oauth_client_secret']) ? sanitize_text_field($input['cf_oauth_client_secret']) : '';
        $output['cf_oauth_auth_url'] = isset($input['cf_oauth_auth_url']) ? esc_url_raw((string) $input['cf_oauth_auth_url']) : 'https://dash.cloudflare.com/oauth2/auth';
        $output['cf_oauth_token_url'] = isset($input['cf_oauth_token_url']) ? esc_url_raw((string) $input['cf_oauth_token_url']) : 'https://dash.cloudflare.com/oauth2/token';
        $output['cf_oauth_scope'] = isset($input['cf_oauth_scope']) ? sanitize_text_field((string) $input['cf_oauth_scope']) : '';
        $output['cf_oauth_connected_email'] = $defaults['cf_oauth_connected_email'];
        $output['cf_oauth_connected_at'] = $defaults['cf_oauth_connected_at'];
        $output['cf_oauth_refresh_token'] = $defaults['cf_oauth_refresh_token'];
        $output['cf_oauth_expires_at'] = $defaults['cf_oauth_expires_at'];

        if (!empty($input['worker_name'])) {
            $output['worker_name'] = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $input['worker_name']));
        }

        if (!empty($input['cron_expression'])) {
            $output['cron_expression'] = sanitize_text_field((string) $input['cron_expression']);
        }

        $existing = $this->get_settings();
        $output['cf_oauth_connected_email'] = (string) ($existing['cf_oauth_connected_email'] ?? '');
        $output['cf_oauth_connected_at'] = (int) ($existing['cf_oauth_connected_at'] ?? 0);
        $output['cf_oauth_refresh_token'] = (string) ($existing['cf_oauth_refresh_token'] ?? '');
        $output['cf_oauth_expires_at'] = (int) ($existing['cf_oauth_expires_at'] ?? 0);

        return $output;
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'wp-cronflare-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            '1.1.0'
        );

        wp_enqueue_script(
            'wp-cronflare-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            [],
            '1.1.0',
            true
        );
    }

    public function protect_wp_cron(): void
    {
        if (!$this->is_wp_cron_request()) {
            return;
        }

        $settings = $this->get_settings();

        if (empty($settings['require_auth']) || empty($settings['secret_key'])) {
            return;
        }

        $provided = $_SERVER['HTTP_X_WORKER_AUTH'] ?? '';

        if ($provided !== '' && hash_equals((string) $settings['secret_key'], (string) $provided)) {
            return;
        }

        if (!empty($settings['allow_local_loopback']) && $this->is_local_request()) {
            return;
        }

        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden: Missing or invalid X-Worker-Auth header.';
        exit;
    }

    public function handle_run_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-cronflare'));
        }

        check_admin_referer('wp_cronflare_run_test');

        $settings = $this->get_settings();
        $cron_url = add_query_arg('doing_wp_cron', (string) microtime(true), home_url('/wp-cron.php'));

        $args = [
            'timeout' => 10,
            'headers' => [
                'X-Worker-Auth' => (string) ($settings['secret_key'] ?? ''),
                'User-Agent' => 'Cloudflare-Worker-WP-Cron',
                'Cache-Control' => 'no-cache',
            ],
        ];

        $started = microtime(true);
        $response = wp_remote_get($cron_url, $args);
        $elapsed_ms = (int) ((microtime(true) - $started) * 1000);

        $result = [
            'ok' => false,
            'message' => '',
            'code' => 0,
            'elapsed_ms' => $elapsed_ms,
        ];

        if (is_wp_error($response)) {
            $result['message'] = $response->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = trim((string) wp_remote_retrieve_body($response));
            $result['code'] = $code;
            $result['ok'] = ($code >= 200 && $code < 300);
            $result['message'] = substr($body, 0, 240);
        }

        set_transient(self::TEST_TRANSIENT_KEY, $result, 90);

        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public function handle_auto_setup(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-cronflare'));
        }

        check_admin_referer('wp_cronflare_auto_setup');

        $settings = $this->get_settings();
        $token = trim((string) $settings['cloudflare_api_token']);

        if ($token === '' && !empty($settings['cf_oauth_refresh_token'])) {
            $refresh = $this->maybe_refresh_oauth_token($settings);
            if ($refresh['ok']) {
                $settings = $this->get_settings();
                $token = trim((string) $settings['cloudflare_api_token']);
            }
        }

        if ($token === '') {
            $this->set_setup_result(false, 'Add a Cloudflare API Token or connect Cloudflare OAuth first, then save settings.');
            $this->redirect_back();
        }

        $expires_at = (int) ($settings['cf_oauth_expires_at'] ?? 0);
        if ($expires_at > 0 && $expires_at <= (time() + 60) && !empty($settings['cf_oauth_refresh_token'])) {
            $refresh = $this->maybe_refresh_oauth_token($settings);
            if (!$refresh['ok']) {
                $this->set_setup_result(false, 'Cloudflare OAuth refresh failed: ' . $refresh['message']);
                $this->redirect_back();
            }

            $settings = $this->get_settings();
            $token = trim((string) $settings['cloudflare_api_token']);
        }

        $verify = $this->cf_request('GET', '/user/tokens/verify', $token);
        if (!$verify['ok']) {
            $this->set_setup_result(false, 'Cloudflare token verification failed: ' . $verify['message']);
            $this->redirect_back();
        }

        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host === '') {
            $this->set_setup_result(false, 'Could not determine the WordPress site hostname.');
            $this->redirect_back();
        }

        $zone = $this->find_best_zone_for_host($token, $host);
        if (!$zone['ok']) {
            $this->set_setup_result(false, $zone['message']);
            $this->redirect_back();
        }

        $zone_data = $zone['zone'];
        $zone_id = (string) ($zone_data['id'] ?? '');
        $account_id = (string) ($zone_data['account']['id'] ?? '');

        if ($zone_id === '' || $account_id === '') {
            $this->set_setup_result(false, 'Cloudflare zone/account ID missing in API response.');
            $this->redirect_back();
        }

        $worker_name = $settings['worker_name'] ?: $this->default_worker_name($host);
        $route_pattern = $host . '/wp-cron.php*';
        $cron_expression = trim((string) ($settings['cron_expression'] ?: '* * * * *'));

        $secret = (string) $settings['secret_key'];
        if ($secret === '') {
            $secret = wp_generate_password(48, true, true);
            $settings['secret_key'] = $secret;
            update_option(self::OPTION_KEY, $settings);
        }

        $upload = $this->cf_upload_worker_script($token, $account_id, $worker_name, $this->build_worker_script());
        if (!$upload['ok']) {
            $this->set_setup_result(false, 'Worker upload failed: ' . $upload['message']);
            $this->redirect_back();
        }

        $save_url_secret = $this->cf_request('PUT', "/accounts/{$account_id}/workers/scripts/{$worker_name}/secrets", $token, [
            'name' => 'WP_CRON_URL',
            'text' => home_url(),
            'type' => 'secret_text',
        ]);

        if (!$save_url_secret['ok']) {
            $this->set_setup_result(false, 'Failed to save WP_CRON_URL secret: ' . $save_url_secret['message']);
            $this->redirect_back();
        }

        $save_key_secret = $this->cf_request('PUT', "/accounts/{$account_id}/workers/scripts/{$worker_name}/secrets", $token, [
            'name' => 'WP_CRON_KEY',
            'text' => $secret,
            'type' => 'secret_text',
        ]);

        if (!$save_key_secret['ok']) {
            $this->set_setup_result(false, 'Failed to save WP_CRON_KEY secret: ' . $save_key_secret['message']);
            $this->redirect_back();
        }

        $schedule = $this->cf_request('PUT', "/accounts/{$account_id}/workers/scripts/{$worker_name}/schedules", $token, [
            ['cron' => $cron_expression],
        ]);

        if (!$schedule['ok']) {
            $schedule = $this->cf_request('PUT', "/accounts/{$account_id}/workers/scripts/{$worker_name}/schedules", $token, [
                'schedules' => [
                    ['cron' => $cron_expression],
                ],
            ]);
        }

        if (!$schedule['ok']) {
            $this->set_setup_result(false, 'Failed to set cron trigger: ' . $schedule['message']);
            $this->redirect_back();
        }

        $route = $this->upsert_worker_route($token, $zone_id, $route_pattern, $worker_name);
        if (!$route['ok']) {
            $this->set_setup_result(false, 'Failed to set worker route: ' . $route['message']);
            $this->redirect_back();
        }

        $details = sprintf(
            'Auto-setup complete. Zone: %s. Worker: %s. Route: %s. Schedule: %s.',
            (string) ($zone_data['name'] ?? $host),
            $worker_name,
            $route_pattern,
            $cron_expression
        );

        $this->set_setup_result(true, $details);
        $this->redirect_back();
    }

    public function handle_oauth_start(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-cronflare'));
        }

        check_admin_referer('wp_cronflare_oauth_start');

        $settings = $this->get_settings();
        $client_id = trim((string) ($settings['cf_oauth_client_id'] ?? ''));
        $auth_url = trim((string) ($settings['cf_oauth_auth_url'] ?? ''));

        if ($client_id === '' || $auth_url === '') {
            $this->set_setup_result(false, 'Cloudflare OAuth Client ID or Authorization URL is missing.');
            $this->redirect_back();
        }

        $state = wp_generate_password(32, false, false);
        set_transient($this->oauth_state_key(), $state, 10 * MINUTE_IN_SECONDS);

        $params = [
            'response_type' => 'code',
            'client_id' => $client_id,
            'redirect_uri' => $this->oauth_redirect_uri(),
            'state' => $state,
        ];

        $scope = trim((string) ($settings['cf_oauth_scope'] ?? ''));
        if ($scope !== '') {
            $params['scope'] = $scope;
        }

        wp_safe_redirect(add_query_arg($params, $auth_url));
        exit;
    }

    public function handle_oauth_disconnect(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-cronflare'));
        }

        check_admin_referer('wp_cronflare_oauth_disconnect');

        $settings = $this->get_settings();
        $settings['cloudflare_api_token'] = '';
        $settings['cf_oauth_refresh_token'] = '';
        $settings['cf_oauth_expires_at'] = 0;
        $settings['cf_oauth_connected_email'] = '';
        $settings['cf_oauth_connected_at'] = 0;
        update_option(self::OPTION_KEY, $settings);

        $this->set_setup_result(true, 'Cloudflare OAuth connection removed.');
        $this->redirect_back();
    }

    public function handle_oauth_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-cronflare'));
        }

        check_admin_referer('wp_cronflare_oauth_test');

        $settings = $this->get_settings();
        $checks = [];

        $client_id = trim((string) ($settings['cf_oauth_client_id'] ?? ''));
        $client_secret = trim((string) ($settings['cf_oauth_client_secret'] ?? ''));
        $auth_url = trim((string) ($settings['cf_oauth_auth_url'] ?? ''));
        $token_url = trim((string) ($settings['cf_oauth_token_url'] ?? ''));

        $checks[] = [
            'label' => 'Client ID is set',
            'ok' => $client_id !== '',
            'detail' => $client_id !== '' ? 'Present' : 'Missing OAuth Client ID',
        ];
        $checks[] = [
            'label' => 'Client Secret is set',
            'ok' => $client_secret !== '',
            'detail' => $client_secret !== '' ? 'Present' : 'Missing OAuth Client Secret',
        ];
        $checks[] = [
            'label' => 'Authorization URL format',
            'ok' => (bool) filter_var($auth_url, FILTER_VALIDATE_URL),
            'detail' => (bool) filter_var($auth_url, FILTER_VALIDATE_URL) ? 'Valid URL' : 'Invalid authorization URL',
        ];
        $checks[] = [
            'label' => 'Token URL format',
            'ok' => (bool) filter_var($token_url, FILTER_VALIDATE_URL),
            'detail' => (bool) filter_var($token_url, FILTER_VALIDATE_URL) ? 'Valid URL' : 'Invalid token URL',
        ];

        if ($auth_url !== '' && filter_var($auth_url, FILTER_VALIDATE_URL)) {
            $probe_auth = wp_remote_get(add_query_arg([
                'response_type' => 'code',
                'client_id' => $client_id !== '' ? $client_id : 'missing-client-id',
                'redirect_uri' => $this->oauth_redirect_uri(),
                'state' => 'wp-cronflare-oauth-test',
            ], $auth_url), [
                'timeout' => 10,
                'redirection' => 0,
            ]);

            if (is_wp_error($probe_auth)) {
                $checks[] = [
                    'label' => 'Authorization endpoint reachable',
                    'ok' => false,
                    'detail' => $probe_auth->get_error_message(),
                ];
            } else {
                $status = (int) wp_remote_retrieve_response_code($probe_auth);
                $checks[] = [
                    'label' => 'Authorization endpoint reachable',
                    'ok' => $this->is_oauth_probe_status_ok($status),
                    'detail' => 'HTTP ' . $status,
                ];
            }
        }

        if ($token_url !== '' && filter_var($token_url, FILTER_VALIDATE_URL) && $client_id !== '' && $client_secret !== '') {
            $basic = base64_encode($client_id . ':' . $client_secret);
            $probe_token = wp_remote_post($token_url, [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Basic ' . $basic,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => 'wp-cronflare-invalid-code',
                    'redirect_uri' => $this->oauth_redirect_uri(),
                ], '', '&'),
            ]);

            if (is_wp_error($probe_token)) {
                $checks[] = [
                    'label' => 'Token endpoint reachable',
                    'ok' => false,
                    'detail' => $probe_token->get_error_message(),
                ];
            } else {
                $status = (int) wp_remote_retrieve_response_code($probe_token);
                $body = substr(trim((string) wp_remote_retrieve_body($probe_token)), 0, 140);
                $checks[] = [
                    'label' => 'Token endpoint reachable',
                    'ok' => $this->is_oauth_probe_status_ok($status),
                    'detail' => 'HTTP ' . $status . ($body !== '' ? ' - ' . $body : ''),
                ];
            }
        }

        $all_ok = true;
        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                $all_ok = false;
                break;
            }
        }

        set_transient(self::OAUTH_TEST_TRANSIENT_KEY, [
            'ok' => $all_ok,
            'checks' => $checks,
        ], 120);

        $this->redirect_back();
    }

    public function maybe_handle_oauth_callback(): void
    {
        if (!is_admin()) {
            return;
        }

        if (empty($_GET['page']) || (string) $_GET['page'] !== self::PAGE_SLUG) {
            return;
        }

        if (empty($_GET['wp_cronflare_oauth']) || (string) $_GET['wp_cronflare_oauth'] !== 'callback') {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();

        if (!empty($_GET['error'])) {
            $error = sanitize_text_field((string) $_GET['error']);
            $desc = isset($_GET['error_description']) ? sanitize_text_field((string) $_GET['error_description']) : '';
            $this->set_setup_result(false, trim('OAuth authorization failed: ' . $error . ' ' . $desc));
            $this->redirect_back();
        }

        $received_state = isset($_GET['state']) ? sanitize_text_field((string) $_GET['state']) : '';
        $saved_state = (string) get_transient($this->oauth_state_key());
        delete_transient($this->oauth_state_key());

        if ($received_state === '' || $saved_state === '' || !hash_equals($saved_state, $received_state)) {
            $this->set_setup_result(false, 'OAuth state validation failed. Try connecting again.');
            $this->redirect_back();
        }

        $code = isset($_GET['code']) ? sanitize_text_field((string) $_GET['code']) : '';
        if ($code === '') {
            $this->set_setup_result(false, 'OAuth callback is missing authorization code.');
            $this->redirect_back();
        }

        $exchange = $this->oauth_token_request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->oauth_redirect_uri(),
        ], $settings);

        if (!$exchange['ok']) {
            $this->set_setup_result(false, 'OAuth token exchange failed: ' . $exchange['message']);
            $this->redirect_back();
        }

        $token_payload = $exchange['result'];
        $access_token = trim((string) ($token_payload['access_token'] ?? ''));
        if ($access_token === '') {
            $this->set_setup_result(false, 'OAuth token response is missing access_token.');
            $this->redirect_back();
        }

        $settings['cloudflare_api_token'] = $access_token;
        $settings['cf_oauth_refresh_token'] = (string) ($token_payload['refresh_token'] ?? '');
        $settings['cf_oauth_expires_at'] = !empty($token_payload['expires_in']) ? (time() + (int) $token_payload['expires_in']) : 0;
        $settings['cf_oauth_connected_at'] = time();
        $settings['cf_oauth_connected_email'] = '';

        $user_info = $this->cf_request('GET', '/user', $access_token);
        if ($user_info['ok'] && !empty($user_info['result']['email'])) {
            $settings['cf_oauth_connected_email'] = sanitize_email((string) $user_info['result']['email']);
        }

        update_option(self::OPTION_KEY, $settings);

        $connected_label = $settings['cf_oauth_connected_email'] !== '' ? $settings['cf_oauth_connected_email'] : 'Cloudflare account';
        $this->set_setup_result(true, 'Cloudflare OAuth connected: ' . $connected_label . '.');
        $this->redirect_back();
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $status = $this->get_setup_status($settings);
        $test_result = get_transient(self::TEST_TRANSIENT_KEY);
        $setup_result = get_transient(self::SETUP_TRANSIENT_KEY);
        $oauth_test_result = get_transient(self::OAUTH_TEST_TRANSIENT_KEY);

        if ($test_result !== false) {
            delete_transient(self::TEST_TRANSIENT_KEY);
        }

        if ($setup_result !== false) {
            delete_transient(self::SETUP_TRANSIENT_KEY);
        }
        if ($oauth_test_result !== false) {
            delete_transient(self::OAUTH_TEST_TRANSIENT_KEY);
        }

        $cron_url = home_url('/wp-cron.php?doing_wp_cron');
        $wp_cron_key = (string) $settings['secret_key'];
        $oauth_connected = !empty($settings['cloudflare_api_token']) && (!empty($settings['cf_oauth_connected_at']) || !empty($settings['cf_oauth_refresh_token']));
        $oauth_connected_email = (string) ($settings['cf_oauth_connected_email'] ?? '');
        $oauth_connected_at = !empty($settings['cf_oauth_connected_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $settings['cf_oauth_connected_at']) : '';
        $oauth_auth_url = trim((string) ($settings['cf_oauth_auth_url'] ?? ''));
        $oauth_token_url = trim((string) ($settings['cf_oauth_token_url'] ?? ''));
        $oauth_scope = trim((string) ($settings['cf_oauth_scope'] ?? ''));
        $suggested_oauth_scope = 'account.workers.scripts:write zone.workers_routes:write zone:read user:read';
        $oauth_scope_for_copy = $oauth_scope !== '' ? $oauth_scope : $suggested_oauth_scope;
        $oauth_setup_status = [
            'client_id' => !empty($settings['cf_oauth_client_id']),
            'client_secret' => !empty($settings['cf_oauth_client_secret']),
            'auth_url' => (bool) filter_var($oauth_auth_url, FILTER_VALIDATE_URL),
            'token_url' => (bool) filter_var($oauth_token_url, FILTER_VALIDATE_URL),
            'scope' => $oauth_scope !== '',
        ];

        ?>
        <div class="wrap wp-cronflare-wrap">
            <h1><?php echo esc_html__('WP Cronflare', 'wp-cronflare'); ?></h1>
            <p class="wp-cronflare-lead"><?php echo esc_html__('Run WordPress cron through Cloudflare Worker with a cleaner, safer setup flow.', 'wp-cronflare'); ?></p>

            <?php if ($setup_result !== false) : ?>
                <div class="notice <?php echo !empty($setup_result['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p><?php echo esc_html((string) ($setup_result['message'] ?? '')); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($test_result !== false) : ?>
                <div class="notice <?php echo !empty($test_result['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p>
                        <?php
                        echo !empty($test_result['ok'])
                            ? esc_html__('Cron test succeeded.', 'wp-cronflare')
                            : esc_html__('Cron test failed.', 'wp-cronflare');
                        ?>
                        <?php
                        echo ' ' . esc_html(sprintf(
                            __('HTTP %d in %dms. %s', 'wp-cronflare'),
                            (int) ($test_result['code'] ?? 0),
                            (int) ($test_result['elapsed_ms'] ?? 0),
                            (string) ($test_result['message'] ?? '')
                        ));
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($oauth_test_result !== false) : ?>
                <div class="notice <?php echo !empty($oauth_test_result['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p>
                        <?php echo !empty($oauth_test_result['ok']) ? esc_html__('OAuth config test passed.', 'wp-cronflare') : esc_html__('OAuth config test found issues.', 'wp-cronflare'); ?>
                    </p>
                    <?php if (!empty($oauth_test_result['checks']) && is_array($oauth_test_result['checks'])) : ?>
                        <ul style="margin-left:1.2em;list-style:disc;">
                            <?php foreach ($oauth_test_result['checks'] as $check) : ?>
                                <li>
                                    <?php
                                    $label = isset($check['label']) ? (string) $check['label'] : '';
                                    $detail = isset($check['detail']) ? (string) $check['detail'] : '';
                                    $ok = !empty($check['ok']);
                                    echo esc_html(($ok ? 'OK: ' : 'Issue: ') . $label . ' - ' . $detail);
                                    ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="wp-cronflare-grid">
                <section class="wp-cronflare-card">
                    <h2><?php echo esc_html__('Setup Checklist', 'wp-cronflare'); ?></h2>
                    <ul class="wp-cronflare-checklist">
                        <li>
                            <span><?php echo esc_html__('DISABLE_WP_CRON is enabled', 'wp-cronflare'); ?></span>
                            <strong class="state <?php echo $status['wp_cron_disabled'] ? 'ok' : 'warn'; ?>">
                                <?php echo $status['wp_cron_disabled'] ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                            </strong>
                        </li>
                        <li>
                            <span><?php echo esc_html__('Secret key configured', 'wp-cronflare'); ?></span>
                            <strong class="state <?php echo $status['secret_set'] ? 'ok' : 'warn'; ?>">
                                <?php echo $status['secret_set'] ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                            </strong>
                        </li>
                        <li>
                            <span><?php echo esc_html__('Cron endpoint protection enabled', 'wp-cronflare'); ?></span>
                            <strong class="state <?php echo $status['auth_enabled'] ? 'ok' : 'warn'; ?>">
                                <?php echo $status['auth_enabled'] ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                            </strong>
                        </li>
                        <li>
                            <span><?php echo esc_html__('Cloudflare token saved', 'wp-cronflare'); ?></span>
                            <strong class="state <?php echo !empty($settings['cloudflare_api_token']) ? 'ok' : 'warn'; ?>">
                                <?php echo !empty($settings['cloudflare_api_token']) ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                            </strong>
                        </li>
                        <li>
                            <span><?php echo esc_html__('Cloudflare OAuth connected', 'wp-cronflare'); ?></span>
                            <strong class="state <?php echo $oauth_connected ? 'ok' : 'warn'; ?>">
                                <?php echo $oauth_connected ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                            </strong>
                        </li>
                    </ul>
                </section>

                <section class="wp-cronflare-card">
                    <h2><?php echo esc_html__('Settings', 'wp-cronflare'); ?></h2>
                    <form method="post" action="options.php" class="wp-cronflare-form">
                        <?php settings_fields('wp_cronflare_settings_group'); ?>

                        <label for="wp-cronflare-secret"><?php echo esc_html__('Worker Secret Key', 'wp-cronflare'); ?></label>
                        <div class="wp-cronflare-secret-row">
                            <input
                                id="wp-cronflare-secret"
                                type="password"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[secret_key]"
                                value="<?php echo esc_attr($wp_cron_key); ?>"
                                autocomplete="off"
                                class="regular-text"
                            />
                            <button type="button" class="button" data-toggle-secret><?php echo esc_html__('Show', 'wp-cronflare'); ?></button>
                        </div>

                        <label for="wp-cronflare-token"><?php echo esc_html__('Cloudflare API Token', 'wp-cronflare'); ?></label>
                        <div class="wp-cronflare-secret-row">
                            <input
                                id="wp-cronflare-token"
                                type="password"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[cloudflare_api_token]"
                                value="<?php echo esc_attr((string) $settings['cloudflare_api_token']); ?>"
                                autocomplete="off"
                                class="regular-text"
                            />
                            <button type="button" class="button" data-toggle-token><?php echo esc_html__('Show', 'wp-cronflare'); ?></button>
                        </div>

                        <label for="wp-cronflare-oauth-client-id"><?php echo esc_html__('OAuth Client ID', 'wp-cronflare'); ?></label>
                        <input
                            id="wp-cronflare-oauth-client-id"
                            type="text"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[cf_oauth_client_id]"
                            value="<?php echo esc_attr((string) $settings['cf_oauth_client_id']); ?>"
                            class="regular-text"
                        />

                        <label for="wp-cronflare-oauth-client-secret"><?php echo esc_html__('OAuth Client Secret', 'wp-cronflare'); ?></label>
                        <div class="wp-cronflare-secret-row">
                            <input
                                id="wp-cronflare-oauth-client-secret"
                                type="password"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[cf_oauth_client_secret]"
                                value="<?php echo esc_attr((string) $settings['cf_oauth_client_secret']); ?>"
                                autocomplete="off"
                                class="regular-text"
                            />
                            <button type="button" class="button" data-toggle-client-secret><?php echo esc_html__('Show', 'wp-cronflare'); ?></button>
                        </div>

                        <label for="wp-cronflare-oauth-auth-url"><?php echo esc_html__('OAuth Authorization URL', 'wp-cronflare'); ?></label>
                        <input
                            id="wp-cronflare-oauth-auth-url"
                            type="url"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[cf_oauth_auth_url]"
                            value="<?php echo esc_attr((string) $settings['cf_oauth_auth_url']); ?>"
                            class="regular-text"
                        />

                        <label for="wp-cronflare-oauth-token-url"><?php echo esc_html__('OAuth Token URL', 'wp-cronflare'); ?></label>
                        <input
                            id="wp-cronflare-oauth-token-url"
                            type="url"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[cf_oauth_token_url]"
                            value="<?php echo esc_attr((string) $settings['cf_oauth_token_url']); ?>"
                            class="regular-text"
                        />

                        <label for="wp-cronflare-oauth-scope"><?php echo esc_html__('OAuth Scope (optional)', 'wp-cronflare'); ?></label>
                        <input
                            id="wp-cronflare-oauth-scope"
                            type="text"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[cf_oauth_scope]"
                            value="<?php echo esc_attr((string) $settings['cf_oauth_scope']); ?>"
                            class="regular-text"
                        />

                        <label for="wp-cronflare-worker-name"><?php echo esc_html__('Worker Name', 'wp-cronflare'); ?></label>
                        <input
                            id="wp-cronflare-worker-name"
                            type="text"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[worker_name]"
                            value="<?php echo esc_attr((string) $settings['worker_name']); ?>"
                            class="regular-text"
                        />

                        <label for="wp-cronflare-cron"><?php echo esc_html__('Cron Expression', 'wp-cronflare'); ?></label>
                        <input
                            id="wp-cronflare-cron"
                            type="text"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[cron_expression]"
                            value="<?php echo esc_attr((string) $settings['cron_expression']); ?>"
                            class="regular-text"
                        />

                        <p class="description"><?php echo esc_html__('Required token permissions: Workers Scripts Write, Workers Routes Write, Zone Read.', 'wp-cronflare'); ?></p>

                        <label class="checkbox-row">
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_auth]"
                                value="1"
                                <?php checked(!empty($settings['require_auth'])); ?>
                            />
                            <?php echo esc_html__('Require X-Worker-Auth header on wp-cron.php', 'wp-cronflare'); ?>
                        </label>

                        <label class="checkbox-row">
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_local_loopback]"
                                value="1"
                                <?php checked(!empty($settings['allow_local_loopback'])); ?>
                            />
                            <?php echo esc_html__('Allow localhost loopback requests (for diagnostics)', 'wp-cronflare'); ?>
                        </label>

                        <?php submit_button(__('Save Settings', 'wp-cronflare'), 'primary', 'submit', false); ?>
                    </form>
                </section>
            </div>

            <section class="wp-cronflare-card">
                <h2><?php echo esc_html__('Cloudflare OAuth', 'wp-cronflare'); ?></h2>
                <p><?php echo esc_html__('Use OAuth if you want a Sign in with Cloudflare flow instead of manually creating API tokens.', 'wp-cronflare'); ?></p>
                <p><strong><?php echo esc_html__('Redirect URI:', 'wp-cronflare'); ?></strong> <code><?php echo esc_html($this->oauth_redirect_uri()); ?></code></p>
                <?php if ($oauth_connected) : ?>
                    <p class="description">
                        <?php
                        echo esc_html__('Connected account:', 'wp-cronflare') . ' ' . esc_html($oauth_connected_email !== '' ? $oauth_connected_email : __('(no email scope)', 'wp-cronflare'));
                        if ($oauth_connected_at !== '') {
                            echo ' - ' . esc_html__('Connected at:', 'wp-cronflare') . ' ' . esc_html($oauth_connected_at);
                        }
                        ?>
                    </p>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;">
                        <input type="hidden" name="action" value="wp_cronflare_oauth_disconnect" />
                        <?php wp_nonce_field('wp_cronflare_oauth_disconnect'); ?>
                        <?php submit_button(__('Disconnect OAuth', 'wp-cronflare'), 'delete', 'submit', false); ?>
                    </form>
                <?php else : ?>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;">
                        <input type="hidden" name="action" value="wp_cronflare_oauth_start" />
                        <?php wp_nonce_field('wp_cronflare_oauth_start'); ?>
                        <?php submit_button(__('Sign in with Cloudflare', 'wp-cronflare'), 'primary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;margin-left:8px;">
                    <input type="hidden" name="action" value="wp_cronflare_oauth_test" />
                    <?php wp_nonce_field('wp_cronflare_oauth_test'); ?>
                    <?php submit_button(__('Run OAuth Config Test', 'wp-cronflare'), 'secondary', 'submit', false); ?>
                </form>
            </section>

            <section class="wp-cronflare-card wp-cronflare-snippets">
                <h2><?php echo esc_html__('Create OAuth App Checklist', 'wp-cronflare'); ?></h2>
                <ul class="wp-cronflare-checklist">
                    <li>
                        <span><?php echo esc_html__('OAuth Client ID saved', 'wp-cronflare'); ?></span>
                        <strong class="state <?php echo $oauth_setup_status['client_id'] ? 'ok' : 'warn'; ?>">
                            <?php echo $oauth_setup_status['client_id'] ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                        </strong>
                    </li>
                    <li>
                        <span><?php echo esc_html__('OAuth Client Secret saved', 'wp-cronflare'); ?></span>
                        <strong class="state <?php echo $oauth_setup_status['client_secret'] ? 'ok' : 'warn'; ?>">
                            <?php echo $oauth_setup_status['client_secret'] ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                        </strong>
                    </li>
                    <li>
                        <span><?php echo esc_html__('Authorization URL is valid', 'wp-cronflare'); ?></span>
                        <strong class="state <?php echo $oauth_setup_status['auth_url'] ? 'ok' : 'warn'; ?>">
                            <?php echo $oauth_setup_status['auth_url'] ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                        </strong>
                    </li>
                    <li>
                        <span><?php echo esc_html__('Token URL is valid', 'wp-cronflare'); ?></span>
                        <strong class="state <?php echo $oauth_setup_status['token_url'] ? 'ok' : 'warn'; ?>">
                            <?php echo $oauth_setup_status['token_url'] ? esc_html__('Yes', 'wp-cronflare') : esc_html__('No', 'wp-cronflare'); ?>
                        </strong>
                    </li>
                    <li>
                        <span><?php echo esc_html__('OAuth scope configured', 'wp-cronflare'); ?></span>
                        <strong class="state <?php echo $oauth_setup_status['scope'] ? 'ok' : 'warn'; ?>">
                            <?php echo $oauth_setup_status['scope'] ? esc_html__('Yes', 'wp-cronflare') : esc_html__('Optional', 'wp-cronflare'); ?>
                        </strong>
                    </li>
                </ul>

                <h3><?php echo esc_html__('1) Set Redirect URI in Cloudflare app', 'wp-cronflare'); ?></h3>
                <div class="snippet-box">
                    <pre><code><?php echo esc_html($this->oauth_redirect_uri()); ?></code></pre>
                    <button type="button" class="button" data-copy-target="oauth-redirect-uri"><?php echo esc_html__('Copy', 'wp-cronflare'); ?></button>
                    <textarea data-copy-source="oauth-redirect-uri" class="screen-reader-text"><?php echo esc_textarea($this->oauth_redirect_uri()); ?></textarea>
                </div>

                <h3><?php echo esc_html__('2) Use Authorization URL', 'wp-cronflare'); ?></h3>
                <div class="snippet-box">
                    <pre><code><?php echo esc_html($oauth_auth_url !== '' ? $oauth_auth_url : 'https://dash.cloudflare.com/oauth2/auth'); ?></code></pre>
                    <button type="button" class="button" data-copy-target="oauth-auth-url"><?php echo esc_html__('Copy', 'wp-cronflare'); ?></button>
                    <textarea data-copy-source="oauth-auth-url" class="screen-reader-text"><?php echo esc_textarea($oauth_auth_url !== '' ? $oauth_auth_url : 'https://dash.cloudflare.com/oauth2/auth'); ?></textarea>
                </div>

                <h3><?php echo esc_html__('3) Use Token URL', 'wp-cronflare'); ?></h3>
                <div class="snippet-box">
                    <pre><code><?php echo esc_html($oauth_token_url !== '' ? $oauth_token_url : 'https://dash.cloudflare.com/oauth2/token'); ?></code></pre>
                    <button type="button" class="button" data-copy-target="oauth-token-url"><?php echo esc_html__('Copy', 'wp-cronflare'); ?></button>
                    <textarea data-copy-source="oauth-token-url" class="screen-reader-text"><?php echo esc_textarea($oauth_token_url !== '' ? $oauth_token_url : 'https://dash.cloudflare.com/oauth2/token'); ?></textarea>
                </div>

                <h3><?php echo esc_html__('4) Suggested OAuth Scope', 'wp-cronflare'); ?></h3>
                <div class="snippet-box">
                    <pre><code><?php echo esc_html($oauth_scope_for_copy); ?></code></pre>
                    <button type="button" class="button" data-copy-target="oauth-scope"><?php echo esc_html__('Copy', 'wp-cronflare'); ?></button>
                    <textarea data-copy-source="oauth-scope" class="screen-reader-text"><?php echo esc_textarea($oauth_scope_for_copy); ?></textarea>
                </div>
            </section>

            <section class="wp-cronflare-card">
                <h2><?php echo esc_html__('Cloudflare Auto Setup', 'wp-cronflare'); ?></h2>
                <p><?php echo esc_html__('One click to verify token, detect zone, deploy/update Worker, save secrets, set schedule, and upsert route.', 'wp-cronflare'); ?></p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="wp_cronflare_auto_setup" />
                    <?php wp_nonce_field('wp_cronflare_auto_setup'); ?>
                    <?php submit_button(__('Run Cloudflare Auto Setup', 'wp-cronflare'), 'secondary', 'submit', false); ?>
                </form>
            </section>

            <section class="wp-cronflare-card wp-cronflare-snippets">
                <h2><?php echo esc_html__('Copy-Ready Setup Snippets', 'wp-cronflare'); ?></h2>

                <h3><?php echo esc_html__('Cloudflare Worker Variables', 'wp-cronflare'); ?></h3>
                <div class="snippet-box">
                    <pre><code><?php
                    echo esc_html("WP_CRON_URL=" . home_url() . "\nWP_CRON_KEY=" . ($wp_cron_key !== '' ? '***set-in-dashboard***' : 'generate-a-long-random-secret'));
                    ?></code></pre>
                    <button type="button" class="button" data-copy-target="worker-vars"><?php echo esc_html__('Copy', 'wp-cronflare'); ?></button>
                    <textarea data-copy-source="worker-vars" class="screen-reader-text"><?php
                        echo esc_textarea("WP_CRON_URL=" . home_url() . "\nWP_CRON_KEY=" . ($wp_cron_key !== '' ? $wp_cron_key : 'generate-a-long-random-secret'));
                    ?></textarea>
                </div>

                <h3><?php echo esc_html__('wp-config.php', 'wp-cronflare'); ?></h3>
                <div class="snippet-box">
                    <pre><code><?php echo esc_html("define( 'DISABLE_WP_CRON', true );"); ?></code></pre>
                    <button type="button" class="button" data-copy-target="wp-config"><?php echo esc_html__('Copy', 'wp-cronflare'); ?></button>
                    <textarea data-copy-source="wp-config" class="screen-reader-text"><?php echo esc_textarea("define( 'DISABLE_WP_CRON', true );"); ?></textarea>
                </div>

                <h3><?php echo esc_html__('Test URL', 'wp-cronflare'); ?></h3>
                <div class="snippet-box">
                    <pre><code><?php echo esc_html($cron_url); ?></code></pre>
                    <button type="button" class="button" data-copy-target="cron-url"><?php echo esc_html__('Copy', 'wp-cronflare'); ?></button>
                    <textarea data-copy-source="cron-url" class="screen-reader-text"><?php echo esc_textarea($cron_url); ?></textarea>
                </div>
            </section>

            <section class="wp-cronflare-card">
                <h2><?php echo esc_html__('Diagnostics', 'wp-cronflare'); ?></h2>
                <p><?php echo esc_html__('Run an authenticated self-test to verify wp-cron.php responds correctly.', 'wp-cronflare'); ?></p>

                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="wp_cronflare_run_test" />
                    <?php wp_nonce_field('wp_cronflare_run_test'); ?>
                    <?php submit_button(__('Run Cron Test', 'wp-cronflare'), 'secondary', 'submit', false); ?>
                </form>
            </section>
        </div>
        <?php
    }

    private function get_default_settings(): array
    {
        return [
            'secret_key' => '',
            'require_auth' => 1,
            'allow_local_loopback' => 1,
            'cloudflare_api_token' => '',
            'worker_name' => '',
            'cron_expression' => '* * * * *',
            'cf_oauth_client_id' => '',
            'cf_oauth_client_secret' => '',
            'cf_oauth_auth_url' => 'https://dash.cloudflare.com/oauth2/auth',
            'cf_oauth_token_url' => 'https://dash.cloudflare.com/oauth2/token',
            'cf_oauth_scope' => '',
            'cf_oauth_connected_email' => '',
            'cf_oauth_connected_at' => 0,
            'cf_oauth_refresh_token' => '',
            'cf_oauth_expires_at' => 0,
        ];
    }

    private function get_settings(): array
    {
        $settings = get_option(self::OPTION_KEY, []);

        if (!is_array($settings)) {
            $settings = [];
        }

        return wp_parse_args($settings, $this->get_default_settings());
    }

    private function get_setup_status(array $settings): array
    {
        return [
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'secret_set' => !empty($settings['secret_key']),
            'auth_enabled' => !empty($settings['require_auth']),
        ];
    }

    private function is_wp_cron_request(): bool
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($script === 'wp-cron.php') {
            return true;
        }

        return defined('DOING_CRON') && DOING_CRON;
    }

    private function is_local_request(): bool
    {
        $remote_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return in_array($remote_ip, ['127.0.0.1', '::1'], true);
    }

    private function set_setup_result(bool $ok, string $message): void
    {
        set_transient(self::SETUP_TRANSIENT_KEY, [
            'ok' => $ok,
            'message' => $message,
        ], 120);
    }

    private function redirect_back(): void
    {
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    private function default_worker_name(string $host): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $host));
        return 'wp-cronflare-' . trim($base, '-');
    }

    private function oauth_redirect_uri(): string
    {
        return add_query_arg([
            'page' => self::PAGE_SLUG,
            'wp_cronflare_oauth' => 'callback',
        ], admin_url('options-general.php'));
    }

    private function oauth_state_key(): string
    {
        return 'wp_cronflare_oauth_state_' . get_current_user_id();
    }

    private function maybe_refresh_oauth_token(array $settings): array
    {
        $refresh_token = trim((string) ($settings['cf_oauth_refresh_token'] ?? ''));
        if ($refresh_token === '') {
            return [
                'ok' => false,
                'message' => 'Missing OAuth refresh token.',
            ];
        }

        $refresh = $this->oauth_token_request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
        ], $settings);

        if (!$refresh['ok']) {
            return $refresh;
        }

        $payload = $refresh['result'];
        $access_token = trim((string) ($payload['access_token'] ?? ''));
        if ($access_token === '') {
            return [
                'ok' => false,
                'message' => 'Refresh response missing access_token.',
            ];
        }

        $settings['cloudflare_api_token'] = $access_token;
        if (!empty($payload['refresh_token'])) {
            $settings['cf_oauth_refresh_token'] = (string) $payload['refresh_token'];
        }
        $settings['cf_oauth_expires_at'] = !empty($payload['expires_in']) ? (time() + (int) $payload['expires_in']) : 0;
        update_option(self::OPTION_KEY, $settings);

        return [
            'ok' => true,
            'message' => '',
        ];
    }

    private function oauth_token_request(array $params, array $settings): array
    {
        $token_url = trim((string) ($settings['cf_oauth_token_url'] ?? ''));
        $client_id = trim((string) ($settings['cf_oauth_client_id'] ?? ''));
        $client_secret = trim((string) ($settings['cf_oauth_client_secret'] ?? ''));

        if ($token_url === '' || $client_id === '' || $client_secret === '') {
            return [
                'ok' => false,
                'message' => 'OAuth token URL, client ID, or client secret is missing.',
                'result' => null,
            ];
        }

        $basic = base64_encode($client_id . ':' . $client_secret);
        $response = wp_remote_post($token_url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . $basic,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query($params, '', '&'),
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
                'result' => null,
            ];
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($json)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from OAuth token endpoint.',
                'result' => null,
            ];
        }

        if (!empty($json['error'])) {
            return [
                'ok' => false,
                'message' => trim((string) $json['error'] . ' ' . (string) ($json['error_description'] ?? '')),
                'result' => null,
            ];
        }

        return [
            'ok' => true,
            'message' => '',
            'result' => $json,
        ];
    }

    private function is_oauth_probe_status_ok(int $status): bool
    {
        if ($status >= 200 && $status < 400) {
            return true;
        }

        return in_array($status, [400, 401, 403, 405], true);
    }

    private function find_best_zone_for_host(string $token, string $host): array
    {
        $exact = $this->cf_request('GET', '/zones?status=active&name=' . rawurlencode($host), $token);

        if ($exact['ok'] && !empty($exact['result'][0])) {
            return [
                'ok' => true,
                'zone' => $exact['result'][0],
            ];
        }

        $zones = $this->cf_request('GET', '/zones?status=active&per_page=50', $token);
        if (!$zones['ok']) {
            return [
                'ok' => false,
                'message' => 'Failed to fetch zones: ' . $zones['message'],
            ];
        }

        $best = null;
        $best_len = 0;

        foreach ((array) $zones['result'] as $zone) {
            $zone_name = (string) ($zone['name'] ?? '');
            if ($zone_name === '') {
                continue;
            }

            $is_match = ($host === $zone_name) || (substr($host, -strlen('.' . $zone_name)) === '.' . $zone_name);
            if (!$is_match) {
                continue;
            }

            $len = strlen($zone_name);
            if ($len > $best_len) {
                $best = $zone;
                $best_len = $len;
            }
        }

        if ($best === null) {
            return [
                'ok' => false,
                'message' => 'No matching Cloudflare zone found for host: ' . $host,
            ];
        }

        return [
            'ok' => true,
            'zone' => $best,
        ];
    }

    private function upsert_worker_route(string $token, string $zone_id, string $pattern, string $worker_name): array
    {
        $routes = $this->cf_request('GET', '/zones/' . rawurlencode($zone_id) . '/workers/routes', $token);
        if (!$routes['ok']) {
            return [
                'ok' => false,
                'message' => $routes['message'],
            ];
        }

        $existing_route = null;
        foreach ((array) $routes['result'] as $route) {
            if ((string) ($route['pattern'] ?? '') === $pattern) {
                $existing_route = $route;
                break;
            }
        }

        if ($existing_route !== null && !empty($existing_route['id'])) {
            $update = $this->cf_request('PUT', '/zones/' . rawurlencode($zone_id) . '/workers/routes/' . rawurlencode((string) $existing_route['id']), $token, [
                'pattern' => $pattern,
                'script' => $worker_name,
            ]);

            return [
                'ok' => $update['ok'],
                'message' => $update['message'],
            ];
        }

        $create = $this->cf_request('POST', '/zones/' . rawurlencode($zone_id) . '/workers/routes', $token, [
            'pattern' => $pattern,
            'script' => $worker_name,
        ]);

        return [
            'ok' => $create['ok'],
            'message' => $create['message'],
        ];
    }

    private function cf_upload_worker_script(string $token, string $account_id, string $worker_name, string $script): array
    {
        $boundary = '----wpcronflare' . wp_generate_password(16, false, false);
        $metadata = wp_json_encode([
            'main_module' => 'main.js',
            'compatibility_date' => gmdate('Y-m-d'),
            'bindings' => [],
        ]);

        $parts = [];
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Disposition: form-data; name="metadata"';
        $parts[] = 'Content-Type: application/json';
        $parts[] = '';
        $parts[] = $metadata;
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Disposition: form-data; name="main.js"; filename="main.js"';
        $parts[] = 'Content-Type: application/javascript+module';
        $parts[] = '';
        $parts[] = $script;
        $parts[] = '--' . $boundary . '--';
        $parts[] = '';

        $body = implode("\r\n", $parts);
        $url = self::CF_API_BASE . '/accounts/' . rawurlencode($account_id) . '/workers/scripts/' . rawurlencode($worker_name);

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($json)) {
            return [
                'ok' => false,
                'message' => 'Invalid response while uploading Worker script.',
            ];
        }

        if (empty($json['success'])) {
            return [
                'ok' => false,
                'message' => $this->cf_error_message($json),
            ];
        }

        return [
            'ok' => true,
            'message' => '',
        ];
    }

    private function cf_request(string $method, string $path, string $token, $body = null): array
    {
        $url = self::CF_API_BASE . $path;

        $args = [
            'method' => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
                'result' => null,
            ];
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($json)) {
            return [
                'ok' => false,
                'message' => 'Invalid JSON response from Cloudflare API.',
                'result' => null,
            ];
        }

        if (!empty($json['success'])) {
            return [
                'ok' => true,
                'message' => '',
                'result' => $json['result'] ?? null,
            ];
        }

        return [
            'ok' => false,
            'message' => $this->cf_error_message($json),
            'result' => $json['result'] ?? null,
        ];
    }

    private function cf_error_message(array $response): string
    {
        $errors = (array) ($response['errors'] ?? []);
        if (empty($errors)) {
            return 'Unknown Cloudflare API error.';
        }

        $first = $errors[0];
        $code = (string) ($first['code'] ?? '');
        $msg = (string) ($first['message'] ?? 'Request failed.');

        return trim($code . ' ' . $msg);
    }

    private function build_worker_script(): string
    {
        return <<<'JS'
const FETCH_TIMEOUT_MS = 10000;

export default {
  async fetch(req, env) {
    const url = new URL(req.url);
    if (url.pathname === '/wp-cron.php') {
      return triggerCron(env.WP_CRON_URL, env.WP_CRON_KEY);
    }

    return fetch(req);
  },

  async scheduled(event, env, ctx) {
    ctx.waitUntil(triggerCron(env.WP_CRON_URL, env.WP_CRON_KEY));
  },
};

async function triggerCron(siteUrl, secretKey) {
  if (!siteUrl || !secretKey) {
    return new Response('Missing Worker secret bindings: WP_CRON_URL / WP_CRON_KEY.', { status: 500 });
  }

  const baseUrl = siteUrl.replace(/\/+$/, '');
  const cronUrl = `${baseUrl}/wp-cron.php?doing_wp_cron`;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

  try {
    const response = await fetch(cronUrl, {
      method: 'GET',
      headers: {
        'User-Agent': 'Cloudflare-Worker-WP-Cron',
        'X-Worker-Auth': secretKey,
        'Cache-Control': 'no-cache',
      },
      signal: controller.signal,
      cf: { cacheTtl: 0 },
    });

    if (!response.ok) {
      const text = await response.text();
      return new Response(`Cron failed: HTTP ${response.status} ${response.statusText} ${text.slice(0, 500)}`, { status: 500 });
    }

    return new Response('Cloudflare Worker for WordPress works. Yay!', { status: 200 });
  } catch (err) {
    if (err && err.name === 'AbortError') {
      return new Response('Timeout waiting for wp-cron.php', { status: 504 });
    }

    return new Response('Worker runtime error while triggering cron.', { status: 500 });
  } finally {
    clearTimeout(timeoutId);
  }
}
JS;
    }
}

WP_Cronflare_Plugin::instance();
