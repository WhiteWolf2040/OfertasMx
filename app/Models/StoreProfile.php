<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProfile extends Model
{
    protected $fillable = [
        'user_id',
        'domain',
        'selectors',
        'success_count',
        'fail_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'selectors' => 'array',
            'success_count' => 'integer',
            'fail_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
