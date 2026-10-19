<?php

namespace Glenmark\ConnectorClient;

if (!defined('ABSPATH')) {
    exit;
}

final class Collector
{
    public function collect(): array
    {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
        $health = \WP_Site_Health::get_instance();
        $definitions = \WP_Site_Health::get_tests();
        $tests = [];
        $started = microtime(true);
        foreach (['direct', 'async'] as $kind) {
            foreach ($definitions[$kind] ?? [] as $id => $definition) {
                if (count($tests) >= 200) {
                    break;
                }
                $test = ['id' => sanitize_key($id), 'label' => sanitize_text_field($definition['label'] ?? $id), 'status' => 'unsupported', 'description' => '', 'actions' => ''];
                $method = is_string($definition['test'] ?? null) ? 'get_test_' . $definition['test'] : '';
                if ($kind === 'async') {
                    $test['description'] = __('Asynchronní test není tímto konektorem spouštěn. Ověřte jej v Nástroje → Zdraví webu.', 'glenmark-connector-client');
                } elseif (microtime(true) - $started > 12) {
                    $test['status'] = 'pending';
                    $test['description'] = __('Dosažen časový rozpočet diagnostiky.', 'glenmark-connector-client');
                } elseif ($method && is_callable([$health, $method]) && (new \ReflectionMethod($health, $method))->getDeclaringClass()->getName() === 'WP_Site_Health') {
                    try {
                        $result = $health->$method();
                        if (is_array($result) && in_array($result['status'] ?? '', ['good', 'recommended', 'critical'], true)) {
                            $test['status'] = $result['status'];
                            $test['label'] = sanitize_text_field($result['label'] ?? $test['label']);
                            $test['description'] = sanitize_text_field(wp_strip_all_tags($result['description'] ?? ''));
                            $test['actions'] = sanitize_text_field(wp_strip_all_tags($result['actions'] ?? ''));
                        } else {
                            $test['status'] = 'error';
                        }
                    } catch (\Throwable $error) {
                        $test['status'] = 'error';
                        $test['description'] = __('Test nebylo možné dokončit.', 'glenmark-connector-client');
                    }
                }
                $tests[] = $test;
            }
        }
        $raw = \WP_Debug_Data::debug_data();
        $information = $this->information($raw);
        return [
            'schema_version' => '1.0',
            'connector' => ['name' => 'Glenmark Connector Client', 'version' => GLENMARK_CONNECTOR_CLIENT_VERSION],
            'site' => ['id' => Plugin::site_id(), 'url' => home_url('/'), 'platform' => 'wordpress'],
            'environment' => [
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'locale' => get_locale(),
                'web_server' => sanitize_text_field($_SERVER['SERVER_SOFTWARE'] ?? ''),
                'database_version' => sanitize_text_field((string) ($raw['wp-database']['fields']['server_version']['value'] ?? '')),
                'updates' => $this->updates(),
            ],
            'health' => ['tests' => $tests],
            'information' => $information,
            'components' => $this->components(),
            'collected_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function information(array $raw): array
    {
        $allowed = [
            'wp-core' => ['version', 'site_language', 'user_language', 'timezone', 'permalink', 'https_status', 'multisite', 'user_registration', 'blog_public', 'default_comment_status', 'environment_type'],
            'wp-server' => ['server_architecture', 'httpd_software', 'php_version', 'php_sapi', 'max_input_variables', 'time_limit', 'memory_limit', 'max_input_time', 'upload_max_filesize', 'php_post_max_size', 'curl_version', 'suhosin', 'imagick_availability', 'pretty_permalinks'],
            'wp-database' => ['extension', 'server_version', 'client_version', 'database_charset', 'database_collate', 'max_allowed_packet', 'max_connections'],
            'wp-active-theme' => ['name', 'version', 'author', 'parent_theme', 'theme_features', 'auto_update'],
            'wp-parent-theme' => ['name', 'version', 'author', 'auto_update'],
            'wp-plugins-active' => null,
            'wp-plugins-inactive' => null,
            'wp-mu-plugins' => null,
        ];
        $output = [];
        foreach ($allowed as $section_id => $field_ids) {
            if (!isset($raw[$section_id]['fields'])) {
                continue;
            }
            $section = ['label' => sanitize_text_field($raw[$section_id]['label'] ?? $section_id), 'fields' => []];
            foreach (array_slice($raw[$section_id]['fields'], 0, 250, true) as $field_id => $field) {
                if (!empty($field['private']) || ($field_ids !== null && !in_array($field_id, $field_ids, true))) {
                    continue;
                }
                $value = $field['value'] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', array_filter($value, 'is_scalar'));
                }
                $value = sanitize_text_field(is_scalar($value) ? (string) $value : '');
                $section['fields'][sanitize_key($field_id)] = ['label' => sanitize_text_field($field['label'] ?? $field_id), 'value' => function_exists('mb_substr') ? mb_substr($value, 0, 2000) : substr($value, 0, 2000)];
            }
            $output[$section_id] = $section;
        }
        return $output;
    }

    private function updates(): string
    {
        $plugins = get_site_transient('update_plugins');
        $themes = get_site_transient('update_themes');
        $core = get_site_transient('update_core');
        if (!is_object($plugins) || !is_object($themes) || !is_object($core)) {
            return 'unknown';
        }
        $core_count = 0;
        foreach ($core->updates ?? [] as $update) {
            if (($update->response ?? '') === 'upgrade') {
                ++$core_count;
            }
        }
        return wp_json_encode(['core' => $core_count, 'plugins' => count($plugins->response ?? []), 'themes' => count($themes->response ?? [])]);
    }

    private function components(): array
    {
        $theme = wp_get_theme();
        $parent = $theme->parent();
        $output = ['glenmark-product' => '', 'glenmark-product-child' => '', 'glenmark-content-connector' => '', 'glenmark-connector-client' => GLENMARK_CONNECTOR_CLIENT_VERSION];
        if ($theme->get_template() === 'glenmark-product') {
            $output['glenmark-product'] = (string) ($parent ?: $theme)->get('Version');
            if ($parent) {
                $output['glenmark-product-child'] = (string) $theme->get('Version');
            }
        }
        foreach (get_plugins() as $file => $plugin) {
            if ($file === 'glenmark-content-connector/glenmark-content-connector.php') {
                $output['glenmark-content-connector'] = sanitize_text_field($plugin['Version']);
            }
        }
        return $output;
    }
}