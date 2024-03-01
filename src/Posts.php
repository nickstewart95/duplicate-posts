<?php

namespace Nickstewart\DuplicatePosts;

define('DUPLICATE_POSTS_VERSION', '1.0.0');
define('DUPLICATE_POSTS_FILE', __FILE__);

use Carbon\Carbon;
use GuzzleHttp\Client;

use Nickstewart\DuplicatePosts\DuplicatePosts;

class Posts {
	/**
	 * Helper function that returns a set of posts
	 */
	public static function requestPosts($page): bool|array {
		$base_url = DuplicatePosts::getSiteUrl();

		$post_type = apply_filters(
			'duplicate_posts_post_type_plural',
			DuplicatePosts::DEFAULT_POST_TYPE_PLURAL,
		);

		$posts_per_page = apply_filters(
			'duplicate_posts_post_per_page',
			DuplicatePosts::DEFAULT_POSTS_PER_PAGE,
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
				DuplicatePosts::logError($error_message);

				return false;
			}
		} catch (\Exception $e) {
			DuplicatePosts::logError($e->getMessage());
			return false;
		}

		$page_count = $response->getHeader('X-WP-TotalPages')[0];

		$posts = [];
		$posts['posts'] = json_decode($response->getBody(), true);
		$posts['page_count'] = $page_count;

		return $posts;
	}

	/**
	 * Helper function that returns a single post
	 */
	public static function requestPost($post_id) {
		$original_post_id = get_post_meta(
			$post_id,
			'duplicate_posts_original_id',
			true,
		);

		$original_post_id_stripped = DuplicatePosts::stripPostId(
			$original_post_id,
		);

		$base_url = DuplicatePosts::getSiteUrl();

		$post_type = apply_filters(
			'duplicate_posts_post_type_plural',
			DuplicatePosts::DEFAULT_POST_TYPE_PLURAL,
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
				DuplicatePosts::logError($error_message);
			}
		} catch (\Exception $e) {
			DuplicatePosts::logError($e->getMessage());
			return;
		}

		return $posts = json_decode($response->getBody(), true);
	}
}
