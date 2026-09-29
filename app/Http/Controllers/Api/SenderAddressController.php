<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SenderAddressRequest;
use App\Models\SenderAddress;
use App\Models\User;
use App\Support\CustomerIdentity;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SenderAddressController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->response($request->user('customer')->senderAddresses()->first());
    }

    public function update(SenderAddressRequest $request): JsonResponse
    {
        $address = DB::transaction(function () use ($request): SenderAddress {
            $customer = User::lockForUpdate()->findOrFail($request->user('customer')->id);
            abort_unless($customer->is_active && $customer->role === UserRole::Customer, 403);
            $address = $customer->senderAddresses()->first();
            abort_unless(($address?->version ?? 0) === $request->integer('version'), 409, 'Indirizzo aggiornato: ricarica prima di salvare.');
            $address ??= $customer->senderAddresses()->make();
            $address->fill(CustomerIdentity::normalize($request->safe()->except('version')));
            $address->version = ($address->version ?? 0) + 1;
            $address->save();

            return $address;
        }, 3);

        return $this->response($address);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $data): void {
            $customer = User::lockForUpdate()->findOrFail($request->user('customer')->id);
            abort_unless($customer->is_active && $customer->role === UserRole::Customer, 403);
            $address = $customer->senderAddresses()->firstOrFail();
            abort_unless($address->version === (int) $data['version'], 409, 'Indirizzo aggiornato: ricarica prima di eliminarlo.');
            $address->delete();
        }, 3);

        return response()->json(['data' => null]);
    }

    private function response(?SenderAddress $address): JsonResponse
    {
        return response()->json(['data' => $address?->only([...SenderAddress::Fields, 'version'])]);
    }
}
