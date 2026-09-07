@props(['item', 'compact' => false])
@php($available = \Illuminate\Support\Facades\Route::has($item['route']))
@if ($available)
    <a href="{{ route($item['route']) }}" @class(['nav-item-link', 'active' => request()->routeIs($item['route']), 'compact' => $compact]) @if(request()->routeIs($item['route'])) aria-current="page" @endif><x-ui.icon :name="$item['icon']" /><span>{{ $item['label'] }}</span></a>
@else
    <span @class(['nav-item-link', 'unavailable', 'compact' => $compact]) aria-disabled="true"><x-ui.icon :name="$item['icon']" /><span>{{ $item['label'] }}</span>@unless($compact)<span class="nav-soon">Presto</span>@endunless</span>
@endif
