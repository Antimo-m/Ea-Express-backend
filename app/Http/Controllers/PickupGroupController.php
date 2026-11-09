<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\PickupAgenda;
use App\Support\PublicReferences;
use App\Support\RecipientRisk;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PickupGroupController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'regex:/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/']]);
        $today = now('Europe/Rome');
        $month = Carbon::createFromFormat('!Y-m', $data['month'] ?? $today->format('Y-m'), 'Europe/Rome')->locale('it');
        $base = $this->scheduledPickups($request);
        $dailyCounts = (clone $base)->whereBetween('pickup_date', [$month->toDateString(), $month->copy()->endOfMonth()->format('Y-m-d H:i:s')])
            ->selectRaw('DATE(pickup_date) AS scheduled_date, COUNT(*) as total')->groupByRaw('DATE(pickup_date)')->pluck('total', 'scheduled_date')
            ->map(fn (int|string $count): int => (int) $count);
        $days = [];
        $lastDay = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        for ($day = $month->copy()->startOfWeek(Carbon::MONDAY); $day->lte($lastDay); $day->addDay()) {
            $days[] = $day->isSameMonth($month) ? $day->copy() : null;
        }
        $weeks = collect($days)->chunk(7);
        $previousMonth = $month->format('Y-m') === '0001-01' ? null : $month->copy()->subMonth()->format('Y-m');
        $nextMonth = $month->format('Y-m') === '9999-12' ? null : $month->copy()->addMonth()->format('Y-m');

        return view('pickups.index', compact('month', 'today', 'dailyCounts', 'weeks', 'previousMonth', 'nextMonth'));
    }

    public function show(Request $request, PickupAgenda $agenda): Response|JsonResponse
    {
        $data = $this->pagination($request, 10);
        $date = Carbon::createFromFormat('!Y-m-d', $data['date'], 'Europe/Rome')->locale('it');

        return $this->readSnapshot(function () use ($request, $agenda, $date, $data): Response|JsonResponse {
            ['groups' => $groups, 'totalOrders' => $totalOrders] = $agenda->accounts($request->user(), $date, $data['page'] ?? 1, $data['per_page'] ?? 10);
            if ($request->expectsJson()) {
                return response()->json([
                    'date' => $date->toDateString(), 'total_orders' => $totalOrders,
                    'data' => $groups->items(), ...$this->pageData($groups),
                ])->header('Cache-Control', 'private, no-store');
            }
            $view = $request->header('X-Pickup-Agenda') === 'accounts' ? 'pickups.accounts' : 'pickups.day';

            return response()->view($view, compact('date', 'groups', 'totalOrders'))->header('Cache-Control', 'private, no-store');
        });
    }

    public function orders(Request $request, string $account, PickupAgenda $agenda): Response|JsonResponse
    {
        $data = $this->pagination($request, 20);
        $kind = $request->validate(['kind' => ['nullable', 'in:customer,manual']])['kind'] ?? 'customer';
        $date = Carbon::createFromFormat('!Y-m-d', $data['date'], 'Europe/Rome')->locale('it');

        return $this->readSnapshot(function () use ($request, $account, $agenda, $date, $data, $kind): Response|JsonResponse {
            $reference = $kind === 'manual' ? 'order_id' : 'customer_id';
            $referenceId = PublicReferences::ids([$reference => $account])[$reference];
            ['query' => $query, 'summary' => $accountSummary] = $agenda->account($request->user(), $date, $referenceId, $kind);
            $orders = $agenda->orders($query, $accountSummary->orders_count, $data['page'] ?? 1, $data['per_page'] ?? 20)
                ->appends(['date' => $date->toDateString(), 'kind' => $kind, 'per_page' => $data['per_page'] ?? 20]);
            $recipientRisks = app(RecipientRisk::class)->forOrders($orders->getCollection());
            if ($request->expectsJson()) {
                return response()->json([
                    'date' => $date->toDateString(), 'account' => $accountSummary,
                    'data' => $orders->getCollection()->map(fn (Order $order): array => $this->orderData($order, $recipientRisks[$order->id] ?? null))->all(),
                    ...$this->pageData($orders),
                ])->header('Cache-Control', 'private, no-store');
            }
            $accountSlug = $accountSummary->slug;

            return response()->view('pickups.account-orders', compact('date', 'orders', 'recipientRisks', 'accountSummary', 'accountSlug', 'kind'))
                ->header('Cache-Control', 'private, no-store');
        });
    }

    private function readSnapshot(Closure $read): Response|JsonResponse
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql' && $connection->transactionLevel() === 0) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction($read);
    }

    /** @return array{date: string, page?: int, per_page?: int} */
    private function pagination(Request $request, int $maximum): array
    {
        return $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'regex:/^(?!0000)\\d{4}-/'],
            'page' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$maximum],
        ]);
    }

    /** @return array{meta: array<string, int|null>, links: array<string, string|null>} */
    private function pageData(LengthAwarePaginator $page): array
    {
        return [
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
        ];
    }

    /** @param array{count: int, last_at: ?string}|null $risk @return array<string, mixed> */
    private function orderData(Order $order, ?array $risk): array
    {
        return [
            'slug' => $order->slug, 'reference' => $order->reference, 'store_name' => $order->displayName(),
            'recipient_name' => $order->recipient_name, 'recipient_risk' => $risk,
            'status' => $order->status->value, 'status_label' => $order->status->label(), 'parcel_count' => (int) $order->parcel_count,
            'pickup_date' => $order->pickup_date->toDateString(), 'pickup_from' => substr($order->pickup_from, 0, 5), 'pickup_to' => substr($order->pickup_to, 0, 5),
            'pickup_address' => $order->pickup_address, 'pickup_street_number' => $order->pickup_street_number, 'pickup_postal_code' => $order->pickup_postal_code, 'pickup_city' => $order->pickup_city,
            'delivery_address' => $order->delivery_address, 'delivery_street_number' => $order->delivery_street_number, 'delivery_postal_code' => $order->delivery_postal_code, 'delivery_city' => $order->delivery_city,
            'rider' => $order->rider ? ['slug' => $order->rider->slug, 'name' => $order->rider->name] : null,
            'detail_url' => route('orders.show', $order), 'labels_url' => route('labels.index', ['ids' => [$order->slug]]),
        ];
    }

    /** @return Builder<Order> */
    private function scheduledPickups(Request $request): Builder
    {
        return Order::visibleTo($request->user())->awaitingPickup();
    }
}
