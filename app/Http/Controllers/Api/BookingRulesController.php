<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingRulesController extends Controller
{
    public function __invoke(Request $request, BookingRules $rules): JsonResponse
    {
        return response()->json($rules->payload($request->is('api/v1/customer/*') ? 1 : 0))->header('Cache-Control', 'no-store');
    }
}
