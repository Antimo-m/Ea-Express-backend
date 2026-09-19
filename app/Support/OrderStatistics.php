<?php

namespace App\Support;

use App\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OrderStatistics
{
    public function period(Request $request): ReportingPeriod
    {
        $data = $request->validate(['period' => ['nullable', 'in:today,week,month,custom'], 'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'], 'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d']]);
        $today = now('Europe/Rome');
        $start = match ($data['period'] ?? 'month') {
            'today' => $today->copy()->startOfDay(),'week' => $today->copy()->startOfWeek(),'custom' => Carbon::parse($data['from'], 'Europe/Rome')->startOfDay(),default => $today->copy()->startOfMonth()
        };
        $end = ($data['period'] ?? null) === 'custom' ? Carbon::parse($data['to'], 'Europe/Rome')->endOfDay() : $today->copy()->endOfDay();
        if ($start->greaterThan($end) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'Intervallo non valido: massimo un anno.']);
        }

        return new ReportingPeriod($start, $end);
    }

    /** @return array<string,mixed> */
    public function summarize(Builder $query): array
    {
        $count = (clone $query)->count();
        $delivered = (clone $query)->where('status', OrderStatus::Delivered)->count();
        $cancelled = (clone $query)->whereIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->count();
        $priced = (clone $query)->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->whereNotNull('price_cents');

        return ['total' => $count, 'delivered' => $delivered, 'cancelled' => $cancelled, 'in_progress' => $count - $delivered - $cancelled, 'completion_percent' => $count ? round(100 * $delivered / $count, 1) : 0, 'shipping_spend_cents' => (int) (clone $priced)->sum('price_cents'), 'delivered_spend_cents' => (int) (clone $priced)->where('status', OrderStatus::Delivered)->sum('price_cents'), 'unpriced' => (clone $query)->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->whereNull('price_cents')->count(), 'prices' => (clone $priced)->selectRaw('price_cents, COUNT(*) AS shipments, SUM(price_cents) AS total_cents')->groupBy('price_cents')->orderBy('price_cents')->get()];
    }
}
