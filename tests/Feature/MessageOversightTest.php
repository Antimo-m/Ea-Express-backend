<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MessageOversightTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rider;

    private User $otherRider;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-22 10:00', 'Europe/Rome'));
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Anna Admin']);
        $this->rider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Mario Rossi']);
        $this->otherRider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Luca Bianchi']);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->order = Order::factory()->create(['customer_id' => $customer->id, 'rider_id' => $this->rider->id, 'status' => OrderStatus::Accepted, 'store_name' => 'Boutique Aurora']);
        $this->message($this->order, null, 'Il pacco è pronto?', '2026-11-22 09:00');
        $this->message($this->order, $this->rider, 'Arrivo tra dieci minuti', '2026-11-22 09:20');
        $this->message($this->order, $this->admin, 'Confermo il ritiro', '2026-11-22 09:25');
        $this->message($this->order, null, 'Perfetto, vi aspetto', '2026-11-22 09:40');
    }

    private function message(Order $order, ?User $author, string $body, string $at): OrderMessage
    {
        $message = OrderMessage::factory()->for($order)->create(['body' => $body]);
        $message->forceFill(['user_id' => $author?->id, 'created_at' => Carbon::parse($at, 'Europe/Rome')->utc()])->save();

        return $message;
    }

    public function test_admin_sees_who_wrote_and_the_conversation_summary(): void
    {
        $this->actingAs($this->admin)->get(route('messages.show', $this->order))->assertOk()
            ->assertSeeInOrder(['Rider assegnato', 'Mario Rossi'])
            ->assertSee('Mario Rossi<span class="message-author-role">Rider assegnato</span>', false)
            ->assertSee('Anna Admin<span class="message-author-role">Admin</span>', false)
            ->assertSeeInOrder(['<strong>1</strong> dal rider', '<strong>2</strong> dal cliente', '<strong>1</strong> dall’admin'], false)
            ->assertSee('Risposta media <strong>20 min</strong>', false)
            ->assertSee('Ultimo del rider <strong>22/11 09:20</strong>', false)
            ->assertSee('Cliente in attesa da')
            ->assertSee('inbox-rider-chip', false);
    }

    public function test_admin_json_includes_authors_for_live_updates(): void
    {
        $this->actingAs($this->admin)->getJson(route('messages.show', $this->order))->assertOk()
            ->assertJsonPath('data.0.author', null)
            ->assertJsonPath('data.1.author', ['name' => 'Anna Admin', 'role' => 'Admin', 'assigned' => false])
            ->assertJsonPath('data.2.author', ['name' => 'Mario Rossi', 'role' => 'Rider', 'assigned' => true]);
    }

    public function test_riders_keep_the_neutral_view_without_names_or_summary(): void
    {
        $this->actingAs($this->rider)->get(route('messages.show', $this->order))->assertOk()
            ->assertDontSee('inbox-oversight', false)->assertDontSee('message-author-role', false)->assertDontSee('inbox-rider-filter', false)->assertSee('EA-Express');
        $this->actingAs($this->rider)->getJson(route('messages.show', $this->order))->assertOk()->assertJsonMissingPath('data.0.author');
    }

    public function test_admin_filters_conversations_by_rider(): void
    {
        $other = Order::factory()->create(['rider_id' => $this->otherRider->id, 'status' => OrderStatus::Accepted, 'store_name' => 'Ferramenta Sole']);
        $this->message($other, null, 'Domanda per Luca', '2026-11-22 09:50');
        $unassigned = Order::factory()->create(['rider_id' => null, 'status' => OrderStatus::Received, 'store_name' => 'Fiori Lina']);
        $this->message($unassigned, null, 'Senza rider', '2026-11-22 09:55');

        $this->actingAs($this->admin)->get(route('messages.index', ['rider' => $this->otherRider->slug]))->assertOk()
            ->assertSee('Ferramenta Sole')->assertDontSee('Boutique Aurora')->assertDontSee('Fiori Lina')
            ->assertSee('<option value="'.$this->otherRider->slug.'" selected>', false);
        $this->actingAs($this->admin)->get(route('messages.index', ['rider' => 'none']))->assertOk()
            ->assertSee('Fiori Lina')->assertDontSee('Ferramenta Sole')->assertSee('Senza rider');
        $this->actingAs($this->admin)->get(route('messages.index'))->assertOk()
            ->assertSee('Ferramenta Sole')->assertSee('Boutique Aurora')->assertSee('Fiori Lina');
        $this->actingAs($this->rider)->get(route('messages.index', ['rider' => $this->otherRider->slug]))->assertOk()
            ->assertSee('Boutique Aurora')->assertDontSee('Ferramenta Sole');
    }
}
