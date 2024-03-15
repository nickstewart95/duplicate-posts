<div class="sync-posts-for-wordpress wrap">
	<h2>Sync Posts for WordPress</h2>
    <form action="options.php" method="post">
    @php
        settings_fields('sync_posts_wordpress');
        do_settings_sections('sync-posts-wordpress');
        submit_button('Save Settings');
    @endphp
	</form>
    <h3>About</h3>
    <p>Created by <a href="https://nickstewart.me">Nick Stewart</a>. Plugin documentation and developer guide can be found on <a href="https://github.com/nickstewart95/sync-posts-for-wordpress">GitHub</a>.</p>
    <p>Running version {{ $plugin_version }}</p>
</div>