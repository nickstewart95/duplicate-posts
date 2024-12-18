<?php

namespace Nickstewart\AutoCopy;

use Nickstewart\AutoCopy\AutoCopy;

use GuzzleHttp\Client;

class Posts {
	/**
	 * Helper function that returns a set of posts
	 */
	public static function requestPosts(
		int $page,
		string $post_type = null
	): bool|array {
		$base_url = AutoCopy::getSiteUrl();

		$post_type = $post_type ?? AutoCopy::DEFAULT_POST_TYPE_PLURAL;

		$posts_per_page = apply_filters(
			'auto_copy_posts_post_per_page',
			AutoCopy::pluginSetting('auto_copy_posts_post_per_page'),
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
				AutoCopy::logError($error_message);

				return false;
			}
		} catch (\Exception $e) {
			AutoCopy::logError($e->getMessage());
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
	public static function requestPost(int $post_id) {
		$original_post_id = get_post_meta(
			$post_id,
			'auto_copy_posts_original_id',
			true,
		);

		$original_post_id_stripped = AutoCopy::stripPostId($original_post_id);

		$base_url = AutoCopy::getSiteUrl();

		$post_type = apply_filters(
			'auto_copy_posts_post_type_plural',
			AutoCopy::pluginSetting('auto_copy_posts_post_type_plural'),
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
				AutoCopy::logError($error_message);
			}
		} catch (\Exception $e) {
			AutoCopy::logError($e->getMessage());
			return;
		}

		return json_decode($response->getBody(), true);
	}
}
