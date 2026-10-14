<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotNotification extends Model
{
    protected $table = 'notifications_robot';

    public $timestamps = false;

    protected $fillable = [
        'robot_id',
        'user_id',
        'type',
        'key',
        'label',
        'price',
        'previous_price',
        'value',
        'previous_value',
        'read_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'previous_price' => 'decimal:2',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
