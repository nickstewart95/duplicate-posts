<?php

namespace Nickstewart\DuplicatePosts;

define('DUPLICATE_POSTS_VERSION', '1.0.0');
define('DUPLICATE_POSTS_FILE', __FILE__);

use Carbon\Carbon;

use Nickstewart\DuplicatePosts\DuplicatePosts;
use Nickstewart\DuplicatePosts\Posts;

class Events {
	/**
	 * Fetch each set of posts and schedule each one
	 */
	public static function fetchPosts($page): void {
		$response = Posts::requestPosts($page);

		if (!$response) {
			return;
		}

		$posts = $response['posts'];

		self::schedulePostLoop($posts);
	}

	/**
	 * Schedule the posts per page to prevent timeouts
	 */
	public static function schedulePosts(): void {
		$response = Posts::requestPosts(1);

		if (!$response) {
			return;
		}

		$page_count = $response['page_count'];
		$posts = $response['posts'];

		self::schedulePostLoop($posts);

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
	 * Schedule the main sync to run on a cron like schedule
	 */
	public static function scheduleSync(): void {
		$scheduled_job = as_get_scheduled_actions(
			[
				'hook' => 'duplicate_posts_sync_schedule',
				'group' => 'duplicate_posts_daily_sync',
			],
			'ARRAY_A',
		);

		$schedule = apply_filters(
			'duplicate_posts_sync_schedule',
			DuplicatePosts::DEFAULT_SYNC_SCHEDULE,
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
	 * Loop thru the posts array and schedule the post creation
	 */
	public static function schedulePostLoop($posts): void {
		if (empty($posts)) {
			return;
		}

		foreach ($posts as $post) {
			// Post data is too large to pass to action, so we will store it temporarliy to pass and then delete
			$transient = DuplicatePosts::postTransient($post, true);

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
	public static function createPost($transient): void {
		$post_transient = get_transient($transient);

		if (empty($post_transient)) {
			DuplicatePosts::logError('Could not fetch post transient');

			return;
		}

		$post = json_decode($post_transient, true);
		$author = apply_filters(
			'duplicate_posts_author_id',
			DuplicatePosts::DEFAULT_POSTS_AUTHOR_ID,
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

		$mutated_id = DuplicatePosts::mutatePostId($post['id']);

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
					$category_ids[] = self::createOrFindTerm(
						'category',
						$term['name'],
						$term['slug'],
					);
				} elseif ($term['taxonomy'] == 'post_tag') {
					$tag_ids[] = self::createOrFindTerm(
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
		$existing_posts = self::findExistingPosts();
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
			self::setFeaturedImage(
				$post_id,
				$featured_image_url,
				$featured_image_alt_text,
			);
		}

		return;
	}

	/**
	 * Create or find a term
	 */
	public static function createOrFindTerm($term, $name, $slug = null): int {
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
	 * Download the featured image and set it as featured image on the new post
	 */
	public static function setFeaturedImage(
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
	 * Find existing copied posts
	 */
	public static function findExistingPosts(): array {
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
	 * Sync a single post
	 */
	public static function syncPost($post_id): void {
		$posts = Posts::requestPost($post_id);

		if (empty($posts)) {
			return;
		}

		self::schedulePostLoop($posts);

		return;
	}
}
