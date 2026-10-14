<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'robot_id',
        'status',
        'provider',
        'data',
        'price',
        'error',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'price' => 'decimal:2',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }
}
