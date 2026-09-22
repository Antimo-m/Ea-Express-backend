<?php

namespace App\Http\Controllers;

use App\Actions\CorrectPendingSettlement;
use App\Http\Requests\CorrectPendingSettlementRequest;
use App\Models\PendingAccount;
use App\Models\PendingSettlement;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class PendingSettlementController extends Controller
{
    public function update(CorrectPendingSettlementRequest $request, PendingAccount $account, PendingSettlement $settlement, CorrectPendingSettlement $correct): JsonResponse|RedirectResponse
    {
        abort_unless($settlement->pending_account_id === $account->id, 404);
        $data = $request->validated();
        $correct->handle($request->user(), $account, $settlement, Money::cents($data['amount']), $data['reason'], (int) $data['version']);
        $message = 'Pagamento corretto. Saldo, residuo e bilancio aggiornati.';
        $redirect = route('pending.index', ['id' => $account->id, 'direction' => $account->direction]);

        return $request->expectsJson() ? response()->json(compact('message', 'redirect')) : redirect($redirect)->with('status', $message);
    }
}
