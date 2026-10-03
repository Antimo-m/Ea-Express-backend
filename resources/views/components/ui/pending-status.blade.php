@props(['account'])
<x-ui.status-badge class="status-pill tone-{{ $account->statusTone() }}">{{ $account->statusLabel() }}</x-ui.status-badge>
