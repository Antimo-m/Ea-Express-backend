<?php

namespace App\Http\Controllers;

use App\Models\AccountingControl;
use App\Models\EconomicAudit;
use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\PendingSettlement;
use App\Models\RecipientIncident;
use App\Models\ShippingPriceProposal;
use App\Models\ShippingRate;
use App\Models\User;
use App\Support\EconomicAuditPresenter;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EconomicAuditController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validate(['type' => ['required', 'in:recipient_incidents,users,orders,shipping_rates,shipping_price_proposals,expenses,pending_accounts,pending_settlements,payment_entries,financial_movements,accounting_controls'], 'slug' => ['required', 'uuid']]);
        $models = ['recipient_incidents' => RecipientIncident::class, 'users' => User::class, 'orders' => Order::class, 'shipping_rates' => ShippingRate::class, 'shipping_price_proposals' => ShippingPriceProposal::class, 'expenses' => Expense::class, 'pending_accounts' => PendingAccount::class, 'pending_settlements' => PendingSettlement::class, 'payment_entries' => PaymentEntry::class, 'financial_movements' => FinancialMovement::class, 'accounting_controls' => AccountingControl::class];
        $data['id'] = $models[$data['type']]::where('slug', $data['slug'])->firstOrFail()->id;
        $entries = EconomicAudit::where(function ($query) use ($data): void {
            $query->where(fn ($entity) => $entity->where('entity_type', $data['type'])->where('entity_id', $data['id']));
            if ($data['type'] === 'orders') {
                $query->orWhere(fn ($payments) => $payments->where('entity_type', 'payment_entries')->whereIn('entity_id', PaymentEntry::where('order_id', $data['id'])->select('id')));
            }
        })->with('user:id,slug,name')->latest()->orderByDesc('id')->paginate(25)->withQueryString();
        $entityLabel = ['recipient_incidents' => 'Precedente destinatario', 'users' => 'Rider', 'orders' => 'Spedizione', 'expenses' => 'Spesa', 'pending_accounts' => 'Sospeso', 'pending_settlements' => 'Pagamento', 'payment_entries' => 'Incasso', 'financial_movements' => 'Movimento', 'shipping_rates' => 'Tariffa', 'shipping_price_proposals' => 'Proposta tariffa', 'accounting_controls' => 'Chiusura contabile'][$data['type']];
        $presenter = new EconomicAuditPresenter;

        return view('balance.audit', compact('entries', 'entityLabel', 'presenter'));
    }
}
