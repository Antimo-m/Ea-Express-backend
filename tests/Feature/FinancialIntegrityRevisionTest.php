<?php

namespace Tests\Feature;

use App\Models\AccountingControl;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FinancialIntegrityRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reversal_leaves_money_and_refund_obligation_until_an_explicit_payment_then_recollection_counts_once(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $this->actingAs($admin);
        $path = route('payments.store', $order);
        $this->postJson($path, ['action' => 'receive', 'version' => 1])->assertOk();
        $original = PaymentEntry::sole()->getAttributes();

        $this->postJson($path, ['action' => 'reverse', 'version' => 2, 'note' => 'Rettifica economica senza restituire denaro'])->assertOk();

        $this->assertSame(0, (int) $order->payments()->sum('amount_cents'));
        $this->assertSame(2000, (int) $order->payments()->sum('cash_amount_cents'));
        $this->assertDatabaseCount('payment_entries', 2);
        $this->get('/balance?ledger=history')->assertViewHas('registerEntries', fn ($entries): bool => $entries->isEmpty());
        $this->get('/balance')->assertViewHas('cashBalance', 2000)->assertViewHas('refundsDue', 2000)->assertViewHas('operatingNet', 0);
        $this->postJson($path, ['action' => 'restore', 'version' => 3, 'note' => 'Non deve creare un incasso'])->assertConflict();
        $this->postJson($path, ['action' => 'refund', 'version' => 3, 'note' => 'Contante restituito, ricevuta cliente verificata'])->assertOk();
        $this->postJson($path, ['action' => 'refund', 'version' => 3, 'note' => 'Stesso invio'])->assertUnprocessable();
        $this->get('/balance')->assertViewHas('cashBalance', 0)->assertViewHas('refundsDue', 0)->assertViewHas('cashIn', 2000)->assertViewHas('cashOut', 2000);
        $this->postJson($path, ['action' => 'restore', 'version' => 4, 'note' => 'Ordine ripristinato senza pagamento'])->assertOk();
        $this->assertSame(0, PaymentEntry::where('kind', 'restoration')->sole()->amount_cents);
        $this->postJson($path, ['action' => 'receive', 'version' => 5])->assertOk();
        $this->postJson($path, ['action' => 'receive', 'version' => 5])->assertUnprocessable();
        $this->get('/balance')->assertViewHas('cashBalance', 2000)->assertViewHas('operatingNet', 2000)->assertViewHas('cashIn', 4000)->assertViewHas('cashOut', 2000);
        $newReceipt = $order->payments()->latest('id')->first();
        $this->assertSame(2000, $newReceipt->amount_cents);
        $this->assertSame(2000, $newReceipt->cash_amount_cents);
        $this->assertNotNull($newReceipt->receipt_reversal_id);
        $this->assertSame(PaymentEntry::where('kind', 'refund')->sole()->id, $newReceipt->original_entry_id);
        $this->assertSame($original, PaymentEntry::oldest('id')->first()->getAttributes());
        $this->assertDatabaseCount('payment_entries', 5);
    }

    public function test_cash_only_recollection_preserves_demonstrable_previously_recognized_revenue(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $this->actingAs($admin);
        foreach (['receive', 'reverse', 'refund', 'restore'] as $index => $action) {
            $this->postJson(route('payments.store', $order), ['action' => $action, 'version' => $index + 1, 'note' => 'Operazione documentata'])->assertOk();
        }
        $historical = new PaymentEntry;
        $historical->forceFill(['order_id' => $order->id, 'user_id' => $admin->id, 'kind' => 'restoration', 'amount_cents' => 2000, 'ea_amount_cents' => 2000, 'cash_amount_cents' => 0, 'method' => 'cash', 'note' => 'Registrazione storica da verificare'])->save();

        $this->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 5])->assertOk();

        $receipt = PaymentEntry::where('kind', 'recollection')->sole();
        $this->assertSame(0, $receipt->amount_cents);
        $this->assertSame(2000, $receipt->cash_amount_cents);
        $this->assertSame(2000, (int) $order->payments()->sum('amount_cents'));
        $this->get('/balance')->assertSee('Importo ricevuto')->assertSee('Ricavo già contabilizzato in un movimento precedente.');
    }

    #[TestWith(['incoming', 2000, 0, 2000])]
    #[TestWith(['outgoing', 0, 2000, -2000])]
    public function test_october_position_is_open_in_october_and_payment_affects_only_november(string $direction, int $incoming, int $outgoing, int $cash): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $account = PendingAccount::factory()->create(['customer_id' => $customer->id, 'created_by' => $admin->id, 'direction' => $direction, 'amount_cents' => 2000, 'occurred_on' => '2026-10-01']);
        $this->actingAs($admin);
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $query = '&customer_ids[]='.$customer->slug;
        $this->get('/balance?month=2026-11&ledger=pending'.$query)->assertViewHas('pendingIncoming', $incoming)->assertViewHas('pendingOutgoing', $outgoing)->assertSee('Preesistente');

        $this->postJson(route('pending.settle', $account), ['amount' => '20', 'version' => 1, 'submission_key' => (string) Str::uuid()])->assertOk();

        $this->get('/balance?month=2026-10&ledger=pending'.$query)->assertViewHas('pendingIncoming', $incoming)->assertViewHas('pendingOutgoing', $outgoing)->assertViewHas('cashBalance', 0)->assertViewHas('pendingUncertain', 0)
            ->assertViewHas('pendingEntries', fn ($entries): bool => $entries->sole()->state === 'open' && $entries->sole()->settled_cents === 0);
        $this->get('/balance?month=2026-11'.$query)->assertViewHas('pendingIncoming', 0)->assertViewHas('pendingOutgoing', 0)->assertViewHas('cashBalance', $cash);
        $this->get('/balance?month=2026-11&ledger=pending&pending_state=paid'.$query)->assertViewHas('pendingEntries', fn ($entries): bool => $entries->sole()->state === 'paid');
    }

    public function test_historical_partial_amount_uses_audit_before_later_correction_and_final_payment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = PendingAccount::factory()->create(['direction' => 'incoming', 'amount_cents' => 2000, 'occurred_on' => '2026-10-01']);
        $this->actingAs($admin)->postJson(route('pending.settle', $account), ['amount' => '5', 'version' => 1, 'submission_key' => (string) Str::uuid()])->assertOk();
        $settlement = $account->settlements()->sole();
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $this->patchJson(route('pending.settlements.update', [$account, $settlement]), ['amount' => '8', 'version' => 2, 'reason' => 'Ricevuta verificata'])->assertOk();

        $this->get('/balance?month=2026-10&ledger=pending&pending_state=partially_paid')->assertViewHas('pendingIncoming', 1500)->assertViewHas('pendingUncertain', 0)
            ->assertViewHas('pendingEntries', fn ($entries): bool => $entries->sole()->settled_cents === 500);
        $this->get('/balance?month=2026-11')->assertViewHas('pendingIncoming', 1200);
        $this->postJson(route('pending.settle', $account), ['amount' => '13', 'version' => 3, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(800, $account->fresh()->settled_cents);
    }

    public function test_incomplete_historical_positions_are_flagged_instead_of_claiming_an_exact_balance(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 12:00', 'Europe/Rome'));
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = PendingAccount::factory()->create(['direction' => 'outgoing', 'amount_cents' => 2000, 'occurred_on' => '2026-10-01']);
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Europe/Rome'));
        $account->forceFill(['state' => 'paid', 'settled_cents' => 2000])->save();

        $this->actingAs($admin)->get('/balance?month=2026-10&ledger=pending&pending_state=all')->assertViewHas('pendingUncertain', 1)->assertSee('Storico da verificare')->assertSee('Da verificare');
    }

    public function test_categories_have_one_paginated_register_and_never_mix_refunds_corrections_or_manual_cash_with_receipts(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => str_repeat('Nome molto lungo ', 6)]);
        $this->actingAs($admin);
        $orders = Order::factory()->count(12)->create(['customer_id' => $customer->id, 'store_name' => $customer->name, 'status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        foreach ($orders as $order) {
            $this->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        }
        $order = $orders->first();
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Rettifica documentata'])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => 3, 'note' => 'Ricevuta rimborso verificata'])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'restore', 'version' => 4, 'note' => 'Ripristino senza nuovi soldi'])->assertOk();
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'amount_cents' => 1500]);
        foreach (['payments' => ['receipt'], 'refunds' => ['refund'], 'history' => ['reversal', 'restoration'], 'cash' => ['cash']] as $category => $kinds) {
            $page = $this->get('/balance?ledger='.$category)->assertOk();
            $this->assertTrue($page->viewData('registerEntries')->getCollection()->every(fn ($entry): bool => in_array($entry['model']->kind, $kinds)));
            if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
                file_put_contents($directory.'/financial-'.$category.'.html', $page->getContent());
            }
        }
        $this->get('/balance?ledger=payments&customer_ids[]='.$customer->slug.'&movements_page=2')->assertViewHas('registerEntries', fn ($entries): bool => $entries->count() === 2 && $entries->currentPage() === 2)->assertSee('customer_ids%5B0%5D='.$customer->slug, false);
        $this->get('/balance?ledger=reversed')->assertViewHas('reversalEntries', fn ($entries): bool => $entries->isEmpty());
        $this->getJson('/balance?customer_ids[]='.$customer->id)->assertUnprocessable();
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/financial-payments-page2.html', $this->get('/balance?ledger=payments&movements_page=2')->getContent());
            $active = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
            $this->postJson(route('payments.store', $active), ['action' => 'receive', 'version' => 1])->assertOk();
            $this->postJson(route('payments.store', $active), ['action' => 'reverse', 'version' => 2, 'note' => 'Storno attivo con rimborso ancora da pagare'])->assertOk();
            PendingAccount::factory()->create(['customer_id' => $customer->id, 'subject' => 'Credito precedente con descrizione estesa', 'direction' => 'incoming', 'amount_cents' => 2000, 'occurred_on' => now('Europe/Rome')->toDateString()]);
            $this->postJson(route('expenses.store'), ['description' => 'Carburante e materiali consegne', 'amount' => '12.50', 'spent_on' => now('Europe/Rome')->toDateString(), 'submission_key' => (string) Str::uuid()])->assertCreated();
            foreach (['expenses', 'pending', 'reversed'] as $category) {
                file_put_contents($directory.'/financial-'.$category.'.html', $this->get('/balance?ledger='.$category)->getContent());
            }
            file_put_contents($directory.'/financial-labels-empty.html', $this->get('/orders/in-progress')->getContent());
        }

    }

    #[TestWith(['refund_paid', 0, 0])]
    #[TestWith(['refund_unpaid', 2000, 2000])]
    public function test_historical_automatic_refund_requires_documented_reconciliation_without_rewriting_original(string $resolution, int $cash, int $due): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $this->actingAs($admin);
        foreach (['receive', 'reverse'] as $index => $action) {
            $this->postJson(route('payments.store', $order), ['action' => $action, 'version' => $index + 1, 'note' => 'Rettifica storica'])->assertOk();
        }
        $refund = new PaymentEntry;
        $refund->forceFill(['order_id' => $order->id, 'user_id' => $admin->id, 'kind' => 'refund', 'amount_cents' => 0, 'ea_amount_cents' => 0, 'cash_amount_cents' => -2000, 'receipt_reversal_id' => $order->receiptReversals()->sole()->id, 'method' => 'cash', 'note' => 'Registrazione storica da verificare'])->save();
        $original = $refund->fresh()->getAttributes();
        $this->get('/balance?ledger=refunds')->assertViewHas('uncertainRefunds', 1)->assertViewHas('registerEntries', fn ($entries): bool => $entries->isEmpty());
        $this->get('/balance?ledger=history')->assertSee('Riconcilia rimborso storico');
        $this->postJson(route('payments.store', $order), ['action' => 'restore', 'version' => 3, 'note' => 'Prima serve la verifica del rimborso storico'])->assertConflict();
        $this->postJson(route('payments.store', $order), ['action' => 'reconcile', 'resolution' => $resolution, 'entry_slug' => (string) $refund->id, 'version' => 3, 'confirmed' => true, 'note' => 'Identificatore numerico vietato'])->assertUnprocessable()->assertJsonValidationErrors('entry_slug');
        $otherOrder = Order::factory()->create();
        $this->postJson(route('payments.store', $otherOrder), ['action' => 'reconcile', 'resolution' => $resolution, 'entry_slug' => $refund->slug, 'version' => 1, 'confirmed' => true, 'note' => 'Movimento di altro ordine'])->assertNotFound();

        $this->postJson(route('payments.store', $order), ['action' => 'reconcile', 'resolution' => $resolution, 'entry_slug' => $refund->slug, 'version' => 3, 'confirmed' => true, 'note' => 'Documentazione verificata con cliente e registro cassa'])->assertOk();

        $this->assertSame($original, $refund->fresh()->getAttributes());
        $this->get('/balance')->assertViewHas('cashBalance', $cash)->assertViewHas('refundsDue', $due)->assertViewHas('uncertainRefunds', 0);
        $this->postJson(route('payments.store', $order), ['action' => 'reconcile', 'resolution' => $resolution, 'entry_slug' => $refund->slug, 'version' => 4, 'confirmed' => true, 'note' => 'Ripetizione'])->assertConflict();
        if ($resolution === 'refund_unpaid') {
            $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => 4, 'note' => 'Rimborso ora effettivamente pagato'])->assertOk();
            $this->get('/balance')->assertViewHas('cashBalance', 0)->assertViewHas('cashIn', 2000)->assertViewHas('cashOut', 2000)->assertViewHas('refundTotal', 2000);
        }
    }

    public function test_closed_period_rejects_new_refunds_and_customer_or_rider_cannot_reconcile_money(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'price_cents' => 2000]);
        $this->actingAs($admin);
        foreach (['receive', 'reverse'] as $index => $action) {
            $this->postJson(route('payments.store', $order), ['action' => $action, 'version' => $index + 1, 'note' => 'Operazione verificata'])->assertOk();
        }
        AccountingControl::first()->forceFill(['closed_through' => now('Europe/Rome')->toDateString()])->save();

        $this->postJson(route('payments.store', $order), ['action' => 'refund', 'version' => 3, 'note' => 'Periodo chiuso'])->assertConflict();
        $this->assertDatabaseCount('payment_entries', 2);
        foreach ([UserRole::Customer, UserRole::Rider] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson(route('payments.store', $order), ['action' => 'reconcile', 'resolution' => 'refund_paid', 'version' => 3, 'confirmed' => true, 'note' => 'Vietato'])->assertForbidden();
        }
    }
}
