@props(['item', 'compact' => false])
@if(!($item['admin']??false) || auth()->user()->role===\App\UserRole::Admin)
<a href="{{ route($item['route']) }}" @class(['nav-item-link', 'active' => request()->routeIs($item['route']), 'compact' => $compact]) @if(request()->routeIs($item['route'])) aria-current="page" @endif><x-ui.icon :name="$item['icon']" /><span>{{ $item['label'] }}</span></a>

@endif
