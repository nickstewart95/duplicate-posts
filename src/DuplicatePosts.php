<?php

namespace Nickstewart\DuplicatePosts;

define('DUPLICATE_POSTS_VERSION', '1.0.0');
define('DUPLICATE_POSTS_FILE', __FILE__);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

class DuplicatePosts {
	private static $instance = null;

	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function setup() {
		add_action('init', [$this, 'init']);
	}

	public function init() {
		//
	}
}
