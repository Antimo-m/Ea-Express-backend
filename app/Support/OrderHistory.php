<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class OrderHistory
{
    /**
     * @param  Builder<Order>  $query
     * @return array{orders: LengthAwarePaginator, archiveDays: LengthAwarePaginator, archiveTotal: int, selectedDate: ?string}
     */
    public function page(Builder $query, Request $request): array
    {
        $filters = $request->validate([
            'day' => ['nullable', 'date_format:Y-m-d'],
            'day_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $hour = $query->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00:00', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')";
        $hours = (clone $query)->reorder()->toBase()->selectRaw("{$hour} AS utc_hour, COUNT(*) AS total")
            ->groupByRaw($hour)->get();
        $totals = [];
        foreach ($hours as $row) {
            $date = Carbon::parse($row->utc_hour, 'UTC')->timezone('Europe/Rome')->toDateString();
            $totals[$date] = ($totals[$date] ?? 0) + (int) $row->total;
        }
        $days = collect($totals)->sortKeysDesc()->map(fn (int $total, string $date): array => ['date' => $date, 'total' => $total])->values();
        $page = (int) ($filters['page'] ?? 1);
        if (! isset($filters['page']) && isset($filters['day'])) {
            $index = $days->search(fn (array $day): bool => $day['date'] === $filters['day']);
            if ($index !== false) {
                $page = intdiv($index, 14) + 1;
            }
        }
        $archiveDays = new LengthAwarePaginator($days->forPage($page, 14)->values(), $days->count(), 14, $page, ['path' => $request->url()]);
        $archiveDays->appends($request->except(['page', 'day', 'day_page']));
        $selectedDate = $filters['day'] ?? ($archiveDays->first()['date'] ?? null);
        if ($selectedDate) {
            $start = Carbon::parse($selectedDate, 'Europe/Rome')->startOfDay();
            $orders = (clone $query)->where('created_at', '>=', $start->copy()->utc())
                ->where('created_at', '<', $start->copy()->addDay()->utc())
                ->latest()->orderByDesc('id')->paginate(20, ['*'], 'day_page');
        } else {
            $orders = new LengthAwarePaginator([], 0, 20, 1, ['path' => $request->url(), 'pageName' => 'day_page']);
        }
        $orders->appends([...$request->except('day_page'), 'day' => $selectedDate, 'page' => $page])
            ->fragment('archive-day-'.$selectedDate);

        return ['orders' => $orders, 'archiveDays' => $archiveDays, 'archiveTotal' => (int) $days->sum('total'), 'selectedDate' => $selectedDate];
    }
}
