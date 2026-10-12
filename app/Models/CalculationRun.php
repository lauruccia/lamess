<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalculationRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['totals' => 'array', 'warnings' => 'array', 'decisions' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommissionLine::class, 'run_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(ConsultantPeriodStat::class, 'run_id');
    }
}
