<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultantPeriodStat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['personal_pvf' => 'decimal:2', 'personal_pvc' => 'decimal:2', 'personal_cv' => 'decimal:2', 'group_pv' => 'decimal:2', 'activity_points' => 'decimal:2', 'pr_opening' => 'decimal:2', 'pr_closing' => 'decimal:2', 'group_pr_closing' => 'decimal:2', 'is_active' => 'boolean', 'next_rank' => 'array', 'lines_pr' => 'array'];
    }
}
