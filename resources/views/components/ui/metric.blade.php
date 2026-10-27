@props(['label', 'value', 'note' => null, 'icon' => null, 'tone' => 'neutral'])
<article {{ $attributes->class(['metric-tile', 'metric-tile--'.$tone]) }}>
    <header><span>{{ $label }}</span>@if($icon)<x-ui.icon :name="$icon" />@endif</header>
    <strong class="metric-tile-value">{{ $value }}</strong>
    @if($note)<p>{{ $note }}</p>@endif
    @isset($footer)<footer>{{ $footer }}</footer>@endisset
</article>
