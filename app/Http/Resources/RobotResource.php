<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RobotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name ?? parse_url($this->url, PHP_URL_HOST),
            'url' => $this->url,
            'interval_minutes' => $this->interval_minutes,
            'is_active' => $this->is_active,
            'last_price' => $this->last_price,
            'last_currency' => $this->last_currency,
            'last_status' => $this->last_status,
            'last_run_at' => $this->last_run_at,
            'next_run_at' => $this->next_run_at,
            'target_price' => $this->target_price,
            'notify_on_drop' => $this->notify_on_drop,
            'alerted_at' => $this->alerted_at,
            'notify_on_restock' => $this->notify_on_restock,
            'selectors' => $this->selectors,
            'overrides' => $this->overrides,
            'snapshot_path' => $this->snapshot_path,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'runs' => RobotRunResource::collection($this->whenLoaded('runs')),
        ];
    }
}
