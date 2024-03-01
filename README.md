# Duplicate Posts

A simple WordPress plugin that can duplicate posts from one WordPress site to another using the REST API

## How it works

There is an action, `duplicate_posts_sync`, that will query a WordPress site, `duplicate_posts_site_url`, and loop thru chunks, `duplicate_posts_post_per_page` of posts. Each chunk gets an event, `fetch_posts`, to avoid query too many posts at once on the source site.

Inside of `fetch_posts`, each post gets sent to an event, `create_post`, that will actually create or update the post locally. This is also in a job to prevent any server stress when creating too many posts at once.

## Dev Guide

### Filters

`duplicate_posts_sync_schedule` - The cron schedule for how often the sync runs

`duplicate_posts_site_url` - The url of the WordPress site posts are being duplicated from

`duplicate_posts_post_per_page` - The number of posts being grabbed at a time

`duplicate_posts_author_id` - The author ID copied posts are associated with

### Actions

`duplicate_posts_sync` - Starts the sync

### Scheduled Events

`fetch_posts` - Fetches posts per page and then creates events for each
`create_post` - Creates/updates individual posts

## Todo

- [x] Meta box in post editor showing that the post has been copied
- [ ] Handle featured image
- [ ] Handle taxonomy and categories
- [ ] Better error handling and logging
- [ ] Refactor REST related code into an API class
- [ ] Refactor events related code into an Events class
- [ ] Add filters to support multiple post types
- [ ] Implement coding standards
