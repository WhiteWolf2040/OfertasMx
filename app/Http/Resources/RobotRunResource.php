<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RobotRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'provider' => $this->provider,
            'data' => $this->data,
            'price' => $this->price,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at,
        ];
    }
}
