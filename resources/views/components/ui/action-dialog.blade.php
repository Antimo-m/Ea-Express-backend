@props(['id', 'title', 'action' => 'edit', 'description' => '', 'open' => false, 'text' => false])
<x-ui.icon-button :action="$action" :label="$title" :data-dialog-open="$id" :aria-controls="$id" aria-haspopup="dialog" :text="$text" />
<x-ui.modal :id="$id" :title="$title" :description="$description" :data-dialog-initial="$open ? 'true' : null">
    <div class="modal-content-area">{{ $slot }}</div>
</x-ui.modal>
