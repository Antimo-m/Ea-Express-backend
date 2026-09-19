<?php

namespace App\Http\Controllers;

use App\Models\EconomicAudit;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EconomicAuditController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validate(['type' => ['required', 'in:orders,shipping_rates,shipping_price_proposals,expenses,pending_accounts,pending_settlements,payment_entries,financial_movements'], 'id' => ['required', 'integer', 'min:1']]);
        $entries = EconomicAudit::where('entity_type', $data['type'])->where('entity_id', $data['id'])->with('user')->latest()->orderByDesc('id')->paginate(25)->withQueryString();

        return view('balance.audit', compact('entries'));
    }
}
