<?php

namespace Nickstewart\AutoCopy;

use Nickstewart\AutoCopy\AutoCopy;
use Nickstewart\AutoCopy\Posts;

use Carbon\Carbon;
use Illuminate\Support\Str;

class Events {
	/**
	 * Fetch each set of posts and schedule each one
	 */
	public static function fetchPosts(int $page): void {
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
				'auto_copy_posts_fetch_posts',
				[
					'page' => $i,
				],
				'auto_copy_posts_fetch',
			);
		}
	}

	/**
	 * Schedule the main sync to run on a cron like schedule
	 */
	public static function scheduleSync(): void {
		$scheduled_job = as_get_scheduled_actions(
			[
				'hook' => 'auto_copy_posts_sync_schedule',
				'group' => 'auto_copy_posts_daily_sync',
			],
			'ARRAY_A',
		);

		$schedule = apply_filters(
			'auto_copy_posts_sync_schedule',
			AutoCopy::pluginSetting('auto_copy_posts_sync_schedule'),
		);

		// Check if the schedule has changed, if so update it
		if (!empty($scheduled_job)) {
			if ($scheduled_job['schedule'] != $schedule) {
				as_unschedule_all_actions(
					'auto_copy_posts_sync_schedule',
					[],
					'auto_copy_posts_daily_sync',
				);
			} else {
				return;
			}
		}

		// Schedule the sync
		as_schedule_cron_action(
			time(),
			$schedule,
			'auto_copy_posts_sync',
			[],
			'auto_copy_posts_daily_sync',
			true,
			10,
		);
	}

	/**
	 * Loop thru the posts array and schedule the post creation
	 */
	public static function schedulePostLoop(array|null $posts): void {
		if (empty($posts)) {
			return;
		}

		foreach ($posts as $post) {
			// Post data is too large to pass to action, so we will store it temporarliy to pass and then delete
			$transient = AutoCopy::postTransient($post, true);

			as_schedule_single_action(
				time(),
				'auto_copy_posts_create_post',
				[
					'transient' => $transient,
				],
				'auto_copy_posts_create',
			);
		}
	}

	/**
	 * Create or update post
	 */
	public static function createPost(string $transient): void {
		$post_transient = get_transient($transient);

		if (empty($post_transient)) {
			AutoCopy::logError('Could not fetch post transient');

			// Todo - Send it back instead of just failing

			return;
		}

		$post = json_decode($post_transient, true);
		$author = apply_filters(
			'auto_copy_posts_author_id',
			AutoCopy::pluginSetting('auto_copy_posts_author_id'),
		);
		$skip_post = apply_filters(
			'auto_copy_posts_post_title_matching',
			AutoCopy::pluginSetting('auto_copy_posts_post_title_matching'),
		);

		if ($skip_post) {
			// Check if post already exists with same title
			$post_exists = \post_exists($post['title']['rendered']);

			if ($post_exists) {
				delete_transient($transient);
				return;
			}
		}

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

		$mutated_id = AutoCopy::mutatePostId($post['id']);

		// Plugin custom meta
		$meta['auto_copy_posts_original_id'] = $mutated_id;
		$meta['auto_copy_posts_original_modification_date'] =
			$post['modified_gmt'];
		$meta['auto_copy_posts_original_url'] = $post['link'];
		$meta['auto_copy_posts_last_synced_date_gtm'] = Carbon::now('UTC');

		// Setup the terms
		$terms_parent = $post['_embedded']['wp:term'];
		$category_ids = [];
		$tag_ids = [];
		$taxonomy_ids = [];

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
				} else {
					// Create or find taxonomy
					$taxonomy = self::createOrFindTaxonomy($term['taxonomy']);

					// Insert taxonomy terms
					$term_taxonomy_id = self::createOrFindTerm(
						$taxonomy,
						$term['name'],
						$term['slug'],
					);

					$taxonomy_ids[$taxonomy] = [$term_taxonomy_id];
				}
			}
		}

		$content = $post['content']['rendered'];

		// Setup post attributes
		$data = [
			'post_title' => $post['title']['rendered'],
			'post_excerpt' => $post['excerpt']['rendered'],
			'post_date' => $post['date'],
			'post_date_gmt' => $post['date_gmt'],
			'meta_input' => $meta,
			'post_content' => $content,
			'post_status' => $post['status'],
			'post_author' => $author,
			'post_type' => $post['type'],
			'post_category' => $category_ids,
			'tags_input' => $tag_ids,
			'tax_input' => $taxonomy_ids,
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

		$download_images = apply_filters(
			'auto_copy_posts_post_images',
			AutoCopy::pluginSetting('auto_copy_posts_post_images'),
		);

		$site_url = apply_filters(
			'auto_copy_posts_site_url',
			AutoCopy::pluginSetting('auto_copy_posts_site_url'),
		);

		$site_url = parse_url($site_url)['host'];
		$site_url = str_replace('www.', '', $site_url);

		if ($download_images) {
			// Find images
			try {
				libxml_use_internal_errors(true);
				$doc = new \DOMDocument();
				$doc->loadHTML($content);
				$images = $doc->getElementsByTagName('img');
				libxml_use_internal_errors(false);
			} catch (\Exception $e) {
				AutoCopy::logError($e->getMessage());
			}

			// Loop thru images and only grab the ones that are local
			$old_images = [];
			if (!empty($images)) {
				foreach ($images as $image) {
					$source = $image->getAttribute('src');

					// If the source contains the site url OR if its a relative url
					if (
						!empty($source) &&
						(!empty(stristr($source, $site_url)) ||
							empty(parse_url($source)['host']))
					) {
						// Create a random key
						$key = Str::random(16);
						if (!empty($old_images[$key])) {
							$key = Str::random(16);
						}

						$old_images[$key] = $source;
					}
				}
			}

			// Download each image and store the URL
			$new_images = [];
			if (!empty($old_images)) {
				foreach ($old_images as $key => $image) {
					try {
						$image_url = media_sideload_image(
							$image,
							$post_id,
							'',
							'src',
						);

						$new_images[$key] = $image_url;
					} catch (\Exception $e) {
						AutoCopy::logError($e->getMessage());
					}
				}
			}

			// Replace each instance in the post with the new URL
			foreach ($old_images as $key => $image) {
				if (!empty($new_images[$key])) {
					$content = str_replace($image, $new_images[$key], $content);
				}
			}

			// Update the post
			$data['post_content'] = $content;

			try {
				wp_update_post($data);
			} catch (\Exception $e) {
				AutoCopy::logError($e->getMessage());
			}
		}
	}

	/**
	 * Create or find a taxonomy
	 */
	public static function createOrFindTaxonomy(string $name): string {
		if (taxonomy_exists($name)) {
			return $name;
		}

		$post_type_single = apply_filters(
			'auto_copy_posts_post_type_single',
			AutoCopy::pluginSetting('auto_copy_posts_post_type_single'),
		);

		$post_type_plural = apply_filters(
			'auto_copy_posts_post_type_plural',
			AutoCopy::pluginSetting('auto_copy_posts_post_type_plural'),
		);

		// Create taxonomy
		$args = [
			'name' => $name,
			'post_type_single' => $post_type_single,
			'post_type_plural' => $post_type_plural,
		];

		register_taxonomy(
			$args['name'],
			$args['post_type_plural'],
			$args['args'],
		);

		// Store in the database so it stays registered
		$registered_taxonomies = get_option(
			'auto_copy_posts_registered_taxonomies',
			[],
		);

		$registered_taxonomies[] = $args;

		update_option(
			'auto_copy_posts_registered_taxonomies',
			$registered_taxonomies,
		);

		return $name;
	}

	/**
	 * Create or find a term
	 */
	public static function createOrFindTerm(
		string $term,
		string $name,
		string $slug = null
	): int {
		$existing_term = term_exists($name, $term);

		if ($existing_term) {
			return (int) $existing_term['term_taxonomy_id'];
		}

		$term = wp_insert_term($name, $term, [
			'slug' => $slug,
		]);

		return (int) $term['term_taxonomy_id'];
	}

	/**
	 * Download the featured image and set it as featured image on the new post
	 */
	public static function setFeaturedImage(
		int $post_id,
		string $featured_image_url,
		string $description = null
	): void {
		try {
			$featured_image_attachment = media_sideload_image(
				$featured_image_url,
				$post_id,
				$description,
				'id',
			);

			set_post_thumbnail($post_id, $featured_image_attachment);
		} catch (\Exception $e) {
			AutoCopy::logError($e->getMessage());
		}
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
				'auto_copy_posts_original_id',
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
	public static function syncPost(int $post_id): void {
		$posts = Posts::requestPost($post_id);

		if (empty($posts)) {
			return;
		}

		self::schedulePostLoop($posts);
	}

	/**
	 * Delete all synced posts
	 */
	public static function deletePosts(): void {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
SELECT post_id, meta_value
FROM {$wpdb->prefix}postmeta
WHERE meta_key = %s
",
				'auto_copy_posts_original_id',
			),
		);

		if (!empty($results)) {
			foreach ($results as $result) {
				as_schedule_single_action(
					time(),
					'auto_copy_posts_delete_post',
					[
						'post_id' => $result->post_id,
					],
					'auto_copy_posts_delete',
				);
			}
		}
	}

	public static function deletePost(int $post_id): void {
		wp_delete_post($post_id, true);
	}
}
