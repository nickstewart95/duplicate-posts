<?php

namespace Nickstewart\DuplicatePosts;

define('DUPLICATE_POSTS_VERSION', '1.0.0');
define('DUPLICATE_POSTS_FILE', __FILE__);

use GuzzleHttp\Client;

class DuplicatePosts {
	private static $instance = null;

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
	}

	/**
	 * Setup actions
	 */
	public function initActions(): void {
		add_action('duplicate_posts_sync', [$this, 'sync'], 10, 0);
		add_action('duplicate_posts_fetch_posts', [$this, 'fetchPosts'], 10, 1);
		add_action('duplicate_posts_create_post', [$this, 'createPost'], 10, 1);
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
	}

	/**
	 * The sync action that basically runs the plugin
	 */
	public function sync(): void {
		$api = new DuplicatePosts();
		$api->schedulePosts();
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
	 * Helper function that returns a set of posts
	 */
	public function requestPosts($page): bool|array {
		$base_url = apply_filters(
			'duplicate_posts_site_url',
			'https://tjwrestling.com',
		);
		$base_url = $base_url . '/wp-json/wp/v2/'; // TODO - check for trailing slash

		$posts_per_page = apply_filters('duplicate_posts_post_per_page', 10);

		$client = new Client([
			'base_uri' => $base_url,
		]);

		try {
			$response = $client->request('GET', 'posts', [
				'query' => ['per_page' => $posts_per_page, 'page' => $page],
			]);

			if ($response->getStatusCode() !== 200) {
				return false;
			}
		} catch (\Exception $e) {
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

		foreach ($posts as $post) {
			// Post data is too large to pass to action, so we will store it temporarliy to pass and then delete
			$transient = $this->setPostTransient($post);

			as_schedule_single_action(
				time(),
				'duplicate_posts_create_post',
				[
					'transient' => $transient,
				],
				'duplicate_posts',
			);
		}
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

		// Create the posts for the first page pulled
		// TODO - refactor
		foreach ($posts as $post) {
			// Post data is too large to pass to action, so we will store it temporarliy to pass and then delete
			$transient = $this->setPostTransient($post);

			as_schedule_single_action(
				time(),
				'duplicate_posts_create_post',
				[
					'transient' => $transient,
				],
				'duplicate_posts_create',
			);
		}

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
	 * Create or update post
	 */
	public function createPost($transient): void {
		$post_transient = get_transient($transient);

		if (empty($post_transient)) {
			return;
		}

		$post = json_decode($post_transient, true);

		$author = apply_filters('duplicate_posts_author_id', 1);

		// Meta from the post
		$meta = $post['meta'];

		// Plugin custom meta
		$meta['duplicate_posts_original_id'] = $post['id'];
		$meta['duplicate_posts_original_modification_date'] =
			$post['modified_gmt'];
		$meta['duplicate_posts_original_url'] = $post['link'];

		// Setup post attributes
		$data = [
			'post_title' => $post['title']['rendered'],
			'post_date' => $post['date'],
			'post_date_gmt' => $post['date_gmt'],
			'post_excerpt' => $post['excerpt']['rendered'],
			'meta_input' => $meta,
			'post_content' => $post['content']['rendered'],
			'post_status' => $post['status'],
			'post_author' => $author,
			'post_type' => $post['type'],
		];

		delete_transient($transient);

		// Check to see if post has been created
		$local_post = false;

		// Update
		if (!empty($local_post)) {
			wp_update_post($data);

			return;
		}

		// Create
		wp_insert_post($data);

		return;
	}

	/**
	 * Set a transient with the post data
	 */
	public function setPostTransient($post): string {
		$name = 'duplicate_posts_temp_' . $post['id'];
		$post = json_encode($post);

		set_transient($name, $post, DAY_IN_SECONDS);

		return $name;
	}
}
