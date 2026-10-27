@props(['item', 'compact' => false])
@php($active = request()->routeIs($item['active'] ?? $item['route']))
@if(!($item['admin']??false) || auth()->user()->role===\App\UserRole::Admin)
<a href="{{ route($item['route']) }}" @class(['nav-item-link', 'active' => $active, 'compact' => $compact]) @if($active) aria-current="page" @endif><x-ui.icon :name="$item['icon']" /><span>{{ $item['label'] }}</span></a>

@endif
