@props(['id', 'title', 'description' => '', 'danger' => false])
<dialog id="{{ $id }}" {{ $attributes->class(['ea-modal', 'ea-modal-danger' => $danger]) }} aria-labelledby="{{ $id }}-title" @if($description) aria-describedby="{{ $id }}-description" @endif>
    <header class="modal-heading"><div><h2 id="{{ $id }}-title">{{ $title }}</h2>@if($description)<p id="{{ $id }}-description">{{ $description }}</p>@endif</div><x-ui.icon-button icon="x-lg" label="Chiudi finestra" data-tooltip-placement="below-end" data-dialog-close/></header>
    <div class="modal-content-area">{{ $slot }}</div>
</dialog>
