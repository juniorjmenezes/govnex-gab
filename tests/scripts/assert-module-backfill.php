<?php

declare(strict_types=1);

use App\Enums\GabineteModule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.mysql.database');

if (! app()->environment('testing') || $database !== 'govnexgab_modules_test') {
    fwrite(STDERR, "Refusing to alter a database outside the dedicated CI environment.\n");
    exit(1);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
};

$moduleCodes = array_column(GabineteModule::cases(), 'value');
$officeIds = DB::table('gabinetes')->orderBy('id')->pluck('id')->all();

if ($officeIds === []) {
    $fail('The seed must create at least one office before testing the backfill.');
}

DB::table('gabinete_modulo_eventos')->delete();
DB::table('gabinete_modulos')->delete();

$migration = require database_path('migrations/2026_08_10_000001_create_gabinete_module_tables.php');
$migration->up();

$assertState = static function () use ($fail, $moduleCodes, $officeIds): array {
    $rows = DB::table('gabinete_modulos')
        ->orderBy('gabinete_id')
        ->orderBy('modulo')
        ->get(['gabinete_id', 'modulo', 'ativo', 'administrador_id'])
        ->map(static fn (object $row): array => (array) $row)
        ->all();
    $expectedTotal = count($officeIds) * count($moduleCodes);

    if (count($rows) !== $expectedTotal) {
        $fail("Expected {$expectedTotal} module rows, found ".count($rows).'.');
    }

    foreach ($officeIds as $officeId) {
        $officeRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) $row['gabinete_id'] === (int) $officeId,
        ));
        $codes = array_column($officeRows, 'modulo');
        sort($codes);
        $expectedCodes = $moduleCodes;
        sort($expectedCodes);

        if ($codes !== $expectedCodes) {
            $fail("Office {$officeId} does not have the exact module catalog.");
        }

        foreach ($officeRows as $row) {
            if ((int) $row['ativo'] !== 1 || $row['administrador_id'] !== null) {
                $fail("Office {$officeId} has an invalid backfill row.");
            }
        }
    }

    $duplicates = DB::table('gabinete_modulos')
        ->select('gabinete_id', 'modulo')
        ->groupBy('gabinete_id', 'modulo')
        ->havingRaw('COUNT(*) > 1')
        ->count();

    if ($duplicates !== 0) {
        $fail('Duplicate module rows were created.');
    }

    if (DB::table('gabinete_modulo_eventos')->count() !== 0) {
        $fail('The migration backfill must not create administrative audit events.');
    }

    return $rows;
};

$firstSnapshot = $assertState();
$migration->up();
$secondSnapshot = $assertState();

if ($firstSnapshot !== $secondSnapshot) {
    $fail('Running the migration a second time changed the module rows.');
}

fwrite(STDOUT, sprintf(
    "MariaDB module backfill validated: %d offices, %d modules, %d rows.\n",
    count($officeIds),
    count($moduleCodes),
    count($secondSnapshot),
));
