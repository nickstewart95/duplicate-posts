<?php

namespace Nickstewart\DuplicatePosts;

define('DUPLICATE_POSTS_VERSION', '1.0.0');
define('DUPLICATE_POSTS_FILE', __FILE__);

use Carbon\Carbon;
use GuzzleHttp\Client;

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
		add_action('action_scheduler_init', [$this, 'scheduleSync']);
	}

	/**
	 * Setup actions
	 */
	public function initActions(): void {
		add_action('duplicate_posts_sync', [$this, 'sync'], 10, 0);
		add_action('duplicate_posts_fetch_posts', [$this, 'fetchPosts'], 10, 1);
		add_action('duplicate_posts_create_post', [$this, 'createPost'], 10, 1);
		add_action(
			'duplicate_posts_sync_single_post',
			[$this, 'syncPost'],
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
		$api = new DuplicatePosts();
		$api->schedulePosts();
	}

	/**
	 * Schedule the main sync to run on a cron like schedule
	 */
	public function scheduleSync(): void {
		$scheduled_job = as_get_scheduled_actions(
			[
				'hook' => 'duplicate_posts_sync_schedule',
				'group' => 'duplicate_posts_daily_sync',
			],
			'ARRAY_A',
		);

		$schedule = apply_filters(
			'duplicate_posts_sync_schedule',
			self::DEFAULT_SYNC_SCHEDULE,
		);

		// Check if the schedule has changed, if so update it
		if (!empty($scheduled_job)) {
			if ($scheduled_job['schedule'] != $schedule) {
				as_unschedule_all_actions(
					'duplicate_posts_sync_schedule',
					[],
					'duplicate_posts_daily_sync',
				);
			} else {
				return;
			}
		}

		// Schedule the sync
		as_schedule_cron_action(
			time(),
			$schedule,
			'duplicate_posts_sync',
			[],
			'duplicate_posts_daily_sync',
			true,
			10,
		);
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
	 * Helper function that returns a set of posts
	 */
	public function requestPosts($page): bool|array {
		$base_url = $this->getSiteUrl();

		$post_type = apply_filters(
			'duplicate_posts_post_type_plural',
			self::DEFAULT_POST_TYPE_PLURAL,
		);

		$posts_per_page = apply_filters(
			'duplicate_posts_post_per_page',
			self::DEFAULT_POSTS_PER_PAGE,
		);

		$client = new Client([
			'base_uri' => $base_url,
		]);

		try {
			$response = $client->request('GET', $post_type, [
				'query' => [
					'_embed' => 1,
					'per_page' => $posts_per_page,
					'page' => $page,
				],
			]);

			if ($response->getStatusCode() !== 200) {
				$error_message =
					'Error fetching posts: ' . $response->getStatusCode();
				$this->logError($error_message);

				return false;
			}
		} catch (\Exception $e) {
			$this->logError($e->getMessage());
			return false;
		}

		$page_count = $response->getHeader('X-WP-TotalPages')[0];

		$posts = [];
		$posts['posts'] = json_decode($response->getBody(), true);
		$posts['page_count'] = $page_count;

		return $posts;
	}

	/**
	 * Fetch each set of posts and schedule each one
	 */
	public function fetchPosts($page): void {
		$response = $this->requestPosts($page);

		if (!$response) {
			return;
		}

		$posts = $response['posts'];

		$this->schedulePostLoop($posts);
	}

	/**
	 * Schedule the posts per page to prevent timeouts
	 */
	public function schedulePosts(): void {
		$response = $this->requestPosts(1);

		if (!$response) {
			return;
		}

		$page_count = $response['page_count'];
		$posts = $response['posts'];

		$this->schedulePostLoop($posts);

		// Schedule the additional requests
		for ($i = 2; $i < $page_count + 1; $i++) {
			as_schedule_single_action(
				time(),
				'duplicate_posts_fetch_posts',
				[
					'page' => $i,
				],
				'duplicate_posts_fetch',
			);
		}
	}

	/**
	 * Loop thru the posts array and schedule the post creation
	 */
	public function schedulePostLoop($posts): void {
		if (empty($posts)) {
			return;
		}

		foreach ($posts as $post) {
			// Post data is too large to pass to action, so we will store it temporarliy to pass and then delete
			$transient = $this->postTransient($post, true);

			as_schedule_single_action(
				time(),
				'duplicate_posts_create_post',
				[
					'transient' => $transient,
				],
				'duplicate_posts_create',
			);
		}
	}

	/**
	 * Create or update post
	 */
	public function createPost($transient): void {
		$post_transient = get_transient($transient);

		if (empty($post_transient)) {
			$this->logError('Could not fetch post transient');

			return;
		}

		$post = json_decode($post_transient, true);
		$author = apply_filters(
			'duplicate_posts_author_id',
			self::DEFAULT_POSTS_AUTHOR_ID,
		);

		$featured_image_url = null;
		$featured_image_alt_text = null;
		if (!empty($post['_embedded']['wp:featuredmedia'][0]['source_url'])) {
			$featured_image_url =
				$post['_embedded']['wp:featuredmedia'][0]['source_url'] ?: '';
			$featured_image_alt_text =
				$post['_embedded']['wp:featuredmedia'][0]['alt_text'] ?: '';
		}

		// Meta from the post
		$meta = $post['meta'];

		$mutated_id = $this->mutatePostId($post['id']);

		// Plugin custom meta
		$meta['duplicate_posts_original_id'] = $mutated_id;
		$meta['duplicate_posts_original_modification_date'] =
			$post['modified_gmt'];
		$meta['duplicate_posts_original_url'] = $post['link'];
		$meta['duplicate_posts_last_synced_date_gtm'] = Carbon::now('UTC');

		// Setup the terms
		$terms_parent = $post['_embedded']['wp:term'];
		$category_ids = [];
		$tag_ids = [];

		foreach ($terms_parent as $terms) {
			foreach ($terms as $term) {
				if (empty($term['name'])) {
					continue;
				}

				if ($term['taxonomy'] == 'category') {
					$category_ids[] = $this->createOrFindTerm(
						'category',
						$term['name'],
						$term['slug'],
					);
				} elseif ($term['taxonomy'] == 'post_tag') {
					$tag_ids[] = $this->createOrFindTerm(
						'post_tag',
						$term['name'],
						$term['slug'],
					);
				}
			}
		}

		// Setup post attributes
		$data = [
			'post_title' => $post['title']['rendered'],
			'post_excerpt' => $post['excerpt']['rendered'],
			'meta_input' => $meta,
			'post_content' => $post['content']['rendered'],
			'post_status' => $post['status'],
			'post_author' => $author,
			'post_type' => $post['type'],
			'post_category' => $category_ids,
			'tags_input' => $tag_ids,
		];

		delete_transient($transient);

		// Check to see if post has been created
		$existing_posts = $this->findExistingPosts();
		$local_post = !empty($existing_posts[$mutated_id])
			? $existing_posts[$mutated_id]
			: false;

		// Update or create
		if ($local_post) {
			$data['ID'] = $local_post;
			$post_id = wp_update_post($data);
		} else {
			$post_id = wp_insert_post($data);
		}

		if ($featured_image_url) {
			$this->setFeaturedImage(
				$post_id,
				$featured_image_url,
				$featured_image_alt_text,
			);
		}

		return;
	}

	/**
	 * Sync a single post
	 */
	public function syncPost($post_id): void {
		$original_post_id = get_post_meta(
			$post_id,
			'duplicate_posts_original_id',
			true,
		);

		$original_post_id_stripped = $this->stripPostId($original_post_id);

		$base_url = $this->getSiteUrl();

		$post_type = apply_filters(
			'duplicate_posts_post_type_plural',
			self::DEFAULT_POST_TYPE_PLURAL,
		);

		$client = new Client([
			'base_uri' => $base_url,
		]);

		try {
			$response = $client->request('GET', $post_type, [
				'query' => [
					'_embed' => 1,
					'include' => $original_post_id_stripped,
				],
			]);

			if ($response->getStatusCode() !== 200) {
				$error_message =
					'Error fetching single post: ' . $response->getStatusCode();
				$this->logError($error_message);
			}
		} catch (\Exception $e) {
			$this->logError($e->getMessage());
			return;
		}

		$posts = json_decode($response->getBody(), true);

		$this->schedulePostLoop($posts);

		return;
	}

	/**
	 * Download the featured image and set it as featured image on the new post
	 */
	public function setFeaturedImage(
		$post_id,
		$featured_image_url,
		$description = null
	): void {
		// TODO - error handling, can result in timeout errors
		$featured_image_attachment = media_sideload_image(
			$featured_image_url,
			$post_id,
			$description,
			'id',
		);

		set_post_thumbnail($post_id, $featured_image_attachment);
	}

	/**
	 * Create or find a term
	 */
	public function createOrFindTerm($term, $name, $slug = null): int {
		$existing_term = term_exists($name, $term);

		if ($existing_term) {
			return $existing_term['term_id'];
		}

		$term = wp_insert_term($name, $term, [
			'slug' => $slug,
		]);

		return $term['term_id'];
	}

	/**
	 * Return a post transient name with the option to create it
	 */
	public function postTransient($post, $create = false): string {
		$post_id = $this->mutatePostId($post['id']);

		$name = 'duplicate_posts_temp_' . $post_id;

		if ($create) {
			$post = json_encode($post);

			set_transient($name, $post, DAY_IN_SECONDS);
		}

		return $name;
	}

	/**
	 * Find existing copied posts
	 */
	public function findExistingPosts(): array {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
SELECT post_id, meta_value
FROM {$wpdb->prefix}postmeta
WHERE meta_key = %s
",
				'duplicate_posts_original_id',
			),
		);

		$data = [];

		if (!empty($results)) {
			foreach ($results as $result) {
				$data[$result->meta_value] = $result->post_id;
			}
		}

		return $data;
	}

	/**
	 * Get the site url for the API
	 */
	public function getSiteUrl(): string {
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
	public function mutatePostId($id): string {
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
	public function stripPostId($id): string {
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
