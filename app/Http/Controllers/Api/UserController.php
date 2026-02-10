<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        // Eager load the organization and plan to minimize database queries
        $user = $request->user()->load(['organization.plan']);

        return response()->json([
            'user' => $user,
            // You can format specific fields here if needed
        ]);
    }
}
