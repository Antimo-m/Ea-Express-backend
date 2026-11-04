<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RecipientIncident;
use App\Models\User;
use App\OrderStatus;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundedWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_directory_counts_real_assignments_and_excludes_closed_destinations(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create(['name' => 'Rider Test 1']);
        $other = User::factory()->create(['name' => 'Rider Test 2']);
        Order::factory()->count(2)->create(['created_by' => $admin->id, 'rider_id' => $rider->id, 'status' => OrderStatus::OutForDelivery, 'delivery_city' => 'Napoli']);
        Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'delivery_city' => 'Avellino']);
        Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $rider->id, 'status' => OrderStatus::Cancelled, 'delivery_city' => 'Salerno']);
        Order::factory()->create(['created_by' => $admin->id, 'rider_id' => $other->id, 'status' => OrderStatus::Accepted, 'delivery_city' => 'Caserta']);

        $response = $this->actingAs($admin)->get(route('users.index'));

        $response->assertViewHas('users', function ($users) use ($rider): bool {
            $person = $users->firstWhere('slug', $rider->slug);

            return (int) $person->assigned_count === 4 && (int) $person->active_orders_count === 2 && (int) $person->delivered_count === 1;
        })->assertViewHas('destinations', fn ($destinations): bool => $destinations->get($rider->id)->pluck('delivery_city')->all() === ['Napoli'])
            ->assertSee('data-directory-select="'.$rider->slug.'"', false)
            ->assertSee(route('rider-operations.show', $rider), false)->assertSee(route('users.update', $rider), false)
            ->assertDontSee('Online')->assertDontSee('data-directory-select="'.$rider->id.'"', false);
        $this->actingAs($other)->get(route('users.index'))->assertForbidden();
    }

    public function test_inbox_keeps_selected_conversation_on_a_separate_sidebar_page_and_scopes_messages(): void
    {
        $this->freezeTime();
        $rider = User::factory()->create();
        $selected = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Accepted, 'store_name' => 'Conversazione selezionata']);
        $selected->messages()->create(['body' => 'Messaggio precedente']);
        foreach (range(1, 16) as $number) {
            $order = Order::factory()->create(['rider_id' => $rider->id, 'created_by' => $rider->id, 'status' => OrderStatus::Accepted]);
            $order->messages()->create(['body' => 'Conversazione '.$number]);
        }
        $private = Order::factory()->create(['store_name' => 'Conversazione riservata ad altro Rider']);
        $private->messages()->create(['body' => 'Riservato']);

        $response = $this->actingAs($rider)->get(route('messages.show', $selected));

        $response->assertSee('data-live-chat="'.$selected->slug.'"', false)
            ->assertSee('data-conversation-link="'.$selected->slug.'"', false)->assertSee('Messaggio precedente')
            ->assertDontSee($private->store_name)->assertDontSee('Riservato')
            ->assertViewHas('orders', fn ($orders): bool => $orders->total() === 17 && $orders->count() === 15);
        $this->get(route('messages.show', ['order' => $selected, 'conversations_page' => 2]))
            ->assertViewHas('orders', fn ($orders): bool => $orders->count() === 2)
            ->assertViewHas('messages', fn ($messages): bool => $messages->currentPage() === 1);
        $this->getJson(route('messages.show', $selected))->assertJsonPath('data.0.body', 'Messaggio precedente')
            ->assertJsonMissingPath('data.0.id')->assertJsonPath('meta.current_page', 1);
        $this->getJson(route('messages.show', $private))->assertNotFound();
    }

    public function test_inbox_renders_empty_and_first_selected_states_without_marking_messages_read(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get(route('messages.index'))->assertSee('Il prossimo contatto parte da qui');
        $order = Order::factory()->create();
        $message = $order->messages()->create(['body' => '<script>alert("message")</script>']);

        $response = $this->get(route('messages.index'));

        $response->assertSee('data-messages-url="'.route('messages.show', $order).'"', false)
            ->assertSee($message->body)->assertDontSee($message->body, false)->assertSee('1 nuovo messaggio da leggere')
            ->assertSee(route('messages.store', $order), false);
        $this->assertNull($message->fresh()->read_at);
    }

    public function test_directories_and_inbox_render_realistic_paginated_data(): void
    {
        $this->travelTo(now('Europe/Rome')->setDate(2026, 10, 22)->setTime(12, 0));
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Amministratore Test']);
        $names = ['Rider Test 1', 'Rider Test 2', 'Rider Test 3', 'Rider Test 4', 'Rider Test 5', 'Rider Test 6', 'Rider Test 7'];
        $first = null;
        foreach ($names as $index => $name) {
            $rider = User::factory()->create(['name' => $name, 'email' => 'rider-'.$index.'@example.test', 'is_active' => $index !== 5]);
            Order::factory()->count(2)->create(['rider_id' => $rider->id, 'created_by' => $admin->id, 'delivery_city' => $index % 2 ? 'Caserta' : 'Napoli', 'status' => OrderStatus::OutForDelivery]);
            Order::factory()->create(['rider_id' => $rider->id, 'created_by' => $admin->id, 'status' => OrderStatus::Delivered]);
            $first ??= $rider;
        }
        foreach (range(1, 16) as $index) {
            $order = Order::factory()->create(['rider_id' => $first->id, 'created_by' => $admin->id, 'store_name' => $index === 16 ? 'Azienda Test Officina Dimostrativa' : 'Cliente distribuzione '.$index, 'recipient_name' => 'Destinatario '.$index, 'recipient_phone' => '+390000000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'status' => OrderStatus::OutForDelivery, 'conversation_token' => $index === 16 ? str_repeat('a', 64) : null, 'conversation_expires_at' => $index === 16 ? now()->addDays(30) : null]);
            $order->messages()->create(['body' => $index === 16 ? 'Buongiorno, la consegna è confermata per questo pomeriggio?' : 'Potete confermare la fascia di consegna?']);
            if ($index === 16) {
                $reply = $order->messages()->make(['body' => 'Buongiorno! La spedizione è in consegna. Ti aggiorniamo qui.']);
                $reply->user_id = $admin->id;
                $reply->save();
                $order->messages()->create(['body' => 'Perfetto, grazie. Il citofono è Azienda Test.']);
                $selected = $order;
            }
            if ($index <= 2) {
                app(RecipientRisk::class)->record($order, $admin);
            }
        }
        $this->actingAs($admin);
        $riders = $this->get(route('users.index'))->assertSee('Rider Test 1')->assertSee('Rider Test 7');
        $people = $this->get(route('recipient-incidents.index'))->assertSee('Destinatario 1')->assertSee('Destinatario 2');
        $inbox = $this->get(route('messages.index'))->assertSee('Azienda Test Officina Dimostrativa');
        $thread = $this->get(route('messages.show', $selected))->assertSee('Il citofono è Azienda Test.')
            ->assertDontSee('Accesso tramite link')
            ->assertDontSee(route('conversation.show', $selected->conversation_token), false)
            ->assertSee(route('messages.store', $selected), false);
        $profile = RecipientIncident::first()->profile;
        $this->get(route('recipient-incidents.show', $profile))->assertSee('Storico rettifiche');
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            foreach (['rounded-riders' => $riders, 'rounded-recipients' => $people, 'rounded-inbox' => $inbox, 'rounded-thread' => $thread] as $name => $response) {
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
            file_put_contents($directory.'/rounded-chat.json', $this->getJson(route('messages.show', $selected))->getContent());
        }
    }
}
