@props(['name', 'label', 'type' => 'text', 'id' => null, 'value' => null, 'bag' => 'default', 'help' => null])
@php
    $fieldId = $id ?? $name;
    $messages = $errors->getBag($bag)->get($name);
    $describedBy = trim(($help ? $fieldId.'-help ' : '').($messages ? $fieldId.'-error' : ''));
@endphp
<div @class(['field', 'field-amount' => in_array($name, ['price', 'amount', 'parcel_value']), 'field-quantity' => $type === 'number', 'field-phone' => $type === 'tel', 'field-time' => $type === 'time', 'field-date' => in_array($type, ['date', 'time', 'datetime-local'])])>
    <label class="form-label" for="{{ $fieldId }}">{{ $label }}</label>
    <div @class(['password-field' => $type === 'password'])>
        <input {{ $attributes->class(['form-control', 'is-invalid' => count($messages) > 0]) }} id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}"
            @if ($type !== 'password') value="{{ $value }}" @endif
            @if ($messages) aria-invalid="true" @endif
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
