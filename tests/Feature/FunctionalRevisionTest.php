<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FunctionalRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_defaults_to_ten_open_positions_and_archives_ninety_paid_without_changing_balances(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        PendingAccount::factory()->count(90)->create(['created_by' => $admin->id, 'state' => 'paid', 'settled_cents' => 1000]);
        PendingAccount::factory()->count(6)->create(['created_by' => $admin->id]);
        PendingAccount::factory()->count(4)->create(['created_by' => $admin->id, 'state' => 'partially_paid', 'settled_cents' => 300]);

        $this->actingAs($admin)->get('/pending')->assertViewHas('accounts', fn ($accounts): bool => $accounts->total() === 10 && $accounts->count() === 10)
            ->assertViewHas('totals', fn ($totals): bool => (int) $totals['incoming'] === 8800)->assertSee('Archivio Sospesi');
        $this->get('/pending?state=paid')->assertViewHas('accounts', fn ($accounts): bool => $accounts->total() === 90 && $accounts->count() === 20)->assertSee('<h1>Archivio Sospesi</h1>', false);
        $this->get('/pending?state=paid&page=2')->assertViewHas('accounts', fn ($accounts): bool => $accounts->currentPage() === 2 && $accounts->total() === 90)->assertSee('state=paid', false);
        $this->get('/pending?state=partially_paid')->assertViewHas('accounts', fn ($accounts): bool => $accounts->total() === 4);
        $this->get('/pending?state=all')->assertViewHas('accounts', fn ($accounts): bool => $accounts->total() === 100);
        $this->assertSame(91200, (int) PendingAccount::sum('settled_cents'));
        $this->assertDatabaseCount('pending_accounts', 100);
    }

    public function test_paid_positions_preserve_payments_and_reopen_after_an_authorized_accounting_correction(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $account = PendingAccount::factory()->create(['created_by' => $admin->id]);
        $this->actingAs($admin)->postJson(route('pending.settle', $account), ['amount' => '10', 'version' => 1, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('paid', $account->fresh()->state);
        $this->get('/pending')->assertViewHas('accounts', fn ($accounts): bool => $accounts->total() === 0);
        $this->get('/pending?state=paid')->assertSee('10,00')->assertSee('Storico pagamenti');
        $this->patchJson(route('pending.update', $account), ['action' => 'update', 'version' => 2, 'subject' => 'Riscrittura impropria', 'description' => 'Concluso'])->assertConflict();
        $payment = $account->settlements()->sole();

        $this->patchJson(route('pending.settlements.update', [$account, $payment]), ['amount' => '4', 'reason' => 'Correzione verificata sulla ricevuta', 'version' => 2])->assertOk();

        $this->assertSame('partially_paid', $account->fresh()->state);
        $this->assertSame(400, $account->fresh()->settled_cents);
        $this->assertDatabaseCount('pending_settlements', 1);
        $this->get('/pending')->assertViewHas('accounts', fn ($accounts): bool => $accounts->total() === 1 && $accounts->first()->slug === $account->slug);
        $this->get('/pending?state=paid')->assertViewHas('accounts', fn ($accounts): bool => $accounts->total() === 0);
        $this->actingAs(User::factory()->create())->patchJson(route('pending.settlements.update', [$account, $payment]), ['amount' => '5', 'reason' => 'Non autorizzato', 'version' => 3])->assertForbidden();
        $this->get('/pending')->assertForbidden();
    }

    public function test_customer_pending_filters_never_expose_other_accounts_or_accept_numeric_detail_references(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $open = PendingAccount::factory()->create(['customer_id' => $customer->id]);
        $partial = PendingAccount::factory()->create(['customer_id' => $customer->id, 'state' => 'partially_paid', 'settled_cents' => 200]);
        $paid = PendingAccount::factory()->create(['customer_id' => $customer->id, 'state' => 'paid', 'settled_cents' => 1000]);
        $other = PendingAccount::factory()->create(['customer_id' => User::factory()->create(['role' => UserRole::Customer])->id]);
        $this->actingAs($customer, 'customer')->getJson('/api/v1/customer/pending')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/customer/pending?view=partial')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $partial->slug);
        $this->getJson('/api/v1/customer/pending?view=settled')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $paid->slug);
        $this->getJson('/api/v1/customer/pending?view=all')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/customer/pending/'.$open->slug)->assertOk();
        $this->getJson('/api/v1/customer/pending/'.$open->id)->assertNotFound();
        $this->getJson('/api/v1/customer/pending/'.$other->slug)->assertNotFound();
    }

    public function test_custom_reports_apply_inclusive_rome_days_to_every_total_and_chart(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $attributes = ['created_by' => $admin->id, 'customer_id' => $customer->id, 'rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'price_cents' => 1500];
        Order::factory()->create([...$attributes, 'created_at' => '2026-10-24 21:59:59', 'delivered_at' => '2026-10-24 21:59:59']);
        $included = Order::factory()->create([...$attributes, 'created_at' => '2026-10-24 22:00:00', 'delivered_at' => '2026-10-24 22:00:00']);
        Order::factory()->create([...$attributes, 'created_at' => '2026-10-25 22:59:59', 'delivered_at' => '2026-10-25 22:59:59']);
        Order::factory()->create([...$attributes, 'created_at' => '2026-10-25 23:00:00', 'delivered_at' => '2026-10-25 23:00:00']);
        $payment = new PaymentEntry;
        $payment->forceFill(['order_id' => $included->id, 'user_id' => $admin->id, 'amount_cents' => 1500, 'cash_amount_cents' => 1500, 'note' => 'Incasso verificato', 'ea_amount_cents' => 1500, 'created_at' => '2026-10-25 22:59:59'])->save();
        Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => 400, 'spent_on' => '2026-10-25']);
        Expense::factory()->create(['user_id' => $admin->id, 'amount_cents' => 999, 'spent_on' => '2026-10-26']);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'income', 'amount_cents' => 700, 'occurred_on' => '2026-10-25']);

        $response = $this->actingAs($admin)->get('/reports?from=2026-10-25&to=2026-10-25');

        $response->assertViewHas('received', 2)->assertViewHas('delivered', 2)->assertViewHas('earned', 3000)->assertViewHas('cash', 1500)->assertViewHas('spent', 400)->assertViewHas('manualNet', 700)->assertViewHas('operatingNet', 1800)
            ->assertViewHas('trend', [['label' => '25/10', 'orders' => 2, 'incoming' => 2200, 'outgoing' => 400]])->assertSee('Periodo personalizzato');
        $period = $response->viewData('period');
        $this->assertSame('2026-10-24 22:00:00', $period->utcRange()[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 22:59:59', $period->utcRange()[1]->format('Y-m-d H:i:s'));
        $this->getJson('/reports?from=2026-11-10&to=2026-11-09')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/reports?from=2026-11-10')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->get('/reports?month=2026-10')->assertViewHas('customPeriod', false);
        $this->getJson('/reports?from=2025-01-01&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_custom_report_does_not_merge_the_same_day_in_different_years(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['created_at' => '2025-11-01 12:00:00']);
        Order::factory()->create(['created_at' => '2026-11-01 12:00:00']);

        $response = $this->actingAs($admin)->get('/reports?from=2025-11-01&to=2026-11-01');

        $response->assertViewHas('received', 2);
        $points = $response->viewData('trend');
        $this->assertSame(1, $points[0]['orders']);
        $this->assertSame(1, $points[array_key_last($points)]['orders']);
        $this->assertSame(2, array_sum(array_column($points, 'orders')));
    }

    public function test_mobile_finance_and_print_labels_render_real_records_with_stable_references(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['rider_id' => $admin->id, 'status' => OrderStatus::Delivered, 'price_cents' => 1235, 'delivered_at' => now()]);
        FinancialMovement::factory()->create(['user_id' => $admin->id, 'kind' => 'cash', 'description' => 'Movimento con descrizione estesa per verificare la leggibilità su telefono', 'amount_cents' => 15235]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        $cash = $this->get('/balance?ledger=cash')->assertSee('money-register')->assertSee('money-row-detail');
        $payments = $this->get('/balance')->assertSee($order->reference)->assertSee('data-cash-movement=', false);
        $orders = Order::factory()->count(3)->create(['rider_id' => $admin->id, 'store_name' => 'Mittente con nome commerciale esteso', 'recipient_name' => 'Destinatario con nome e cognome estesi', 'pickup_address' => 'Via dei Quartieri e delle Attività Commerciali', 'delivery_address' => 'Corso delle Spedizioni e del Centro Storico', 'price_cents' => 500]);
        $labels = $this->get('/labels?'.http_build_query(['ids' => $orders->pluck('slug')->all()]))->assertOk()->assertSee('label-total');
        $this->assertSame(3, substr_count($labels->getContent(), '<article class="label">'));
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/revision-finance-cash.html', $cash->getContent());
            file_put_contents($directory.'/revision-finance-payments.html', $payments->getContent());
            file_put_contents($directory.'/revision-labels.html', $labels->getContent());
        }
    }

    public function test_desktop_and_mobile_receipt_actions_share_one_reachable_dialog_per_order(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'price_cents' => 1235, 'delivered_at' => now()]);
        $this->actingAs($admin)->postJson(route('payments.store', $order), ['action' => 'receive', 'version' => 1])->assertOk();
        $this->postJson(route('payments.store', $order), ['action' => 'reverse', 'version' => 2, 'note' => 'Rimborso verificato'])->assertOk();

        $response = $this->get('/balance?ledger=reversed')->assertSee('data-cash-reversal="', false);

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $triggers = $xpath->query('//*[@data-dialog-open and starts-with(@data-dialog-open, "receipt-")]');
        $this->assertGreaterThanOrEqual(2, $triggers->length);
        foreach ($triggers as $trigger) {
            $id = $trigger->getAttribute('data-dialog-open');
            $dialogs = $xpath->query('//dialog[@id="'.$id.'"]');
            $this->assertSame(1, $dialogs->length, 'Una sola modale per '.$id);
            $this->assertSame(0, $xpath->query('ancestor::*[contains(@class,"finance-desktop-register") or contains(@class,"finance-mobile-list")]', $dialogs->item(0))->length);
        }
        if ($directory = getenv('EA_UI_FIXTURE_DIR')) {
            file_put_contents($directory.'/revision-finance-reversals.html', $response->getContent());
        }
    }
}
