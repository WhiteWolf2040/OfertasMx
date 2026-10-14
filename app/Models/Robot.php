<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Robot extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'url',
        'interval_minutes',
        'is_active',
        'last_price',
        'last_currency',
        'last_status',
        'last_run_at',
        'next_run_at',
        'selectors',
        'overrides',
        'snapshot_path',
        'target_price',
        'notify_on_drop',
        'alerted_at',
        'notify_on_restock',
    ];

    protected function casts(): array
    {
        return [
            'interval_minutes' => 'integer',
            'is_active' => 'boolean',
            'last_price' => 'decimal:2',
            'target_price' => 'decimal:2',
            'notify_on_drop' => 'boolean',
            'alerted_at' => 'datetime',
            'notify_on_restock' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'selectors' => 'array',
            'overrides' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RobotRun::class);
    }
}
