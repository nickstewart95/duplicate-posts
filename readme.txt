=== Auto Copy Posts for WordPress ===
Author: Nick Stewart
Author URI: https://nickstewart.me
Tags: sync, copy, posts
Requires at least: 5.3
Tested up to: 6.1.1
Stable tag: 1.6.0
Requires PHP: 8.0

Sync posts from one Wordpress site to another

== Description ==

A simple WordPress plugin that can sync posts from one WordPress site to another using the REST API.

== Changelog ==

= 1.6 =

- Added ability for multiple post types to be synced
- Added ability to set the local post type
- Removed filters for the single and plural post type
- Refactored metabox into a Blade template

= 1.5 =

- Fixed a possible bug with reusing Blade global name
- Fixed a bug with images always being copied, even if they were copied in the past
- Added ability to delete duplicate images, from the above bug

= 1.4 =

- Bug fix, include Action Scheduler correctly

= 1.3 =

- Added ability to delete all synced posts
- Added a table in the settings that shows all synced post
- Bug fixes with title matching

= 1.2 =

- Added title option matching
- Added option to download post images
- Bug fixes

= 1.1 =

- Remove plugin settings and actions when uninstalling
- Created plugin settings page

= 1.0 =

- The plugin creation
