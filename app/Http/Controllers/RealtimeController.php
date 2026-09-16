<?php

namespace App\Http\Controllers;

use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class RealtimeController extends Controller
{
    public function configuration(Request $request): JsonResponse
    {
        return response()->json(['key' => config('broadcasting.connections.reverb.key'), 'host' => config('realtime.host'), 'port' => config('realtime.port'), 'scheme' => config('realtime.scheme'), 'channel' => ($request->user()->role === UserRole::Customer ? 'customer.' : 'staff.').$request->user()->id]);
    }

    public function authenticate(Request $request): mixed
    {
        $data = $request->validate(['socket_id' => ['required', 'regex:/^\d+\.\d+$/D'], 'channel_name' => ['required', 'string', 'max:100']]);
        $expected = 'private-'.($request->user()->role === UserRole::Customer ? 'customer.' : 'staff.').$request->user()->id;
        abort_unless(hash_equals($expected, $data['channel_name']), 403);

        $broadcaster = Broadcast::connection('reverb');
        $broadcaster->channel(substr($expected, 8), fn ($user) => $user->is_active && $user->id === $request->user()->id);

        return $broadcaster->auth($request);
    }
}
