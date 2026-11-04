<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerPreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'required', 'in:light,dark'],
            'notification_sound' => ['sometimes', 'required', 'boolean'],
            'customer_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ]);
        $preferences = DB::transaction(function () use ($request, $data): array {
            $customer = User::lockForUpdate()->findOrFail($request->user('customer')->id);
            abort_unless($customer->is_active && $customer->role === UserRole::Customer, 403);
            $customer->portal_preferences = [...($customer->portal_preferences ?? []), ...$data];
            $customer->save();

            return $customer->portal_preferences;
        }, 3);
        $request->user('customer')->portal_preferences = $preferences;

        return response()->json(['data' => $preferences]);
    }
}
