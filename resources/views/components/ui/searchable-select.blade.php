@props(['name', 'label', 'options', 'value' => null, 'placeholder' => 'Tutti', 'id' => null])
@php($id ??= $name)
<div class="filter-field filter-account">
    <label class="form-label" for="{{ $id }}">{{ $label }}</label>
    <select data-searchable data-filter-label="{{ $label }}" name="{{ $name }}" id="{{ $id }}" {{ $attributes->class(['form-select']) }}>
        <option value="">{{ $placeholder }}</option>
        @foreach($options as $option)
            <option value="{{ $option->id }}" @selected((string)$value === (string)$option->id)>{{ $option->name }}@if($option->email) · {{ $option->email }}@endif</option>
        @endforeach
    </select>
</div>
