<?php

/**
 * Plugin Name:       Auto Copy Posts for WordPress
 * Description:       Sync posts from one WordPress site to another
 * Version:           1.6.0
 * Requires PHP:      8.0
 * Author:            Nick Stewart
 * Author URI:        https://nickstewart.me
 *
 * @package AutoCopy
 */

if (file_exists(__DIR__ . '/src/vendor/autoload.php')) {
	require_once __DIR__ . '/src/vendor/autoload.php';
}

if (
	file_exists(
		__DIR__ .
			'/src/vendor/woocommerce/action-scheduler/action-scheduler.php',
	)
) {
	require_once __DIR__ .
		'/src/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

use Nickstewart\AutoCopy\AutoCopy;

$auto_copy = AutoCopy::getInstance();
$auto_copy->setup();

$GLOBALS['blade-autocopy'] = $auto_copy->initBladeViews();
