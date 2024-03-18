<div class="auto-copy-posts-for-wordpress wrap">
	@if (!empty($notice))
		<div class="notice notice-success is-dismissible"><p>{{ $notice }}</p></div>
	@endif
	<h2>Auto Copy Posts for WordPress</h2>
	<br /><a href="/wp-admin/options-general.php?page=auto-copy-posts-wordpress&action=dispatch" class="button button-primary">Run Manual Sync</a>
	<br /><br /><a href="/wp-admin/options-general.php?page=auto-copy-posts-wordpress&action=delete" class="button button-secondary">Delete Synced Posts</a>
	<form action="options.php" method="post">
	@php
		settings_fields('auto_copy_posts_wordpress');
		do_settings_sections('auto-copy-posts-wordpress');
		submit_button('Save Settings');
	@endphp
	</form>
	<h2>Synced Posts</h2>
	<table class="wp-list-table widefat fixed striped posts">
		<thead>
			<tr>
				<th scope="col" class="manage-column">ID</th>
				<th scope="col" class="manage-column">Date</th>
				<th scope="col" class="manage-column">Title</th>
				<th scope="col" class="manage-column"></th>
			</tr>
		</thead>
		<tbody id="the-list">
			@foreach ($posts as $post)
			<tr id="post-1">
				<td data-colname="ID">
					{{ $post->post_id }}
				</td>
				<td data-colname="Date">
					{{ $post->post_date }}
				</td>
				<td data-colname="Title">
					{{ $post->post_title }}
				</td>
				<td data-colname="View">
					<a href="/wp-admin/post.php?post={{ $post->post_id }}&action=edit" target="_blank">View</a>
				</td>
			</tr>
			@endforeach
		</tbody>
	</table>
	<h3>About</h3>
	<p>Created by <a href="https://nickstewart.me">Nick Stewart</a>. Plugin documentation and developer guide can be found on <a href="https://github.com/nickstewart95/auto-copy-posts-for-wordpress">GitHub</a>.</p>
	<p>Running version {{ $plugin_version }}</p>
</div>