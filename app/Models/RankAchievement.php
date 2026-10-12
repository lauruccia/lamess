<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankAchievement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['achieved_at' => 'immutable_datetime', 'effective_from' => 'immutable_date', 'detail' => 'array'];
    }
}
