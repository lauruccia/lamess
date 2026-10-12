<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Varianti di prodotto (formato, taglia, colore…). Ogni variante è un vero prodotto «figlio» del prodotto principale:
 * così carrello, giacenza, PV/CV, IVA, ordini, fatture e resi funzionano senza eccezioni. Solo aggiunte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedBigInteger('parent_id')->nullable()->index();   // valorizzato solo sulle varianti
            $t->json('options')->nullable();                            // sul principale: [{name, values[]}]
            $t->json('variant_options')->nullable();                    // sulla variante: {nome opzione: valore}
            $t->string('variant_label', 120)->nullable();               // «100 bustine / Rosso»
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['parent_id', 'options', 'variant_options', 'variant_label']);
        });
    }
};
