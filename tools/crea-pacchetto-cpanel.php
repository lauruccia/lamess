<?php

/**
 * Crea il pacchetto ZIP da caricare su cPanel (file manager o FTP), senza bisogno di terminale sul server.
 *
 *   php tools/crea-pacchetto-cpanel.php                      → aggiornamento (NON contiene .env)
 *   php tools/crea-pacchetto-cpanel.php --prima-installazione → aggiunge ".env.da-compilare" con APP_KEY e token già generati
 *
 * Lo ZIP contiene l'applicazione con vendor/ di produzione (composer --no-dev). Il contenuto di public/ va nella cartella
 * pubblica del dominio (public_html) — oppure si punta il dominio alla cartella public/: vedi docs/installazione-cpanel.md.
 * I segreti (APP_KEY, token) restano in un file LOCALE (tools/.segreti-cpanel.env, ignorato) così non cambiano a ogni pacchetto:
 * una APP_KEY diversa renderebbe illeggibili i dati cifrati e non più verificabile il log di audit.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$first = in_array('--prima-installazione', $argv, true);
$out = $root.'/dist';
$stage = sys_get_temp_dir().'/lamess-pkg-'.bin2hex(random_bytes(4));

function run(string $cmd): void
{
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Errore ($code): $cmd\n");
        exit(1);
    }
}

$exclude = ['.git', 'node_modules', 'vendor', 'tests', 'dist', '.env', '.env.da-compilare', 'database/database.sqlite', 'storage/logs', 'storage/framework', 'storage/app', 'tools/.segreti-cpanel.env', '.phpunit.result.cache', 'AGENTS.md', 'CLAUDE.md', 'phpunit.xml', 'phpunit.maria.xml', 'database/data/odoo', '_to_delete', 'public/hot', 'public/storage', '.ai', '.cursor', '.claude', 'docs/phpunit.maria.xml.esempio', 'bootstrap/cache', '.mcp.json', '.github', 'boost.json', '.styleci.yml', '.npmrc'];
$excludeGlobs = ['*.sqlite', '*.sqlite-journal', '*.tgz', '*.log', '.DS_Store', 'Thumbs.db'];
@mkdir($stage, 0775, true);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = ltrim(substr($f->getPathname(), strlen($root)), '/\\');
    $rel = str_replace('\\', '/', $rel);
    foreach ($excludeGlobs as $g) {
        if (fnmatch($g, basename($rel))) {
            continue 2;
        }
    }
    foreach ($exclude as $e) {
        if ($rel === $e || str_starts_with($rel, $e.'/')) {
            continue 2;
        }
    }
    if ($f->isDir()) {
        @mkdir("$stage/$rel", 0775, true);
    } else {
        copy($f->getPathname(), "$stage/$rel");
    }
}
foreach (['storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $d) {
    @mkdir("$stage/$d", 0775, true);
    file_put_contents("$stage/$d/.gitkeep", '');
}

run('cd '.escapeshellarg($stage).' && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --quiet');
// niente cartelle .git delle librerie (pesano centinaia di MB) né file inutili
run('find '.escapeshellarg($stage.'/vendor').' -name .git -type d -prune -exec rm -rf {} + ; find '.escapeshellarg($stage.'/vendor').' -name ".git*" -maxdepth 3 -type f -delete');
run('cd '.escapeshellarg($stage).' && composer dump-autoload --optimize --no-dev --no-scripts --quiet');

if ($first) {
    $secretsFile = __DIR__.'/.segreti-cpanel.env';
    if (! is_file($secretsFile)) {
        file_put_contents($secretsFile, 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\nLAMESS_INSTALL_TOKEN=".bin2hex(random_bytes(16))."\n");
        chmod($secretsFile, 0600);
    }
    $secrets = [];
    foreach (file($secretsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$k, $v] = explode('=', $line, 2) + [1 => ''];
        $secrets[$k] = $v;
    }
    $env = (string) file_get_contents($root.'/.env.cpanel.example');
    $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$secrets['APP_KEY'], $env);
    $env = preg_replace('/^LAMESS_INSTALL_TOKEN=.*$/m', 'LAMESS_INSTALL_TOKEN='.$secrets['LAMESS_INSTALL_TOKEN'], $env);
    file_put_contents("$stage/.env.da-compilare", $env);
}

@mkdir($out, 0775, true);
$zipPath = $out.'/lamess-'.date('Ymd-His').($first ? '-prima-installazione' : '-aggiornamento').'.zip';
$zip = new ZipArchive;
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Impossibile creare lo ZIP\n");
    exit(1);
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (! is_file($f->getPathname()) || is_dir($f->getPathname())) {
        continue;
    }
    $zip->addFile($f->getPathname(), str_replace('\\', '/', ltrim(substr($f->getPathname(), strlen($stage)), '/\\')));
}
$zip->close();
run('rm -rf '.escapeshellarg($stage));

echo "\nPacchetto creato: $zipPath (".round(filesize($zipPath) / 1048576, 1)." MB)\n";
if ($first) {
    echo "Contiene .env.da-compilare: rinominalo in .env sul server e compila database e dominio. Il token di installazione è in tools/.segreti-cpanel.env\n";
}
