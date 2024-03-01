<?php

namespace Nickstewart\DuplicatePosts;

define('DUPLICATE_POSTS_VERSION', '1.0.0');
define('DUPLICATE_POSTS_FILE', __FILE__);

use Nickstewart\DuplicatePosts\Events;
use Carbon\Carbon;

class DuplicatePosts {
	private static $instance = null;

	const DEFAULT_SYNC_SCHEDULE = '0 4,14 * * *';
	const DEFAULT_SITE_URL = 'https://tjwrestling.com';
	const DEFAULT_POSTS_PER_PAGE = 10;
	const DEFAULT_POSTS_AUTHOR_ID = 1;
	const DEFAULT_POST_TYPE_SINGLE = 'post';
	const DEFAULT_POST_TYPE_PLURAL = 'posts';
	const DEFAULT_LOG_ERRORS = true;

	/**
	 * Class instance
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Call the init the actions, filters, and events
	 */
	public function setup(): void {
		add_action('init', [$this, 'initActions']);
		add_action('init', [$this, 'initFilters']);
		add_action('action_scheduler_init', [Events::class, 'scheduleSync']);
	}

	/**
	 * Setup actions
	 */
	public function initActions(): void {
		add_action('duplicate_posts_sync', [$this, 'sync'], 10, 0);

		add_action(
			'duplicate_posts_fetch_posts',
			[Events::class, 'fetchPosts'],
			10,
			1,
		);
		add_action(
			'duplicate_posts_create_post',
			[Events::class, 'createPost'],
			10,
			1,
		);
		add_action(
			'duplicate_posts_sync_single_post',
			[Events::class, 'syncPost'],
			10,
			1,
		);

		add_action('admin_menu', [$this, 'add_metabox_to_posts']);
		add_action('wp_loaded', [$this, 'check_for_manual_sync']);
	}

	/**
	 * Setup filters
	 */
	public function initFilters(): void {
		add_filter(
			'duplicate_posts_sync_schedule',
			[$this, 'filter_sync_schedule'],
			10,
			1,
		);

		add_filter(
			'duplicate_posts_site_url',
			[$this, 'filter_site_url'],
			10,
			1,
		);

		add_filter(
			'duplicate_posts_post_per_page',
			[$this, 'filter_posts_per_page'],
			10,
			1,
		);

		add_filter(
			'duplicate_posts_author_id',
			[$this, 'filters_author_id'],
			10,
			1,
		);

		add_filter(
			'duplicate_posts_post_type_single',
			[$this, 'filters_post_type_single'],
			10,
			1,
		);

		add_filter(
			'duplicate_posts_post_type_plural',
			[$this, 'filters_post_type_plural'],
			10,
			1,
		);

		add_filter(
			'duplicate_posts_log_errors',
			[$this, 'filters_log_errors'],
			10,
			1,
		);
	}

	/**
	 * The sync action that basically runs the plugin
	 */
	public function sync(): void {
		$events = new Events();
		$events->schedulePosts();
	}

	/**
	 * Call the metabox creation
	 */
	public function add_metabox_to_posts(): void {
		$post_type = apply_filters(
			'duplicate_posts_post_type_single',
			self::DEFAULT_POST_TYPE_SINGLE,
		);

		add_meta_box(
			'duplicate_posts_post_information',
			'Duplicate Post Information',
			[$this, 'create_post_metabox'],
			$post_type,
			'side',
			'high',
		);
	}

	/**
	 * Create the metabox content
	 */
	public function create_post_metabox($post) {
		$post_id = $post->ID;

		$user_timezone = wp_timezone_string();

		$modification_date = get_post_meta(
			$post_id,
			'duplicate_posts_original_modification_date',
			true,
		);
		$modification_date_formatted = Carbon::parse($modification_date)
			->setTimezone($user_timezone)
			->format('M d, Y');

		$last_synced_date = get_post_meta(
			$post_id,
			'duplicate_posts_last_synced_date_gtm',
			true,
		);

		$last_synced_date_formatted = Carbon::parse($last_synced_date)
			->setTimezone($user_timezone)
			->format('M d, Y g:i a');

		$url = get_post_meta($post_id, 'duplicate_posts_original_url', true);

		$is_syncing = !empty($_GET['duplicate_posts_syncing'])
			? $_GET['duplicate_posts_syncing']
			: false;

		// TODO - refactor into a template
		$html = "<b>URL:</b> <a href='{$url}' target='_blank'>{$url}</a><br /><b>Last modified:</b> {$modification_date_formatted}<br /><b>Last synced:</b> {$last_synced_date_formatted}</p>";

		if ($is_syncing) {
			$html .= '<p>Syncing...</p>';
		} else {
			global $wp;
			$current_url =
				'/wp-admin/post.php' . add_query_arg($_GET, $wp->request);
			$sync_url = $current_url . '&duplicate_posts_syncing=true';

			$html .= "<p><a href='{$sync_url}' class='button button-primary'>Sync</a></p>";
		}

		echo $html;
	}

