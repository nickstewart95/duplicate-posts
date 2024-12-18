# Auto Copy Posts for WordPress

![banner](https://raw.githubusercontent.com/nickstewart95/auto-copy-posts-for-wordpress/main/banner-772x250.png)

A simple WordPress plugin that can sync posts from one WordPress site to another using the REST API.

## How it works

There is an action, `auto_copy_posts_sync`, that will query a WordPress site, `auto_copy_posts_site_url`, and loop thru chunks, `auto_copy_posts_post_per_page` of posts. Each chunk gets an event, `fetch_posts`, to avoid query too many posts at once on the source site.

Inside of `fetch_posts`, each post gets sent to an event, `create_post`, that will actually create or update the post locally. This is also in a job to prevent any server stress when creating too many posts at once.

## Installation

Drop the plugin folder in your WordPress plugins folder and run `composer install` inside the plugin folder to install the required libraries.

To customize the plugin, please make use of the plugin settings or the filters.

The plugin settings can be found by going to Settings -> Sync Post Settings. Note that filters will override settings.

An example filter:

```
add_filter('auto_copy_posts_site_url', function () {
	return 'https://yokoco.com/';
});
```

The daily sync event will automatically be created when the plugin is loaded.

If you do not want to wait, you can use the ActionScheduler plugin to manually run this event or run `do_action('auto_copy_posts_sync')` to start a sync.

Clicking into an individual post (that is a copied post) will display a metabox that will allow you to manually since that one one post.

## Uninstall

If you deactive and delete the plugin, the plugin settings and scheduled actions will be removed and deleted. In order to prevent breaking the synced posts, the `auto_copy_posts_registered_taxonomies` setting does not get deleted, that way the custom taxonomy that have been synced do not break.

To reapply those taxonomies outside of the plugin, copy the following code to your theme's `functions.php`

```
add_action('init', function() {
	$registered_taxonomies = get_option(
		'auto_copy_posts_registered_taxonomies',
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
});
```

## Dev Guide

Errors are logged to error.log inside the plugin folder

### Filters

`auto_copy_posts_sync_schedule` - The cron schedule for how often the sync runs, default value is `0 4,14 * * *`;

`auto_copy_posts_site_url` - The url of the WordPress site posts are being syncd from, default value is `https://tjwrestling.com`

`auto_copy_posts_post_per_page` - The number of posts being grabbed at a time, default value is `10`

`auto_copy_posts_author_id` - The author ID copied posts are associated with, default value is `1`

`auto_copy_posts_log_errors` - If errors should be logged, default value is `true`

`auto_copy_posts_post_images` - If relative images are found in the post if they should be downloaded and made relative, default value is `false`

`auto_copy_posts_post_title_matching` - If a post has the same title as a post being synced over, skip it

### Actions

`auto_copy_posts_sync` - Starts the sync

`auto_copy_posts_delete_synced_posts` - Deletes posts that have been synced over

### Scheduled Events

`auto_copy_posts_fetch_posts` - Fetches posts per page and then creates events for each

`auto_copy_posts_create_post` - Creates/updates individual posts

`auto_copy_posts_delete_post` - Deletes individual posts

`auto_copy_posts_sync_single_post` - Syncs a single post

### Meta Data

`auto_copy_posts_original_id` - The post ID from the source site, prepended with the top level domain it came from

`auto_copy_posts_original_modification_date` - The last modified date from the source site

`auto_copy_posts_original_url` - The post URL from the source site

`auto_copy_posts_last_synced_date_gtm` -The last time the post was synced over

### Option Data

`auto_copy_posts_registered_taxonomies` - Contains the taxonomy data from copied posts so they can be recreated

## Todo

May or may not get to these todo items, more so if I have time and noticed I should add something or make it better

- [x] Meta box in post editor showing that the post has been copied
- [x] Handle featured image
- [x] Handle taxonomy and categories
- [ ] Handle taxonomy and category parents (not looking at parents, will need to do some sorta recursive behavior)
- [x] Better error handling and logging
- [x] Refactor REST related code into an API class
- [x] Refactor events related code into an Events class
- [x] Add filters to support multiple post types
- [ ] Implement coding standards (had issues with the WordPress standards not ignoring the filename rule)
- [x] Add timestamp when sync for post is completed
- [x] Add functionality to sync an individual posts
- [x] Rename the plugin for production use
- [x] Branding
- [ ] Space the jobs out (currently all the jobs just sorta fire)
- [x] Remove related events when plugin is uninstalled
- [x] Copy over media
- [x] Add option to match on title

## Credits

- Icon comes from the [Material Symbols](https://fonts.google.com/icons) icon set
- Using [Action Scheduler](https://actionscheduler.org/), [Blade](https://github.com/jenssegers/blade), [Carbon](https://carbon.nesbot.com/), and [Guzzle](https://github.com/guzzle/guzzle) under the hood
