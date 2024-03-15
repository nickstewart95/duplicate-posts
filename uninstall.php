<?php

if (file_exists(__DIR__ . '/src/vendor/autoload.php')) {
	require_once __DIR__ . '/src/vendor/autoload.php';
}

use Nickstewart\AutoCopy\AutoCopy;

if (!defined('WP_UNINSTALL_PLUGIN')) {
	die();
}

$auto_copy = AutoCopy::get_instance();
$auto_copy->delete();