	/**
	 * Run a manual sync
	 */
	public function check_for_manual_sync(): void {
		$is_syncing = !empty($_GET['duplicate_posts_syncing'])
			? $_GET['duplicate_posts_syncing']
			: false;

		if (!$is_syncing) {
			return;
		}

		$post_id = $_GET['post'];

		// Check to make sure its not already scheduled
		$scheduled_job = as_get_scheduled_actions(
			[
				'hook' => 'duplicate_posts_sync_single_post',
				'status' => 'pending',
				'args' => [
					'post_id' => (string) $post_id,
				],
			],
			'ARRAY_A',
		);

		if (!empty($scheduled_job)) {
			return;
		}

		as_schedule_single_action(
			time(),
			'duplicate_posts_sync_single_post',
			[
				'post_id' => $post_id,
			],
			'duplicate_posts_sync_post',
		);
	}

	/**
	 * Filter for how often the sync should run
	 */
	public function filter_sync_schedule($schedule): string {
		return $schedule;
	}

	/**
	 * Filter for the base site url
	 */
	public function filter_site_url($url): string {
		return $url;
	}

	/**
	 * Posts per page when hitting the REST API
	 */
	public function filter_posts_per_page($posts): int {
		return $posts;
	}

	/**
	 * Posts per page when hitting the REST API
	 */
	public function filters_author_id($id): int {
		return $id;
	}

	/**
	 * The post type used for adding the metabox
	 */
	public function filters_post_type_single($post_type): string {
		return $post_type;
	}

	/**
	 * The post type used when hitting the REST API
	 */
	public function filters_post_type_plural($post_type): string {
		return $post_type;
	}

	/**
	 * The post type used when hitting the REST API
	 */
	public function filters_log_errors($answer): bool {
		return $answer;
	}

	/**
	 * Return a post transient name with the option to create it
	 */
	public static function postTransient($post, $create = false): string {
		$post_id = self::mutatePostId($post['id']);

		$name = 'duplicate_posts_temp_' . $post_id;

		if ($create) {
			$post = json_encode($post);

			set_transient($name, $post, DAY_IN_SECONDS);
		}

		return $name;
	}

	/**
	 * Get the site url for the API
	 */
	public static function getSiteUrl(): string {
		$base_url = apply_filters(
			'duplicate_posts_site_url',
			self::DEFAULT_SITE_URL,
		);

		$base_url = rtrim($base_url, '/');

		return $base_url . '/wp-json/wp/v2/';
	}

	/**
	 * Append site url to post id
	 */
	public static function mutatePostId($id): string {
		//site url with id append

		$base_url = apply_filters(
			'duplicate_posts_site_url',
			self::DEFAULT_SITE_URL,
		);

		$url_array = parse_url($base_url);
		$host = $url_array['host'];
		$host_url_parts = explode('.', $host);

		array_pop($host_url_parts);

		$host_top_level = end($host_url_parts);

		return $host_top_level . '_' . $id;
	}

	/**
	 * Transform mutated post id back to just an id
	 */
	public static function stripPostId($id): string {
		$id_parts = explode('_', $id);

		return end($id_parts);
	}

	/**
	 * Write to local log
	 */
	public static function logError($error_message): void {
		$log_errors = apply_filters(
			'duplicate_posts_log_errors',
			self::DEFAULT_LOG_ERRORS,
		);

		if (!$log_errors) {
			return;
		}

		$log_file = plugin_dir_path(__FILE__) . 'error.log';

		$error_message = $error_message . PHP_EOL;

		error_log($error_message, 3, $log_file);
	}
}
