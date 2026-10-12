<?php

declare(strict_types=1);

namespace App\Domain\System;

use App\Domain\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copia di sicurezza del database, creata dal portale stesso (utile dove non c'è il terminale per mysqldump).
 * MySQL/MariaDB: dump SQL in PHP (tabelle, dati e trigger), compresso. SQLite: copia coerente del file.
 * I backup restano in storage/app/backups (privato) e si scaricano solo dal super_admin.
 */
final class BackupService
{
    public const DIR = 'backups';

    public const KEEP = 14;

    /** @return array{name: string, size: int} */
    public function create(string $reason = 'manuale'): array
    {
        Storage::makeDirectory(self::DIR);
        $stamp = now('Europe/Rome')->format('Ymd-His');
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $name = "lamess-{$stamp}.sqlite";
            $target = Storage::path(self::DIR.'/'.$name);
            try {
                DB::statement('VACUUM INTO '.DB::connection()->getPdo()->quote($target));
            } catch (\Throwable) {
                $file = (string) DB::connection()->getDatabaseName();
                if (! is_file($file) || ! copy($file, $target)) {
                    throw new RuntimeException('Impossibile copiare il database SQLite.');
                }
            }
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $gz = function_exists('gzopen');
            $name = "lamess-{$stamp}.sql".($gz ? '.gz' : '');
            $this->dumpMysql(Storage::path(self::DIR.'/'.$name), $gz);
        } else {
            throw new RuntimeException("Backup non supportato per il database «{$driver}».");
        }

        $size = (int) Storage::size(self::DIR.'/'.$name);
        $this->prune();
        AuditLog::record('backup.created', "Backup del database creato ({$reason}): {$name}", null, ['file' => $name, 'byte' => $size]);

        return ['name' => $name, 'size' => $size];
    }

    /** @return list<array{name: string, size: int, at: CarbonImmutable}> dal più recente */
    public function list(): array
    {
        $out = [];
        foreach (Storage::files(self::DIR) as $f) {
            $name = basename($f);
            if (str_starts_with($name, 'lamess-')) {
                $out[] = ['name' => $name, 'size' => (int) Storage::size($f), 'at' => CarbonImmutable::createFromTimestamp(Storage::lastModified($f), 'Europe/Rome')];
            }
        }
        usort($out, fn ($a, $b) => $b['at'] <=> $a['at'] ?: strcmp($b['name'], $a['name']));

        return $out;
    }

    public function path(string $name): string
    {
        abort_unless(preg_match('/^lamess-\d{8}-\d{6}\.(sql|sql\.gz|sqlite)$/', $name) === 1 && Storage::exists(self::DIR.'/'.$name), 404);

        return Storage::path(self::DIR.'/'.$name);
    }

    public function latestAgeHours(): ?float
    {
        $l = $this->list()[0] ?? null;

        return $l === null ? null : $l['at']->diffInMinutes(now(), true) / 60;
    }

    private function prune(): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            Storage::delete(self::DIR.'/'.$old['name']);
        }
    }

    private function dumpMysql(string $path, bool $gz): void
    {
        $pdo = DB::connection()->getPdo();
        $h = $gz ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if ($h === false) {
            throw new RuntimeException('Impossibile scrivere il file di backup.');
        }
        $w = fn (string $s) => $gz ? gzwrite($h, $s) : fwrite($h, $s);

        $w('-- Lamèss — backup del database '.now('Europe/Rome')->format('d/m/Y H:i:s')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = [];
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(\PDO::FETCH_NUM) as $row) {
            $tables[] = $row[0];
        }
        foreach ($tables as $t) {
            $create = $pdo->query('SHOW CREATE TABLE `'.str_replace('`', '``', $t).'`')->fetch(\PDO::FETCH_NUM)[1];
            $w("DROP TABLE IF EXISTS `{$t}`;\n{$create};\n\n");
            $stmt = $pdo->query('SELECT * FROM `'.str_replace('`', '``', $t).'`', \PDO::FETCH_NUM);
            $batch = [];
            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                $batch[] = '('.implode(',', array_map(fn ($v) => self::sqlValue($pdo, $v), $row)).')';
                if (count($batch) >= 200) {
                    $w("INSERT INTO `{$t}` VALUES ".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $w("INSERT INTO `{$t}` VALUES ".implode(",\n", $batch).";\n");
            }
            $w("\n");
        }
        // I trigger (protezione del log di audit) si ricreano per ultimi, dopo i dati.
        try {
            $triggers = $pdo->query('SHOW TRIGGERS')->fetchAll(\PDO::FETCH_ASSOC);
            if ($triggers !== []) {
                $w("DELIMITER $$\n");
                foreach ($triggers as $tr) {
                    $name = $tr['Trigger'];
                    $def = $pdo->query('SHOW CREATE TRIGGER `'.str_replace('`', '``', $name).'`')->fetch(\PDO::FETCH_ASSOC);
                    $w("DROP TRIGGER IF EXISTS `{$name}`$$\n".preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', (string) ($def['SQL Original Statement'] ?? ''))."$$\n");
                }
                $w("DELIMITER ;\n");
            }
        } catch (\Throwable) {
            $w("-- trigger non leggibili con questo utente del database\n");
        }
        $w("\nSET FOREIGN_KEY_CHECKS=1;\n");
        $gz ? gzclose($h) : fclose($h);
    }

    public static function sqlValue(\PDO $pdo, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return (string) $pdo->quote((string) $v);
    }
}
