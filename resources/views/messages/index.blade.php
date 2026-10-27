<x-app-layout title="Messaggi">
    @include('messages.workspace', ['orders' => $orders, 'order' => $order, 'messages' => $messages])
</x-app-layout>
