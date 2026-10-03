@props(['name', 'label', 'options', 'value' => null, 'default' => '', 'size' => 'sm'])
<div class="filter-control filter-{{ $size }}">
    <label class="form-label" for="filter-{{ $name }}">{{ $label }}</label>
    <select id="filter-{{ $name }}" name="{{ $name }}" class="form-select" data-filter-label="{{ $label }}" data-filter-default="{{ $default }}">
        @foreach($options as $key => $text)<option value="{{ $key }}" @selected((string)($value ?? request($name, $default)) === (string)$key)>{{ $text }}</option>@endforeach
    </select>
</div>
