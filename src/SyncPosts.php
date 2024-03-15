<?php

namespace Nickstewart\SyncPosts;

use Nickstewart\SyncPosts\Events;

use Carbon\Carbon;
use Jenssegers\Blade\Blade;

define('SNYC_POSTS_VERSION', '1.1.0');
define('SYNC_POSTS_FILE', __FILE__);

class SyncPosts {
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
		add_action('admin_init', [$this, 'initSettings']);
		add_action('init', [$this, 'initTaxonomy']);
		add_action('action_scheduler_init', [Events::class, 'scheduleSync']);
	}

	/**
	 * Deletes plugin data on uninstall
	 * Doesn't delete sync_posts_registered_taxonomies
	 */
	public function delete(): void {
		// Delete options
		$fields = self::plugin_setting_fields();

		foreach ($fields as $field) {
			delete_option($field['name']);
		}

		// Remove jobs
		as_unschedule_all_actions('sync_posts_sync');
		as_unschedule_all_actions('sync_posts_fetch_posts');
		as_unschedule_all_actions('sync_posts_create_post');
		as_unschedule_all_actions('sync_posts_sync_single_post');
	}

	/**
	 * Setup actions
	 */
	public function initActions(): void {
		add_action('sync_posts_sync', [$this, 'sync'], 10, 0);

		add_action(
			'sync_posts_fetch_posts',
			[Events::class, 'fetchPosts'],
			10,
			1,
		);
		add_action(
			'sync_posts_create_post',
			[Events::class, 'createPost'],
			10,
			1,
		);
		add_action(
			'sync_posts_sync_single_post',
			[Events::class, 'syncPost'],
			10,
			1,
		);

		add_action('admin_menu', [$this, 'add_metabox_to_posts']);
		add_action('admin_menu', [$this, 'add_settings_page']);
		add_action('wp_loaded', [$this, 'check_for_manual_sync']);
	}

	/**
	 * Setup filters
	 */
	public function initFilters(): void {
		add_filter(
			'sync_posts_sync_schedule',
			[$this, 'filter_sync_schedule'],
			10,
			1,
		);

		add_filter('sync_posts_site_url', [$this, 'filter_site_url'], 10, 1);

		add_filter(
			'sync_posts_post_per_page',
			[$this, 'filter_posts_per_page'],
			10,
			1,
		);

		add_filter('sync_posts_author_id', [$this, 'filters_author_id'], 10, 1);

		add_filter(
			'sync_posts_post_type_single',
			[$this, 'filters_post_type_single'],
			10,
			1,
		);

		add_filter(
			'sync_posts_post_type_plural',
			[$this, 'filters_post_type_plural'],
			10,
			1,
		);

		add_filter(
			'sync_posts_log_errors',
			[$this, 'filters_log_errors'],
			10,
			1,
		);
	}

	/**
	 * Plugin settings
	 */
	public static function plugin_setting_fields(): array {
		return [
			[
				'name' => 'sync_posts_sync_schedule',
				'title' => 'Sync Schedule',
				'description' => 'The CRON schedule the active job runs on',
				'value' => get_option(
					'sync_posts_sync_schedule',
					self::DEFAULT_SYNC_SCHEDULE,
				),
			],
			[
				'name' => 'sync_posts_site_url',
				'title' => 'Site URL',
				'description' => 'The WordPress Site URL to copy posts from',
				'value' => get_option(
					'sync_posts_site_url',
					self::DEFAULT_SITE_URL,
				),
			],
			[
				'name' => 'sync_posts_post_per_page',
				'title' => 'Posts Per Page',
				'description' => 'How many posts are grabbed at once',
				'value' => get_option(
					'sync_posts_post_per_page',
					self::DEFAULT_POSTS_PER_PAGE,
				),
			],
			[
				'name' => 'sync_posts_author_id',
				'title' => 'Author ID',
				'description' => 'The author to associate the synced posts to',
				'value' => get_option(
					'sync_posts_author_id',
					self::DEFAULT_POSTS_AUTHOR_ID,
				),
			],
			[
				'name' => 'sync_posts_post_type_single',
				'title' => 'Post Type, single',
				'description' => 'The post type being synced, singular name',
				'value' => get_option(
					'sync_posts_post_type_single',
					self::DEFAULT_POST_TYPE_SINGLE,
				),
			],
			[
				'name' => 'sync_posts_post_type_plural',
				'title' => 'Post Type, plural',
				'description' => 'The post type being synced, plural name',
				'value' => get_option(
					'sync_posts_post_type_plural',
					self::DEFAULT_POST_TYPE_PLURAL,
				),
			],
			[
				'name' => 'sync_posts_log_errors',
				'title' => 'Log plugin errors',
				'description' => 'Log plugin errors to a local file',
				'value' => get_option(
					'sync_posts_log_errors',
					self::DEFAULT_LOG_ERRORS,
				),
			],
		];
	}

	/**
	 * Setup the plugin settings
	 */
	public function initSettings(): void {
		// Register the settings section
		add_settings_section(
			'sync_posts_wordpress_settings',
			'Sync Posts for WordPress Settings',
			[$this, 'create_settings_section'],
			'sync-posts-wordpress',
		);

		$fields = self::plugin_setting_fields();

		foreach ($fields as $field) {
			register_setting('sync_posts_wordpress', $field['name'], [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			]);

			add_settings_field(
				$field['name'],
				$field['title'],
				[$this, 'render_settings_field'],
				'sync-posts-wordpress',
				'sync_posts_wordpress_settings',
				$field,
			);
		}
	}

	/**
	 * Setup custom taxonomy saved in option
	 */
	public function initTaxonomy(): void {
		$registered_taxonomies = get_option(
			'sync_posts_registered_taxonomies',
			[],
		);

		if (empty($registered_taxonomies)) {
			return;
		}

		foreach ($registered_taxonomies as $taxonomy) {
			$pretty_name = str_replace('-', ' ', $taxonomy['name']);
			$pretty_name = ucwords($pretty_name);

			$args = [
				'hierarchical' => true,
				'show_ui' => true,
				'show_admin_column' => true,
				'has_archive' => true,
				'labels' => [
					'name' => $pretty_name,
					'singular_name' => $pretty_name,
				],
			];

			register_taxonomy(
				$taxonomy['name'],
				[$taxonomy['post_type_single']],
				$args,
			);
		}
	}

	/**
	 * Setup Blade for templating
	 */
	public static function initBladeViews() {
		$views = __DIR__ . '/resources/pages';
		$cache = __DIR__ . '/cache';

		return new Blade($views, $cache);
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
			'sync_posts_post_type_single',
			self::pluginSetting('sync_posts_post_type_single'),
		);

		add_meta_box(
			'sync_posts_post_information',
			'Sync Post Information',
			[$this, 'create_post_metabox'],
			$post_type,
			'side',
			'high',
		);
	}

	/**
	 * Add the plugin settings page
	 */
	public function add_settings_page(): void {
		// TODO - add filter for who can view the plugin settings
		add_options_page(
			'Sync Posts for Wordpress',
			'Sync Posts Settings',
			'activate_plugins',
			'sync-posts-wordpress',
			[$this, 'create_settings_page'],
		);
	}

	/**
	 * Create the actual plugin settings page
	 */
	public function create_settings_page(): void {
		$blade = $GLOBALS['blade'];
		$notice = null;

		if (isset($_GET['action']) && $_GET['action'] == 'dispatch') {
			$notice = 'Sync scheduled';

			do_action('sync_posts_sync');
		}

		echo $blade->render('admin.settings', [
			'plugin_version' => SNYC_POSTS_VERSION,
			'notice' => $notice,
		]);
	}

	/**
	 * Create the settings section
	 */
	public function create_settings_section($args): void {
		echo '<p>These will override the default settings but will not override any applied filters.</p>';
	}

	/**
	 * Render settings output
	 */
	public function render_settings_field($args): void {
		$blade = $GLOBALS['blade'];

		echo $blade->render('admin.partials.text-input', [
			'args' => $args,
		]);
	}

	/**
	 * Create the metabox content
	 */
	public function create_post_metabox(\WP_Post $post) {
		$post_id = $post->ID;

		$user_timezone = wp_timezone_string();

		$modification_date = get_post_meta(
			$post_id,
			'sync_posts_original_modification_date',
			true,
		);
		$modification_date_formatted = Carbon::parse($modification_date)
			->setTimezone($user_timezone)
			->format('M d, Y');

		$last_synced_date = get_post_meta(
			$post_id,
			'sync_posts_last_synced_date_gtm',
			true,
		);

		$last_synced_date_formatted = Carbon::parse($last_synced_date)
			->setTimezone($user_timezone)
			->format('M d, Y g:i a');

		$url = get_post_meta($post_id, 'sync_posts_original_url', true);

		$is_syncing = !empty($_GET['sync_posts_syncing'])
			? $_GET['sync_posts_syncing']
			: false;

		// TODO - refactor into a template
		$html = "<b>URL:</b> <a href='{$url}' target='_blank'>{$url}</a><br /><b>Last modified:</b> {$modification_date_formatted}<br /><b>Last synced:</b> {$last_synced_date_formatted}</p>";

		if ($is_syncing) {
			$html .= '<p>Syncing...</p>';
		} else {
			global $wp;
			$current_url =
				'/wp-admin/post.php' . add_query_arg($_GET, $wp->request);
			$sync_url = $current_url . '&sync_posts_syncing=true';

			$html .= "<p><a href='{$sync_url}' class='button button-primary'>Sync</a></p>";
		}

		echo $html;
	}

	/**
	 * Run a manual sync
	 */
	public function check_for_manual_sync(): void {
		$is_syncing = !empty($_GET['sync_posts_syncing'])
			? $_GET['sync_posts_syncing']
			: false;

		if (!$is_syncing) {
			return;
		}

		$post_id = $_GET['post'];

		// Check to make sure its not already scheduled
		$scheduled_job = as_get_scheduled_actions(
			[
				'hook' => 'sync_posts_sync_single_post',
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
			'sync_posts_sync_single_post',
			[
				'post_id' => $post_id,
			],
			'sync_posts_sync_post',
		);
	}

	/**
	 * Filter for how often the sync should run
	 */
	public function filter_sync_schedule(string $schedule): string {
		return $schedule;
	}

	/**
	 * Filter for the base site url
	 */
	public function filter_site_url(string $url): string {
		return $url;
	}

	/**
	 * Posts per page when hitting the REST API
	 */
	public function filter_posts_per_page(int $posts): int {
		return $posts;
	}

	/**
	 * Posts per page when hitting the REST API
	 */
	public function filters_author_id(int $id): int {
		return $id;
	}

	/**
	 * The post type used for adding the metabox
	 */
	public function filters_post_type_single(string $post_type): string {
		return $post_type;
	}

	/**
	 * The post type used when hitting the REST API
	 */
	public function filters_post_type_plural(string $post_type): string {
		return $post_type;
	}

	/**
	 * The post type used when hitting the REST API
	 */
	public function filters_log_errors(bool $answer): bool {
		return $answer;
	}

	/**
	 * Return a post transient name with the option to create it
	 */
	public static function postTransient(
		array $post,
		bool $create = false
	): string {
		$post_id = self::mutatePostId($post['id']);

		$name = 'sync_posts_temp_' . $post_id;

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
			'sync_posts_site_url',
			self::pluginSetting('sync_posts_site_url'),
		);

		$base_url = rtrim($base_url, '/');

		return $base_url . '/wp-json/wp/v2/';
	}

	/**
	 * Append site url to post id
	 */
	public static function mutatePostId(int $id): string {
		//site url with id append

		$base_url = apply_filters(
			'sync_posts_site_url',
			self::pluginSetting('sync_posts_site_url'),
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
	public static function stripPostId(string $id): string {
		$id_parts = explode('_', $id);

		return end($id_parts);
	}

	/**
	 * Write to local log
	 */
	public static function logError(string $error_message): void {
		$log_errors = apply_filters(
			'sync_posts_log_errors',
			self::pluginSetting('sync_posts_log_errors'),
		);

		if (!$log_errors) {
			return;
		}

		$log_file = plugin_dir_path(__FILE__) . 'error.log';

		$error_message = $error_message . PHP_EOL;

		error_log($error_message, 3, $log_file);
	}

	/**
	 * Grab setting value
	 * Not a fan of this, but wanted to keep defaults if you didn't touch the settings
	 */
	public static function pluginSetting($setting): string {
		if ($setting == 'sync_posts_psite_url') {
			return get_option('sync_posts_site_url', self::DEFAULT_SITE_URL);
		}

		if ($setting == 'sync_posts_pposts_post_per_page') {
			return get_option(
				'sync_posts_post_per_page',
				self::DEFAULT_POSTS_PER_PAGE,
			);
		}

		if ($setting == 'sync_posts_pauthor_id') {
			return get_option(
				'sync_posts_author_id',
				self::DEFAULT_POSTS_AUTHOR_ID,
			);
		}

		if ($setting == 'sync_posts_post_type_single') {
			return get_option(
				'sync_posts_post_type_single',
				self::DEFAULT_POST_TYPE_SINGLE,
			);
		}

		if ($setting == 'sync_posts_post_type_plural') {
			return get_option(
				'sync_posts_post_type_plural',
				self::DEFAULT_POST_TYPE_PLURAL,
			);
		}

		if ($setting == 'sync_posts_log_errors') {
			return get_option(
				'sync_posts_log_errors',
				self::DEFAULT_LOG_ERRORS,
			);
		}

		return '';
	}
}
