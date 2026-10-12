<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Engine\Support\Canonical;
use App\Models\CompPlanVersion;
use Illuminate\Database\Seeder;

/** Carica il piano ufficiale pre-lancio come versione attiva (idempotente per contenuto). */
class PrelaunchPlanSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('config/lamess/plan-prelaunch-2026-07-07.json');
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $hash = Canonical::hash($data);
        if (CompPlanVersion::where('content_hash', $hash)->exists()) {
            return;
        }
        CompPlanVersion::createFromArray($data['label'] ?? 'Piano pre-lancio', $data, CompPlanVersion::ACTIVE);
    }
}
