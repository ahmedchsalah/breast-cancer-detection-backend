<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->getRoleNames()->first(),
            'is_active' => $this->is_active,
            'avatar' => $this->avatar,
            'joined_at' => $this->created_at->format('d M Y h:i A'),

            // Clean Organization Object (Updated with new schema)
            'organization' => $this->organization ? [
                'id' => $this->organization->id, // مهم جداً للـ Frontend (خاصة للطبيب)
                'name' => $this->organization->name,
                'type' => $this->organization->type,
                'address' => $this->organization->address,
                'latitude' => $this->organization->latitude,
                'longitude' => $this->organization->longitude,
                'status' => $this->organization->status, // pending, active, etc.
                'subscription_status' => $this->organization->subscription_status,
                'plan_id' => $this->organization->plan_id,
            ] : null,
        ];
    }
}
