@props(['account'])
<span class="status-pill tone-{{ $account->statusTone() }}">{{ $account->statusLabel() }}</span>
