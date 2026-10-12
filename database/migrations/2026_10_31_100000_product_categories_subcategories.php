<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sottocategorie del negozio: una categoria può avere una «categoria madre» (un solo livello: categoria → sottocategoria).
 * Solo un'aggiunta: le categorie esistenti restano principali, i prodotti non cambiano.
 * Se la madre viene eliminata (l'app lo impedisce finché ha figlie) le figlie tornerebbero principali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $t) {
            $t->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_id');
        });
    }
};
