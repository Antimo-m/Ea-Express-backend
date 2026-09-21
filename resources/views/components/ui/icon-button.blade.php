@props(['icon', 'label', 'variant' => 'ghost'])
<button type="button" {{ $attributes->class(['icon-button', 'action-'.$variant]) }} aria-label="{{ $label }}" data-tooltip="{{ $label }}"><x-ui.icon :name="$icon"/></button>
