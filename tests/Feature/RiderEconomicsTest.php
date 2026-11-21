<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\ReceiptDeclaration;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiderEconomicsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-21 16:00', 'Europe/Rome'));
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->rider = User::factory()->create(['role' => UserRole::Rider, 'name' => 'Mario Rossi']);
    }

    private function delivered(User $rider, int $price = 500): Order
    {
        $order = Order::factory()->create(['rider_id' => $rider->id, 'status' => OrderStatus::Delivered, 'pickup_date' => '2026-11-21', 'delivered_at' => now(), 'price_cents' => $price, 'pricing_version' => 1, 'price_state' => 'agreed', 'parcel_value_cents' => 7500]);
        $order->events()->create(['status' => OrderStatus::Delivered, 'rider_id' => $rider->id, 'user_id' => $rider->id]);

        return $order;
    }

    public function test_rider_registers_only_own_expenses_through_the_dedicated_route(): void
    {
        $this->actingAs($this->rider)->post(route('rider-operations.expenses.store'), ['description' => 'Carburante', 'amount' => '20,00', 'spent_on' => '2026-11-21', 'submission_key' => (string) Str::uuid()])
            ->assertRedirect(route('rider-operations.mine'));
        $expense = Expense::sole();
        $this->assertSame([$this->rider->id, 2000], [$expense->user_id, $expense->amount_cents]);

        $blocked = fn ($response) => $this->assertContains($response->getStatusCode(), [403, 429]);
        $blocked($this->post('/expenses', ['description' => 'Altro', 'amount' => '5', 'spent_on' => '2026-11-21']));
        $blocked($this->patch('/expenses/'.$expense->slug, ['description' => 'Modifica', 'amount' => '1', 'spent_on' => '2026-11-21', 'version' => 1]));
        $blocked($this->delete('/expenses/'.$expense->slug));
        $this->assertSame(1, Expense::count());
        $this->actingAs($this->admin)->post(route('rider-operations.expenses.store'), ['description' => 'Admin', 'amount' => '5', 'spent_on' => '2026-11-21'])->assertNotFound();
    }

    public function test_receipt_confirmation_is_a_signal_and_the_admin_registration_creates_one_movement(): void
    {
        $order = $this->delivered($this->rider);
        $this->actingAs($this->rider)->post(route('rider-operations.receipts.declare', $order), ['amount' => '5,00', 'note' => 'Contanti'])->assertRedirect(route('rider-operations.mine'));
        $declaration = ReceiptDeclaration::sole();
        $this->assertSame(['pending', 500, $this->rider->id], [$declaration->state, $declaration->amount_cents, $declaration->rider_id]);
        $this->assertSame(0, PaymentEntry::count(), 'La conferma del Rider non registra incassi.');
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->post(route('rider-operations.receipts.declare', $order), ['amount' => '5,00'])->assertStatus(409);

        $this->actingAs($this->admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => $order->fresh()->version])->assertOk();
        $this->assertSame(1, PaymentEntry::where('order_id', $order->id)->count(), 'Un solo movimento: quello registrato dall’Admin.');
        $this->assertSame(['confirmed', $this->admin->id], [$declaration->fresh()->state, $declaration->fresh()->handled_by]);
        $this->actingAs($this->rider)->post(route('rider-operations.receipts.declare', $order), ['amount' => '5,00'])->assertNotFound();
    }

    public function test_rider_cannot_confirm_receipts_of_other_riders_or_register_admin_receipts(): void
    {
        $other = User::factory()->create(['role' => UserRole::Rider]);
        $foreign = $this->delivered($other);
        $this->actingAs($this->rider)->post(route('rider-operations.receipts.declare', $foreign), ['amount' => '5'])->assertNotFound();
        $mine = $this->delivered($this->rider);
        $this->post(route('rider-operations.receipts.declare', $mine), ['amount' => '0'])->assertSessionHasErrors('amount');
        $this->assertContains($this->postJson(route('payments.store', $mine), ['action' => 'receive', 'version' => 1])->getStatusCode(), [403, 429]);
        $this->assertSame(0, ReceiptDeclaration::count() + PaymentEntry::count());
        $this->actingAs($this->admin)->post(route('rider-operations.receipts.declare', $mine), ['amount' => '5'])->assertNotFound();
    }

    public function test_final_income_includes_non_cash_receipts_and_reports_the_cash_share(): void
    {
        $cashOrder = $this->delivered($this->rider, 500);
        $transferOrder = $this->delivered($this->rider, 800);
        $this->actingAs($this->admin)->postJson(route('payments.store', $cashOrder), ['action' => 'receive', 'version' => $cashOrder->fresh()->version])->assertOk();
        // Incasso registrato con un metodo diverso dai contanti (nessuna quota di cassa).
        $entry = new PaymentEntry;
        $entry->forceFill(['order_id' => $transferOrder->id, 'user_id' => $this->admin->id, 'amount_cents' => 800, 'cash_amount_cents' => 0, 'kind' => 'receipt', 'method' => 'transfer', 'note' => '', 'operation_key' => 'test-transfer'])->save();

        $this->actingAs($this->rider)->get(route('rider-operations.mine'))->assertOk()
            ->assertViewHas('balance', fn (array $balance): bool => $balance['income'] === 1300 && $balance['cash'] === 500 && $balance['available'] === 1300)
            ->assertSee('€ 13,00')->assertSee('di cui € 5,00 in contanti', false);
    }

    public function test_available_balance_is_final_income_minus_rider_expenses_without_declarations(): void
    {
        $paid = $this->delivered($this->rider, 12500);
        $declared = $this->delivered($this->rider, 700);
        $this->actingAs($this->admin)->postJson(route('payments.store', $paid), ['action' => 'receive', 'version' => $paid->fresh()->version])->assertOk();
        $this->actingAs($this->rider)->post(route('rider-operations.expenses.store'), ['description' => 'Carburante', 'amount' => '20', 'spent_on' => '2026-11-21', 'submission_key' => (string) Str::uuid()]);
        $this->post(route('rider-operations.receipts.declare', $declared), ['amount' => '7']);
        Expense::factory()->create(['user_id' => $this->admin->id, 'amount_cents' => 999, 'spent_on' => '2026-11-21']);

        $response = $this->get(route('rider-operations.mine'))->assertOk()
            ->assertViewHas('balance', fn (array $balance): bool => $balance['income'] === 12500 && $balance['expenses'] === 2000 && $balance['available'] === 10500 && $balance['declared_cents'] === 700)
            ->assertSeeInOrder(['Entrate finali', '€ 125,00', 'Spese', '€ 20,00', 'Saldo disponibile', '€ 105,00'])->assertDontSee('Netto finale disponibile')
            ->assertSee('1 conferma d’incasso in attesa dell’Admin', false)
            ->assertDontSee('Ancora da regolarizzare')->assertDontSee('Sospesi in entrata')->assertDontSee('Incassi da registrare')
            ->assertSee('data-workspace-region="rider-wallet"', false)->assertDontSee('La spesa viene registrata a tuo nome');
        $this->actingAs($this->admin)->get(route('rider-operations.show', $this->rider))->assertOk()
            ->assertViewHas('balance', fn (array $balance): bool => $balance['available'] === 10500)
            ->assertSee('Saldo disponibile')->assertDontSee('Ancora da regolarizzare')->assertDontSee('Sospesi in entrata')->assertDontSee('Registra una spesa');
    }
}
