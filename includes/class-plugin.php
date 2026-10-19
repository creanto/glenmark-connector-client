<?php

namespace Glenmark\ConnectorClient;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    public static function boot(): void
    {
        load_plugin_textdomain('glenmark-connector-client', false, 'glenmark-connector-client/languages');
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_glenmark_client_rotate', [self::class, 'rotate']);
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function deactivate(): void
    {
        delete_option('glenmark_client_key_hash');
        delete_transient('glenmark_client_snapshot');
        delete_option('glenmark_client_lock');
    }

    public static function site_id(): string
    {
        $id = get_option('glenmark_client_site_id', '');
        if (!$id) {
            $id = wp_generate_uuid4();
            add_option('glenmark_client_site_id', $id, '', false);
            $id = get_option('glenmark_client_site_id', $id);
        }
        return $id;
    }

    public static function menu(): void
    {
        add_options_page('Glenmark Connector Client', 'Glenmark Connector', 'manage_options', 'glenmark-connector-client', [self::class, 'page']);
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnění.', 'glenmark-connector-client'));
        }
        echo '<div class="wrap"><h1>Glenmark Connector Client</h1>';
        echo '<table class="widefat striped"><tbody>';
        foreach ([__('Identifikátor webu', 'glenmark-connector-client') => self::site_id(), __('URL webu', 'glenmark-connector-client') => home_url('/'), __('Endpoint', 'glenmark-connector-client') => rest_url('glenmark-connector/v1/snapshot'), __('Přístupový klíč', 'glenmark-connector-client') => get_option('glenmark_client_key_hash') ? __('Nastaven', 'glenmark-connector-client') : __('Zneplatněn / nenastaven', 'glenmark-connector-client')] as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td><code>' . esc_html($value) . '</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('glenmark_client_rotate');
        echo '<input type="hidden" name="action" value="glenmark_client_rotate">';
        submit_button(__('Vygenerovat nový klíč', 'glenmark-connector-client'), 'primary', 'rotate', false);
        echo ' ';
        submit_button(__('Zneplatnit klíč', 'glenmark-connector-client'), 'secondary', 'revoke', false);
        echo '</form></div>';
    }

    public static function rotate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnění.', 'glenmark-connector-client'), '', ['response' => 403]);
        }
        check_admin_referer('glenmark_client_rotate');
        if (isset($_POST['revoke'])) {
            delete_option('glenmark_client_key_hash');
            wp_safe_redirect(admin_url('options-general.php?page=glenmark-connector-client'));
            exit;
        }
        $key = bin2hex(random_bytes(32));
        update_option('glenmark_client_key_hash', hash('sha256', $key), false);
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Glenmark Connector Client</title></head><body><h1>' . esc_html__('Nový přístupový klíč', 'glenmark-connector-client') . '</h1><p>' . esc_html__('Klíč se zobrazí pouze nyní. Zadejte jej v centrálním Hubu. Předchozí klíč již neplatí.', 'glenmark-connector-client') . '</p><p><code>' . esc_html($key) . '</code></p><p><a href="' . esc_url(admin_url('options-general.php?page=glenmark-connector-client')) . '">' . esc_html__('Zpět do nastavení', 'glenmark-connector-client') . '</a></p></body></html>';
        exit;
    }

    public static function routes(): void
    {
        register_rest_route('glenmark-connector/v1', '/snapshot', [
            'methods' => 'GET',
            'permission_callback' => [self::class, 'permission'],
            'callback' => [self::class, 'snapshot'],
        ]);
    }

    public static function permission(\WP_REST_Request $request)
    {
        $stored = get_option('glenmark_client_key_hash', '');
        $header = $request->get_header('authorization');
        if (!is_ssl() || !$stored || !is_string($header) || !preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $matches) || !hash_equals($stored, hash('sha256', $matches[1])) || !hash_equals(self::site_id(), (string) $request->get_header('x-glenmark-site-id'))) {
            return new \WP_Error('glenmark_forbidden', __('Přístup zamítnut.', 'glenmark-connector-client'), ['status' => 403]);
        }
        return true;
    }

    public static function snapshot(\WP_REST_Request $request)
    {
        $cached = get_transient('glenmark_client_snapshot');
        if (is_array($cached)) {
            return self::response($cached);
        }
        $lock = (int) get_option('glenmark_client_lock');
        if ($lock && $lock < time() - 120) {
            delete_option('glenmark_client_lock');
        }
        if (!add_option('glenmark_client_lock', time(), '', false)) {
            return new \WP_Error('glenmark_busy', __('Diagnostika právě probíhá.', 'glenmark-connector-client'), ['status' => 429]);
        }
        try {
            $data = (new Collector())->collect();
            set_transient('glenmark_client_snapshot', $data, 5 * MINUTE_IN_SECONDS);
            return self::response($data);
        } catch (\Throwable $error) {
            return new \WP_Error('glenmark_collection', __('Diagnostiku se nepodařilo dokončit.', 'glenmark-connector-client'), ['status' => 503]);
        } finally {
            delete_option('glenmark_client_lock');
        }
    }

    private static function response(array $data): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Vary', 'Authorization, X-Glenmark-Site-ID');
        return $response;
    }
}