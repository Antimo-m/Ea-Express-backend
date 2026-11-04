<?php

namespace App\Http\Controllers;

use App\Actions\ManageReceipt;
use App\Actions\ReconcileReceipt;
use App\Models\Order;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function store(Request $request, Order $order, ManageReceipt $manage, ReconcileReceipt $reconcile): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->is_active && $request->user()->role === UserRole::Admin, 403);
        abort_unless(Order::financialFor($request->user())->whereKey($order->id)->exists(), 404);
        $data = $request->validate([
            'received_amount' => [$request->input('action') === 'receive' && $order->shipping_type === 'external' ? 'required' : 'prohibited', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
            'action' => ['required', 'in:receive,reverse,restore,refund,reconcile'],
            'resolution' => ['exclude_unless:action,reconcile', 'required', 'in:links,refunded,partial'],
            'confirmed' => ['exclude_unless:action,reconcile', 'accepted'],
            'note' => ['exclude_if:action,receive', 'required', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $message = $data['action'] === 'reconcile' ? $reconcile->handle($request->user(), $order, $data) : $manage->handle($request->user(), $order, $data);

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'redirect' => route('balance.index')])
            : redirect()->route('balance.index')->with('status', $message);
    }
}
