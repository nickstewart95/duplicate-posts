<div class="auto-copy-posts-for-wordpress wrap">
    @if (!empty($notice))
        <div class="notice notice-success is-dismissible"><p>{{ $notice }}</p></div>
    @endif
	<h2>Auto Copy Posts for WordPress</h2>
    <br /><a href="/wp-admin/options-general.php?page=sync-posts-wordpress&action=dispatch" class="button button-primary">Run Manual Sync</a>
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