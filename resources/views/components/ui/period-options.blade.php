@props(['route', 'period', 'balance' => false])
@php
    $today = now('Europe/Rome');
    $preserved = collect(request()->except(['page','detail_page','period','year','month','from','to']))->reject(fn ($value, $key) => str_ends_with($key, '_page'))->all();
    $ranges = ['today'=>['Oggi',$today->copy()->startOfDay()], 'week'=>['Settimana',$today->copy()->startOfWeek()], 'month'=>['Mese',$today->copy()->startOfMonth()], 'year'=>['Anno',$today->copy()->startOfYear()]];
@endphp
<nav class="period-options" aria-label="Periodo di analisi">
    @foreach($ranges as $value => [$label,$start])
        @php
            $end = $value === 'year' ? $today->copy()->endOfYear() : $today->copy()->endOfDay();
            $annual = $period->start->month === 1 && $period->start->day === 1 && $period->end->month === 12 && $period->end->day === 31 && $period->start->year === $period->end->year;
            $active = ($value === 'year' && $annual) || ($period->start->equalTo($start) && $period->end->equalTo($end));
            $selection = $balance ? ($value === 'year' ? ['year'=>$today->year] : ['from'=>$start->toDateString(),'to'=>$end->toDateString()]) : ['period'=>$value, ...($value === 'year' ? ['year'=>$today->year] : [])];
        @endphp
        <a @class(['btn','btn-outline-secondary','btn-sm','active'=>$active]) href="{{ route($route,[...$preserved,...$selection]) }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
