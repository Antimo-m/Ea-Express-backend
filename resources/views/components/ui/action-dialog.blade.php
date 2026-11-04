@props(['id', 'title', 'action' => 'edit', 'icon' => null, 'description' => '', 'open' => false, 'text' => false])
<x-ui.icon-button :action="$action" :icon="$icon" :label="$title" :data-dialog-open="$id" :aria-controls="$id" aria-haspopup="dialog" :text="$text" />
<x-ui.modal :id="$id" :title="$title" :description="$description" :data-dialog-initial="$open ? 'true' : null">
    {{ $slot }}
</x-ui.modal>
