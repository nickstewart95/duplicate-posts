<?php

if (file_exists(__DIR__ . '/src/vendor/autoload.php')) {
	require_once __DIR__ . '/src/vendor/autoload.php';
}

use Nickstewart\SyncPosts\SyncPosts;

if (!defined('WP_UNINSTALL_PLUGIN')) {
	die();
}

$sync_posts = SyncPosts::get_instance();
$sync_posts->delete();
