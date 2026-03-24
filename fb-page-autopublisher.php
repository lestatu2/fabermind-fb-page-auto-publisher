<?php
/**
 * Plugin Name: Facebook Page Auto Publisher
 * Plugin URI: https://fabermind.it/
 * Description: Automatically publish WordPress posts and selected custom post types to a Facebook Page via Graph API.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Fabermind
 * Text Domain: fb-page-autopublisher
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('FBAP_PLUGIN_FILE', __FILE__);
define('FBAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FBAP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FBAP_VERSION', '1.0.0');

require_once FBAP_PLUGIN_DIR . 'includes/helpers.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-template-parser.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-logger.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-facebook-client.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-image-processor.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-post-metabox.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-publisher.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-token-manager.php';
require_once FBAP_PLUGIN_DIR . 'includes/class-plugin.php';

\FBPageAutopublisher\Plugin::instance();
