<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderResource;
use App\Models\Order;
use App\Support\CustomerOrderSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerLabelController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        if ($request->input('scope') === 'filtered') {
            $data = $request->validate(['day' => ['required', 'date_format:Y-m-d'], 'preview' => ['nullable', 'boolean']]);
            $query = app(CustomerOrderSelection::class)->query($request)->whereDate('pickup_date', $data['day'])->where('parcel_count', '>', 0);
            $count = (clone $query)->count();
            abort_if($count > 1000 || (clone $query)->sum('parcel_count') > 1000, 422, 'Il documento supera 1000 etichette. Restringi i filtri.');
            if ($request->boolean('preview')) {
                // Elenco compatto della giornata per la scelta delle singole etichette: solo i campi mostrati nella selezione.
                $orders = (clone $query)->orderBy('pickup_from')->orderBy('id')->get(['slug', 'reference', 'store_name', 'pickup_address', 'pickup_street_number', 'pickup_city', 'recipient_name', 'delivery_address', 'delivery_street_number', 'delivery_city', 'parcel_count', 'pickup_from', 'pickup_to']);

                return response()->json(['count' => $count, 'day' => $data['day'], 'orders' => $orders->map(fn (Order $order): array => [
                    'slug' => $order->slug, 'reference' => $order->reference, 'store_name' => $order->store_name,
                    'pickup' => trim(trim($order->pickup_address.' '.$order->pickup_street_number).', '.$order->pickup_city, ', '),
                    'recipient_name' => $order->recipient_name,
                    'delivery' => trim(trim($order->delivery_address.' '.$order->delivery_street_number).', '.$order->delivery_city, ', '),
                    'parcel_count' => $order->parcel_count, 'pickup_from' => substr((string) $order->pickup_from, 0, 5), 'pickup_to' => substr((string) $order->pickup_to, 0, 5),
                ])->values()]);
            }
            abort_if($count === 0, 422, 'Al momento non ci sono ordini da stampare');

            return CustomerOrderResource::collection($query->withDisplayIdentity()->withReceiptStatus()->with('rider')->orderBy('pickup_date')->orderBy('id')->get());
        }
        $request->validate(['scope' => ['prohibited']]);
        $data = $request->validate(['ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'ids.*' => ['required', 'uuid', 'distinct']], ['ids.required' => 'Al momento non ci sono ordini da stampare', 'ids.min' => 'Al momento non ci sono ordini da stampare']);
        $orders = Order::where('customer_id', $request->user()->id)->whereIn('slug', $data['ids'])->where('parcel_count', '>', 0)
            ->withDisplayIdentity()->withReceiptStatus()->with('rider')->orderBy('pickup_date')->orderBy('id')->get();
        abort_unless($orders->count() === count($data['ids']), 404);
        abort_if($orders->sum('parcel_count') > 500, 422, 'Stampa al massimo 500 colli per documento.');

        return CustomerOrderResource::collection($orders);
    }
}
