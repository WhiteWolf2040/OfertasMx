<?php

namespace App\Policies;

use App\Models\Robot;
use App\Models\User;

class RobotPolicy
{
    public function view(User $user, Robot $robot): bool
    {
        return $user->id === $robot->user_id;
    }

    public function update(User $user, Robot $robot): bool
    {
        return $user->id === $robot->user_id;
    }

    public function delete(User $user, Robot $robot): bool
    {
        return $user->id === $robot->user_id;
    }

    public function run(User $user, Robot $robot): bool
    {
        return $user->id === $robot->user_id;
    }
}
