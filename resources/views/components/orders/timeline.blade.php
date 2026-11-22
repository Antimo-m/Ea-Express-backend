@props(['events'])
@php
    $icons = ['received' => 'inbox', 'accepted' => 'check2-circle', 'pickup_scheduled' => 'calendar2-check', 'rider_arriving' => 'bicycle', 'picked_up' => 'box-seam', 'in_transit' => 'truck', 'out_for_delivery' => 'geo-alt', 'delivered' => 'check2-all', 'delivery_attempted' => 'exclamation-circle', 'rescheduled' => 'calendar2-week', 'delivery_issue' => 'exclamation-triangle', 'rejected' => 'x-circle', 'cancelled' => 'x-octagon'];
    $lastOnPage = $events->count() - 1;
    $isLatestPage = $events->currentPage() === $events->lastPage();
@endphp
<ol class="tracking-timeline order-timeline mt-3">@forelse($events as $event)
    @php
        $rescheduled = isset($event->schedule_change['after']['pickup_date']);
        $tone = $rescheduled ? 'purple' : $event->status->tone();
        $operator = $event->user?->name ?? 'Operatore non disponibile';
        $initials = collect(explode(' ', $operator))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
        $at = $event->created_at->timezone('Europe/Rome');
    @endphp
    <li @class(['order-timeline-event', 'event-tone-'.$tone, 'is-latest' => $isLatestPage && $loop->index === $lastOnPage]) style="--event-index: {{ $loop->index }}">
        <span class="order-timeline-dot" aria-hidden="true"><x-ui.icon :name="$rescheduled ? 'calendar2-week' : ($icons[$event->status->value] ?? 'circle')" /></span>
        <div class="order-timeline-card">
            <div class="order-timeline-head">
                <strong>{{ $event->label() }}</strong>
                @if($isLatestPage && $loop->index === $lastOnPage)<span class="order-timeline-latest">Ultimo aggiornamento</span>@endif
                <time datetime="{{ $at->toIso8601String() }}"><x-ui.icon name="clock" />{{ $at->format('d/m/Y') }} · {{ $at->format('H:i') }}</time>
            </div>
            <div class="order-timeline-operator"><span class="order-timeline-avatar" aria-hidden="true">{{ $initials ?: '?' }}</span><span><span class="visually-hidden">Data: {{ $at->format('d/m/Y H:i') }} · </span>Operatore: {{ $operator }}</span></div>
            @if($rescheduled)<div class="order-timeline-change">@if($event->schedule_change['before'])<span class="text-secondary">{{ \Illuminate\Support\Carbon::parse($event->schedule_change['before']['pickup_date'])->format('d/m/Y') }} · {{ $event->schedule_change['before']['pickup_from'] }}–{{ $event->schedule_change['before']['pickup_to'] }}</span> <x-ui.icon name="arrow-right" /> @endif<strong>{{ \Illuminate\Support\Carbon::parse($event->schedule_change['after']['pickup_date'])->format('d/m/Y') }} · {{ $event->schedule_change['after']['pickup_from'] }}–{{ $event->schedule_change['after']['pickup_to'] }}</strong></div>@endif
            @if(isset($event->schedule_change['after']['estimated_at']))<p class="order-timeline-change">Nuovo termine confermato: {{ \Illuminate\Support\Carbon::parse($event->schedule_change['after']['estimated_at'])->timezone('Europe/Rome')->format('d/m/Y H:i') }}</p>@endif
            @if($event->note && $event->note !== $event->public_note)<p class="order-timeline-note is-internal text-break"><x-ui.icon name="lock" />Nota interna: {{ $event->note }}</p>@endif
            @if($event->public_note)<p class="order-timeline-note text-break"><x-ui.icon name="chat-left-text" />{{ $event->public_note === $event->note ? 'Messaggio al cliente' : 'Pubblico' }}: {{ $event->public_note }}</p>@endif
        </div>
    </li>
@empty<li class="order-timeline-empty text-secondary">Nessun evento ancora registrato per questa spedizione.</li>@endforelse</ol>
{{ $events->links('components.ui.pagination') }}
