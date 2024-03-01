<?php

namespace Nickstewart\DuplicatePosts;

define('DUPLICATE_POSTS_VERSION', '1.0.0');
define('DUPLICATE_POSTS_FILE', __FILE__);

use Carbon\Carbon;
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
		add_action('admin_menu', [$this, 'add_metabox_to_posts']);
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
	 * Call the metabox creation
	 */
	public function add_metabox_to_posts(): void {
		add_meta_box(
			'duplicate_posts_post_information',
			'Duplicate Post Information',
			[$this, 'create_post_metabox'],
			'post',
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

		$url = get_post_meta($post_id, 'duplicate_posts_original_url', true);

		echo "<b>URL:</b> <a href='{$url}' target='_blank'>{$url}</a><br /><b>Last modified:</b> {$modification_date_formatted}";
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
				'query' => [
					'_embed' => 1,
					'per_page' => $posts_per_page,
					'page' => $page,
				],
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

		// Plugin custom meta
		$meta['duplicate_posts_original_id'] = $post['id'];
		$meta['duplicate_posts_original_modification_date'] =
			$post['modified_gmt'];
		$meta['duplicate_posts_original_url'] = $post['link'];

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
		$local_post = !empty($existing_posts[$post['id']])
			? $existing_posts[$post['id']]
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
	 * Download the featured image and set it as featured image on the new post
	 */
	public function setFeaturedImage(
		$post_id,
		$featured_image_url,
		$description = null
	): void {
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
	 * Set a transient with the post data
	 */
	public function setPostTransient($post): string {
		$name = 'duplicate_posts_temp_' . $post['id'];
		$post = json_encode($post);

		set_transient($name, $post, DAY_IN_SECONDS);

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
}
