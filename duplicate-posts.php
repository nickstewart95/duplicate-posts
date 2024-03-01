<?php

/**
 * Plugin Name:       Duplicate Posts
 * Description:       Copy posts from one WordPress site to another
 * Version:           1.0.0
 * Requires PHP:      8.0
 * Author:            Nick Stewart
 * Author URI:        https://nickstewart.me
 *
 * @package DuplicatePosts
 */

if (file_exists(__DIR__ . '/src/vendor/autoload.php')) {
	require_once __DIR__ . '/src/vendor/autoload.php';
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use Nickstewart\DuplicatePosts\DuplicatePosts;

$duplicate_posts = DuplicatePosts::get_instance();
$duplicate_posts->setup();
