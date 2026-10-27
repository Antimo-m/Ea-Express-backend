<?php

namespace App\Http\Controllers;

use App\Actions\ReportOrderDelay;
use App\Http\Requests\CorrectOrderDelayRequest;
use App\Http\Requests\ReportOrderDelayRequest;
use App\Models\Order;
use App\Support\OrderDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class OrderDelayController extends Controller
{
    public function store(ReportOrderDelayRequest $request, Order $order, ReportOrderDelay $report): RedirectResponse|JsonResponse
    {
        $changed = $report->handle($order, $request->user(), $request->integer('version'));

        return $this->respond($request->expectsJson(), $order, $changed ? 'Ritardo segnalato.' : 'Ritardo già segnalato.');
    }

    public function update(CorrectOrderDelayRequest $request, Order $order, ReportOrderDelay $report): RedirectResponse|JsonResponse
    {
        $report->handle($order, $request->user(), $request->integer('version'), $request->validated('reason'));

        return $this->respond($request->expectsJson(), $order, 'Segnalazione corretta. La cronologia è stata conservata.');
    }

    private function respond(bool $json, Order $order, string $message): RedirectResponse|JsonResponse
    {
        if ($json) {
            $order->refresh();

            return response()->json(['message' => $message, 'status' => $order->status->value, 'version' => $order->version, 'delay' => $order->delayData(), 'html' => app(OrderDetail::class)->view($order)->render()]);
        }

        return redirect()->route('orders.show', $order)->with('status', $message);
    }
}
