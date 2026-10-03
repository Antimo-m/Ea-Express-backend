@props(['route', 'period', 'balance' => false])
@php
    $today = now('Europe/Rome');
    $preset = null;
    foreach (['today' => $today->copy()->startOfDay(), 'week' => $today->copy()->startOfWeek(), 'month' => $today->copy()->startOfMonth(), 'year' => $today->copy()->startOfYear()] as $key => $start) {
        $end = $key === 'year' ? $today->copy()->endOfYear() : $today->copy()->endOfDay();
        if ($period->start->equalTo($start) && $period->end->equalTo($end)) { $preset = $key; }
    }
    $custom = request('period') === 'custom' || (!$preset && !request('year'));
@endphp
<x-ui.period-options :route="$route" :period="$period" :balance="$balance" />
<div class="filter-range" data-filter-range>
    @unless($balance)<input type="hidden" name="period" value="{{ $custom ? 'custom' : request('period', $preset ?? 'month') }}" data-period-mode>@endunless
    @if(request('year'))<input type="hidden" name="year" value="{{ request('year') }}" data-period-year>@endif
    @if(!$balance && request('month'))<input type="hidden" name="month" value="{{ request('month') }}" data-period-year>@endif
    <button type="button" class="btn btn-outline-secondary {{ $custom ? 'active' : '' }}" data-custom-period aria-controls="filter-custom-dates" aria-expanded="{{ $custom ? 'true' : 'false' }}">Personalizzato <x-ui.icon name="calendar3" /></button>
    <div class="filter-range-dates" id="filter-custom-dates" data-custom-dates @if(!$custom) hidden @endif>
        <x-ui.field name="from" label="Dal" type="date" :value="$period->start->toDateString()" data-filter-label="Dal" />
        <x-ui.field name="to" label="Al" type="date" :value="$period->end->toDateString()" data-filter-label="Al" />
    </div>
</div>
<p class="filter-period-caption">{{ $period->start->format('d/m/Y') }} – {{ $period->end->format('d/m/Y') }}</p>
