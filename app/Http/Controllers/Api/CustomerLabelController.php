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
                return response()->json(['count' => $count, 'day' => $data['day']]);
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
