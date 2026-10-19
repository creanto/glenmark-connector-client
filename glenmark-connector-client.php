<?php
/**
 * Plugin Name: Glenmark Connector Client
 * Description: Authenticated read-only Site Health connector for Glenmark IT.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: glenmark-connector-client
 * Domain Path: /languages
 */

namespace Glenmark\ConnectorClient;

if (!defined('ABSPATH')) {
    exit;
}

define('GLENMARK_CONNECTOR_CLIENT_VERSION', '1.0.0');
require_once __DIR__ . '/includes/class-collector.php';
require_once __DIR__ . '/includes/class-plugin.php';
add_action('plugins_loaded', [Plugin::class, 'boot']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);