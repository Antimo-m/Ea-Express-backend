<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class OrderActivity extends Notification
{
    public function __construct(public int $orderId, public string $reference, public string $title, public bool $message = false) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['order_id' => $this->orderId, 'reference' => $this->reference, 'title' => $this->title, 'message' => $this->message];
    }
}
