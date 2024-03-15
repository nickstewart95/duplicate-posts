<?php

/**
 * Plugin Name:       Auto Copy Posts for WordPress
 * Description:       Sync posts from one WordPress site to another
 * Version:           1.1.0
 * Requires PHP:      8.0
 * Author:            Nick Stewart
 * Author URI:        https://nickstewart.me
 *
 * @package AutoCopy
 */

if (file_exists(__DIR__ . '/src/vendor/autoload.php')) {
	require_once __DIR__ . '/src/vendor/autoload.php';
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use Nickstewart\AutoCopy\AutoCopy;

$auto_copy = AutoCopy::get_instance();
$auto_copy->setup();

$GLOBALS['blade'] = $auto_copy->initBladeViews();
