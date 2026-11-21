<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\OrderStatus;
use App\Support\AccessGuard;
use App\Support\DeliveryDeadline;
use App\Support\ReportingPeriod;
use App\Support\RiderActivity;
use App\Support\RiderOperations;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RiderOperationsController extends Controller
{
    private function period(Request $request): ReportingPeriod
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'], 'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'], 'period' => ['nullable', 'in:today,week,month,custom'], 'view' => ['nullable', 'in:zone,rider'], 'zone' => ['nullable', 'string', 'max:255'], 'rider' => ['nullable', 'uuid', 'exists:users,slug'], 'q' => ['nullable', 'string', 'max:255'], 'filter' => ['nullable', 'in:all,open'], 'sort' => ['nullable', 'in:name,delivered,pending,unassigned']], ['to.after_or_equal' => 'La data finale deve essere uguale o successiva alla data iniziale.']);
        $mode = $data['period'] ?? (isset($data['date']) || isset($data['from']) ? 'custom' : 'today');
        if ($mode === 'custom' && isset($data['from'], $data['to'])) {
            return new ReportingPeriod(Carbon::parse($data['from'], 'Europe/Rome')->startOfDay(), Carbon::parse($data['to'], 'Europe/Rome')->endOfDay());
        }

        return ReportingPeriod::operational($mode, $data['date'] ?? null);
    }

    public function index(Request $request, RiderOperations $operations): View
    {
        $period = $this->period($request);

        return view('rider-operations.index', ['day' => $operations->day($period->start, end: $period->end), 'period' => $period]);
    }

    public function feed(Request $request, RiderOperations $operations): JsonResponse
    {
        $period = $this->period($request);
        $day = $operations->day($period->start, end: $period->end);

        return response()->json($day)->header('Cache-Control', 'private, no-store');
    }

    public function zoneOrders(Request $request, RiderOperations $operations): View
    {
        $request->validate(['zone' => ['required', 'string', 'max:255']]);

        return $this->detail($request, $operations);
    }

    public function detail(Request $request, RiderOperations $operations): View
    {
        $period = $this->period($request);
        $date = $period->start;
        $riderId = $request->filled('rider') ? User::where('slug', $request->input('rider'))->value('id') : null;
        $query = $operations->query($date, $riderId, $request->input('zone'), $period->end);
        if ($riderId) {
            $query->where('rider_id', $riderId);
        }
        if ($request->boolean('unassigned')) {
            $query->whereNull('rider_id');
        }
        if ($request->input('filter') === 'open') {
            $query->whereNotIn('status', OrderStatus::closed());
        }
        $orders = $query->withDisplayIdentity()->with(['rider', 'scheduleEvents', 'deliveryEvent', 'events' => fn ($events) => $events->whereNull('delay_action')->orderBy('id')])->orderBy('pickup_date')->orderBy('pickup_from')->orderBy('id')->paginate(20)->withQueryString();
        $deadlines = $orders->getCollection()->mapWithKeys(fn ($order) => [$order->slug => app(DeliveryDeadline::class)->forOrder($order, $period->reference())]);

        $compact = $request->filled('rider') || $request->boolean('unassigned');
        $backParameters = $request->only(['date', 'from', 'to', 'period', 'view', 'q', 'filter', 'sort', 'selected', 'detail_page']);
        $returnUrl = route('rider-operations.index', $backParameters);

        return view($request->routeIs('rider-operations.zone-orders') ? 'rider-operations.zone-orders' : 'rider-operations.orders', compact('orders', 'deadlines', 'period', 'compact', 'returnUrl'));
    }

    /** Pagina operativa di un Rider consultata dall'Admin: statistiche, consegne e resoconto contabile. */
    public function show(Request $request, User $rider, RiderOperations $operations, RiderActivity $activity): View
    {
        abort_unless(in_array($rider->role, [UserRole::Rider, UserRole::Admin], true), 404);

        return $this->riderPage($request, $rider, true, $operations, $activity);
    }

    /**
     * Pagina operativa personale del Rider: nessun parametro identifica l'account, quindi non è possibile
     * aprire quella di un altro Rider. Nessun dato contabile (incassi, sospesi, saldo).
     */
    public function mine(Request $request, RiderOperations $operations, RiderActivity $activity): View
    {
        AccessGuard::hideUnless($request->user()->role === UserRole::Rider);

        return $this->riderPage($request, $request->user(), false, $operations, $activity);
    }

    private function riderPage(Request $request, User $rider, bool $admin, RiderOperations $operations, RiderActivity $activity): View
    {
        [$period, $selection] = $this->riderPeriod($request);
        $state = $request->validate(['state' => ['nullable', 'in:all,todo,active,completed,issues,closed']])['state'] ?? 'all';
        $zone = $admin ? $request->validate(['zone' => ['nullable', 'string', 'max:255']])['zone'] ?? null : null;
        $query = $operations->dailyWorkload($period->start, $rider->id, $zone, $period->end);
        $counts = $operations->dailyCounts($query);
        $accounting = $admin ? $operations->dailyAccounting($period, $rider->id, $query, $zone) : null;
        $orders = $operations->filterDailyState(clone $query, $state)->withDisplayIdentity()->with('rider:id,slug,name')->orderBy('pickup_date')->orderBy('pickup_from')->orderBy('id')->paginate(20)->withQueryString();
        $current = $activity->current($rider->id);
        $balance = $operations->riderBalance($period, $rider->id);
        $toConfirm = $admin ? collect() : $operations->receiptsToConfirm($rider->id)->withDisplayIdentity()
            ->with(['receiptDeclarations' => fn ($declarations) => $declarations->where('state', 'pending')])
            ->latest('delivered_at')->orderByDesc('id')->limit(10)->get();
        $summary = $activity->period($rider->id, $period, $operations);
        $base = array_filter(['period' => $selection['mode'], 'from' => $selection['mode'] === 'custom' ? $period->start->toDateString() : null, 'to' => $selection['mode'] === 'custom' ? $period->end->toDateString() : null, 'month' => $selection['mode'] === 'month_of' ? $selection['month'] : null, 'year' => $selection['mode'] === 'year_of' ? $selection['year'] : null, 'zone' => $zone]);
        $pageUrl = fn (array $parameters = []): string => $admin ? route('rider-operations.show', ['rider' => $rider->slug, ...$parameters]) : route('rider-operations.mine', $parameters);

        return view('rider-operations.show', compact('rider', 'admin', 'period', 'selection', 'zone', 'state', 'counts', 'accounting', 'orders', 'current', 'summary', 'base', 'pageUrl', 'balance', 'toConfirm'));
    }

    /**
     * Periodo della pagina operativa: oggi, ultimi 7 giorni, mese o anno correnti, un mese o un anno passati,
     * oppure un intervallo libero (al massimo dieci anni).
     *
     * @return array{ReportingPeriod, array{mode: string, month: string, year: int, label: string, single_day: bool, today: bool, shortcut: ?string}}
     */
    private function riderPeriod(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:today,last7,week,month,year,custom,month_of,year_of'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'required_if:period,custom', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'month' => ['nullable', 'required_if:period,month_of', 'date_format:Y-m'],
            'year' => ['nullable', 'required_if:period,year_of', 'integer', 'min:2020', 'max:2100'],
        ], ['to.after_or_equal' => 'La data finale deve essere uguale o successiva alla data iniziale.']);
        $today = now('Europe/Rome')->startOfDay();
        // Compatibilità con i collegamenti esistenti: `date` indica una sola giornata.
        if (! isset($data['period']) && ! isset($data['from']) && isset($data['date'])) {
            $data['from'] = $data['to'] = $data['date'];
        }
        $mode = $data['period'] ?? (isset($data['from'], $data['to']) ? 'custom' : 'today');
        if ($mode === 'custom' && ! isset($data['from'], $data['to'])) {
            $data['from'] = $data['to'] = $data['date'] ?? $today->toDateString();
        }
        $period = match ($mode) {
            'last7' => new ReportingPeriod($today->copy()->subDays(6), $today->copy()->endOfDay()),
            'week' => ReportingPeriod::operational('week'),
            'month' => ReportingPeriod::month($today->format('Y-m')),
            'year' => ReportingPeriod::year($today->year),
            'month_of' => ReportingPeriod::month($data['month']),
            'year_of' => ReportingPeriod::year((int) $data['year']),
            'custom' => new ReportingPeriod(Carbon::parse($data['from'], 'Europe/Rome')->startOfDay(), Carbon::parse($data['to'], 'Europe/Rome')->endOfDay()),
            default => new ReportingPeriod($today->copy(), $today->copy()->endOfDay()),
        };
        if ($period->start->diffInDays($period->end) > 3660) {
            throw ValidationException::withMessages(['to' => 'Scegli un periodo di al massimo dieci anni.']);
        }
        $single = $period->start->isSameDay($period->end);
        // Un intervallo che coincide con un mese o un anno interi viene mostrato come tale (scorciatoie del filtro).
        $wholeMonth = $period->start->isSameDay($period->start->copy()->startOfMonth()) && $period->end->isSameDay($period->start->copy()->endOfMonth());
        $wholeYear = $period->start->isSameDay($period->start->copy()->startOfYear()) && $period->end->isSameDay($period->start->copy()->endOfYear());
        $shortcut = match (true) {
            $wholeYear => 'year', $wholeMonth => 'month', default => null
        };
        if ($mode === 'custom' && $shortcut) {
            $mode = $shortcut === 'year' ? 'year_of' : 'month_of';
        }
        $short = fn (Carbon $date): string => $date->format('d/m/Y');
        $label = match ($mode) {
            'today' => 'Oggi · '.$period->start->copy()->locale('it')->translatedFormat('l j F Y'),
            'last7' => 'Ultimi 7 giorni · dal '.$short($period->start).' al '.$short($period->end),
            'month', 'month_of' => ucfirst($period->start->copy()->locale('it')->translatedFormat('F Y')),
            'year', 'year_of' => 'Anno '.$period->start->year,
            default => $single ? $period->start->copy()->locale('it')->translatedFormat('l j F Y') : 'Dal '.$short($period->start).' al '.$short($period->end),
        };

        return [$period, ['mode' => $mode, 'month' => $period->start->format('Y-m'), 'year' => $period->start->year, 'label' => $label, 'single_day' => $single, 'today' => $single && $period->start->isSameDay($today), 'shortcut' => $shortcut]];
    }
}
