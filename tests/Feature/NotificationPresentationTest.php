<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderActivity;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NotificationPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_notification_cards_distinguish_urgent_and_standard_shipments(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $orders = Order::factory()->count(2)->sequence(
            ['reference' => 'EA-URGENTE', 'urgency' => 'urgent'],
            ['reference' => 'EA-STANDARD', 'urgency' => 'standard'],
        )->create();
        foreach ($orders as $order) {
            foreach (['Spedizione presa in carico', 'Messaggio con dettagli e indicazioni per la consegna della spedizione', 'Rider in arrivo al punto di ritiro'] as $index => $title) {
                $admin->notify(new OrderActivity($order->id, $order->reference, $title, $index === 1));
            }
            $admin->unreadNotifications()->where('data->order_id', $order->id)->first()->markAsRead();
        }

        $response = $this->actingAs($admin)->get(route('notifications.index'));

        $response->assertOk()->assertSeeText('Urgente')->assertSeeText('Standard');
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        foreach (['urgent' => 'EA-URGENTE', 'standard' => 'EA-STANDARD'] as $urgency => $reference) {
            $card = $xpath->query('//details[contains(@class, "notification-group--'.$urgency.'")][contains(., "'.$reference.'")]');
            $this->assertSame(1, $card->length);
        }

        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/notifications-presentation.html', $response->getContent());
            $history = [];
            foreach ($orders as $order) {
                $history['/notifications/orders/'.$order->slug] = $this->getJson(route('notifications.history', $order))->assertOk()->json();
            }
            file_put_contents($directory.'/notifications-history.json', json_encode($history, JSON_THROW_ON_ERROR));
        }
    }

    #[TestWith(['standard', 'urgent'])]
    #[TestWith(['urgent', 'standard'])]
    public function test_existing_notifications_follow_the_current_order_urgency(string $originalUrgency, string $currentUrgency): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['urgency' => $originalUrgency]);
        $admin->notify(new OrderActivity($order->id, $order->reference, 'Aggiornamento precedente alla modifica'));
        $order->update(['urgency' => $currentUrgency]);

        $response = $this->actingAs($admin)->get(route('notifications.index'));

        $response->assertOk()->assertSee('notification-group--'.$currentUrgency, false)
            ->assertDontSee('notification-group--'.$originalUrgency, false)
            ->assertSee($order->reference);
    }
}
