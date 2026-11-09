@props(['name', 'label', 'type' => 'text', 'id' => null, 'value' => null, 'bag' => 'default', 'help' => null, 'size' => null, 'width' => null])
@php
    if ($type === 'text' && in_array($name, ['price', 'amount', 'parcel_value', 'received_amount', 'ea_amount', 'carrier_cost'], true)) {
        $attributes = $attributes->merge(['inputmode' => 'decimal', 'pattern' => '[0-9]{1,6}([.,][0-9]{1,2})?']);
    }
    $fieldId = $id ?? $name;
    $messages = $errors->getBag($bag)->get($name);
    $describedBy = trim(($help ? $fieldId.'-help ' : '').($messages ? $fieldId.'-error' : ''));
@endphp
<div @class(['field', 'field-'.$width => $width !== null, 'field-small' => $size === 'small' || in_array($type, ['date', 'time', 'datetime-local', 'month']), 'field-amount' => in_array($name, ['price', 'amount', 'parcel_value']), 'field-quantity' => $type === 'number', 'field-phone' => $type === 'tel', 'field-time' => $type === 'time', 'field-date' => in_array($type, ['date', 'time', 'datetime-local'])])>
    <label class="form-label" for="{{ $fieldId }}">{{ $label }}@if($attributes->has('required') && $attributes->get('required') !== false)<span class="required-marker" data-required-marker aria-hidden="true"> *</span>@endif</label>
    <div @class(['password-field' => $type === 'password'])>
        <input {{ $attributes->class(['form-control', 'is-invalid' => count($messages) > 0]) }} id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}"
            @if ($type !== 'password') value="{{ $value }}" @endif
            @if ($messages) aria-invalid="true" data-inline-error-id="{{ $fieldId }}-error" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
        @if ($type === 'password')
            <button type="button" class="password-toggle" data-password-toggle="{{ $fieldId }}" aria-controls="{{ $fieldId }}" aria-pressed="false" aria-label="Mostra password" hidden><x-ui.icon name="eye" /></button>
        @endif
    </div>
    @if ($help)<div id="{{ $fieldId }}-help" class="form-text">{{ $help }}</div>@endif
    @if ($messages)
        <div id="{{ $fieldId }}-error" class="invalid-feedback d-block" role="alert">{{ implode(' ', $messages) }}</div>
    @endif
</div>
