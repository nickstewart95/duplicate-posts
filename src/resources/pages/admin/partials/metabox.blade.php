<b>URL:</b> <a href='{{ $url }}' target='_blank'>{{ $url }}</a><br />
<b>Last modified:</b> {{ $modification_date_formatted }}<br />
<b>Last synced:</b> {{ $last_synced_date_formatted }}</p>

@if ($is_syncing)
    <p>Syncing...</p>
@else
    <p><a href='{{ $sync_url }}' class='button button-primary'>Sync</a></p>
@endif
