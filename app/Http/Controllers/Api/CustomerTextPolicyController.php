<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PortalTextPolicy;
use Illuminate\Http\JsonResponse;

class CustomerTextPolicyController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(PortalTextPolicy $policy): JsonResponse
    {
        return response()->json($policy->definition())->header('Cache-Control', 'no-store');
    }
}
