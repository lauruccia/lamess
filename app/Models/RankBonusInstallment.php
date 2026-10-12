<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankBonusInstallment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected $table = 'rank_bonus_installments';

    public function award(): BelongsTo
    {
        return $this->belongsTo(RankAward::class, 'rank_award_id');
    }
}
