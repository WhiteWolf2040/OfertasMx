<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;

class DealPolicy
{
    public function update(User $user, Deal $deal): bool
    {
        return $user->id === $deal->user_id;
    }

    public function delete(User $user, Deal $deal): bool
    {
        return $user->id === $deal->user_id;
    }
}
