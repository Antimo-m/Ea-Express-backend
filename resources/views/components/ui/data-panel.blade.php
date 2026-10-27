@props(['title', 'description' => null, 'count' => null])
<section {{ $attributes->class(['data-panel']) }}>
    <header class="data-panel-heading"><div><h2>{{ $title }} @if($count !== null)<span class="count-badge">{{ $count }}</span>@endif</h2>@if($description)<p>{{ $description }}</p>@endif</div>@isset($actions)<div class="data-panel-actions">{{ $actions }}</div>@endisset</header>
    {{ $slot }}
    @isset($footer)<footer class="data-panel-footer">{{ $footer }}</footer>@endisset
</section>
