<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        return response()->json([
            'organization' => $organization
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validated();

        // 2. Get the Organization
        $organization = $request->user()->organization;

        // 3. Update
        $organization->update($validated);

        // 4. Response
        return response()->json([
            'message' => 'Organization updated successfully.',
            'data'    => $organization
        ]);
    }
}
