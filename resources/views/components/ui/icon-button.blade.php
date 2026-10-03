@props(['icon' => null, 'label', 'action' => null, 'variant' => null, 'as' => 'button', 'href' => null, 'type' => 'button', 'loading' => false, 'disabled' => false, 'text' => false])
@php
    $action ??= match ($icon) { 'chat-dots', 'chat-left-text' => 'message', 'send' => 'send', 'plus-lg' => 'add', 'pencil', 'pencil-square' => 'edit', 'trash', 'trash3' => 'delete', 'search', 'filter' => 'search', 'printer' => 'print', 'arrow-left' => 'back', default => 'neutral' };
    $icon ??= match ($action) { 'message' => 'chat-dots', 'confirm' => 'check-lg', 'send' => 'send', 'add' => 'plus-lg', 'edit' => 'pencil', 'delete' => 'trash', 'search' => 'search', 'reset' => 'arrow-counterclockwise', 'receive' => 'cash-coin', 'restore' => 'arrow-counterclockwise', 'history' => 'clock-history', 'open' => 'box-arrow-up-right', 'operate' => 'arrow-right', 'back' => 'arrow-left', 'print', 'print-multiple' => 'printer', default => 'three-dots' };
    if ($action === 'edit' && in_array($icon, ['pencil', 'pencil-square'], true)) { $text = false; }
    if ($action === 'message') { $text = false; }
    $tag = $href ? 'a' : (in_array($as, ['button', 'summary'], true) ? $as : 'button');
@endphp
<{{ $tag }} @if($tag === 'button') type="{{ $type }}" @disabled($disabled || $loading) @elseif($tag === 'a' && !$disabled && !$loading) href="{{ $href }}" @endif
    {{ $attributes->class(['icon-button', 'action-'.$action, 'action-with-text' => $text])->merge(['data-tooltip' => $label]) }} aria-label="{{ $label }}" title="{{ $label }}" @if($disabled || $loading) aria-disabled="true" @endif @if($loading) aria-busy="true" @endif>
    <span class="action-icon" aria-hidden="true"><x-ui.icon :name="$icon"/>@if($action === 'print-multiple')<span class="print-plus">+</span>@endif</span>@if($text)<span>{{ $label }}</span>@endif
</{{ $tag }}>
