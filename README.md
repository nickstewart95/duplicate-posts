# Duplicate Posts

A simple WordPress plugin that can duplicate posts from one WordPress site to another using the REST API.

While this plugin works, it was more so a project than an production plugin, hence the name conflict with other similar plugins.

## How it works

There is an action, `duplicate_posts_sync`, that will query a WordPress site, `duplicate_posts_site_url`, and loop thru chunks, `duplicate_posts_post_per_page` of posts. Each chunk gets an event, `fetch_posts`, to avoid query too many posts at once on the source site.

Inside of `fetch_posts`, each post gets sent to an event, `create_post`, that will actually create or update the post locally. This is also in a job to prevent any server stress when creating too many posts at once.

## Dev Guide

### Filters

`duplicate_posts_sync_schedule` - The cron schedule for how often the sync runs

`duplicate_posts_site_url` - The url of the WordPress site posts are being duplicated from

`duplicate_posts_post_per_page` - The number of posts being grabbed at a time

`duplicate_posts_author_id` - The author ID copied posts are associated with

`duplicate_posts_post_type` - The post type being retrieved

### Actions

`duplicate_posts_sync` - Starts the sync

### Scheduled Events

`duplicate_posts_fetch_posts` - Fetches posts per page and then creates events for each

`duplicate_posts_create_post` - Creates/updates individual posts

`duplicate_posts_sync_single_post` - Syncs a single post

### Meta Data

`duplicate_posts_original_id` - The post ID from the source site

`duplicate_posts_original_modification_date` - The last modified date from the source site

`duplicate_posts_original_url` - The post URL from the source site

`duplicate_posts_last_synced_date_gtm` -The last time the post was synced over

## Todo

- [x] Meta box in post editor showing that the post has been copied
- [x] Handle featured image
- [x] Handle taxonomy and categories
- [ ] Handle taxonomy and category parents
- [ ] Better error handling and logging
- [ ] Refactor REST related code into an API class
- [ ] Refactor events related code into an Events class
- [x] Add filters to support multiple post types
- [ ] Implement coding standards
- [x] Add timestamp when sync for post is completed
- [x] Add functionality to sync an individual posts
- [ ] Rename the plugin for production use
