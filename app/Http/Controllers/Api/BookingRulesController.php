<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BookingRules;
use Illuminate\Http\JsonResponse;

class BookingRulesController extends Controller
{
    public function __invoke(BookingRules $rules): JsonResponse
    {
        return response()->json($rules->payload())->header('Cache-Control', 'no-store');
    }
}
