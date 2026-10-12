<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log di audit in sola scrittura con catena di hash (vedi App\Domain\Audit\AuditLog).
 * - nessuna chiave esterna: il log sopravvive a qualunque cancellazione di utenti o dati;
 * - prev_hash è UNIQUE: la catena non può biforcarsi;
 * - trigger che vietano UPDATE e DELETE, quando il database lo consente (su alcuni hosting condivisi no:
 *   in quel caso restano la catena di hash e il blocco a livello di modello, e la pagina di verifica lo segnala).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('occurred_at', 32);
            $t->unsignedBigInteger('actor_id')->nullable()->index();
            $t->string('actor_label', 190);
            $t->string('ip', 45)->nullable();
            $t->string('action', 80)->index();
            $t->string('subject_type', 120)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->text('description');
            $t->longText('changes')->nullable();
            $t->text('reason')->nullable();
            $t->char('prev_hash', 64)->unique();
            $t->char('hash', 64)->unique();
            $t->index(['subject_type', 'subject_id']);
            $t->index('occurred_at');
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }

    private function createTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        $message = 'audit_logs è in sola scrittura';
        try {
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, '{$message}'); END;");
                DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, '{$message}'); END;");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
                DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
            }
        } catch (Throwable) {
            // privilegio TRIGGER mancante (tipico dell'hosting condiviso): si prosegue senza, la pagina di verifica lo mostra
        }
    }
};
