<?php

declare(strict_types=1);

use App\Enums\EntidadeModule;
use App\Enums\GabineteType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.mysql.database');

if (! app()->environment('testing') || $database !== 'govnexgab_multientidade_test') {
    fwrite(STDERR, "Refusing to alter a database outside the dedicated multi-entidade validation environment.\n");
    exit(1);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
};

// 1. Toda gabinete pré-existente recebeu uma entidade própria (gate
//    "confirme uma entidade independente e uma gabinete por gabinete
//    preexistente" de docs/DEPLOY.md).
$offices = DB::table('gabinetes')->orderBy('id')->get(['id', 'entidade_id', 'tipo_gabinete']);

if ($offices->isEmpty()) {
    $fail('The seed must create at least one office before testing the backfill.');
}

foreach ($offices as $office) {
    if ($office->entidade_id === null) {
        $fail("Office {$office->id} was not linked to an entidade by the backfill.");
    }

    if ($office->tipo_gabinete !== GabineteType::IndependentOffice->value) {
        $fail("Office {$office->id} did not receive the independent-office gabinete type.");
    }
}

$entidadeCount = DB::table('entidades')->count();

if ($entidadeCount !== $offices->count()) {
    $fail("Expected {$offices->count()} entidades (one per pre-existing office), found {$entidadeCount}.");
}

$duplicateOrigins = DB::table('entidades')
    ->select('gabinete_origem_id')
    ->whereNotNull('gabinete_origem_id')
    ->groupBy('gabinete_origem_id')
    ->havingRaw('COUNT(*) > 1')
    ->count();

if ($duplicateOrigins !== 0) {
    $fail('More than one entidade was backfilled from the same pre-existing office.');
}

// 2. Vínculos equivalentes para usuários ativos, sem acesso cruzado entre
//    gabinetes (gate "confirme vínculos equivalentes... e ausência de acesso
//    cruzado").
$users = DB::table('users')->whereNotNull('gabinete_id')->whereNull('deleted_at')->get(['id', 'gabinete_id', 'is_active']);

foreach ($users as $user) {
    $office = $offices->firstWhere('id', $user->gabinete_id);

    if (! $office) {
        continue;
    }

    $gabineteMembership = DB::table('gabinete_membros')
        ->where('gabinete_id', $user->gabinete_id)
        ->where('usuario_id', $user->id)
        ->first();

    if (! $gabineteMembership) {
        $fail("User {$user->id} has no gabinete_membros row equivalent to its legacy gabinete_id.");
    }

    if ((bool) $gabineteMembership->ativo !== (bool) $user->is_active) {
        $fail("User {$user->id} gabinete membership status diverges from users.is_active.");
    }

    $orgMembership = DB::table('entidade_membros')
        ->where('entidade_id', $office->entidade_id)
        ->where('usuario_id', $user->id)
        ->first();

    if (! $orgMembership) {
        $fail("User {$user->id} has no entidade_membros row for its office's entidade.");
    }

    // Sem acesso cruzado: o usuário não pode ter vínculo de gabinete em
    // nenhum outro gabinete além do seu próprio.
    $crossOfficeMembership = DB::table('gabinete_membros')
        ->where('usuario_id', $user->id)
        ->where('gabinete_id', '!=', $user->gabinete_id)
        ->exists();

    if ($crossOfficeMembership) {
        $fail("User {$user->id} has a gabinete membership in an office other than its own gabinete_id.");
    }
}

// 3. Planos/licenças compatíveis com todos os módulos institucionais atuais
//    disponíveis (gate "confirme planos/licenças compatíveis, todos os
//    módulos atuais disponíveis").
$moduleCodes = DB::table('entidade_modulos')
    ->select('modulo')
    ->distinct()
    ->pluck('modulo')
    ->sort()
    ->values()
    ->all();
$expectedModuleCodes = array_column(EntidadeModule::cases(), 'value');
sort($expectedModuleCodes);

if ($moduleCodes !== $expectedModuleCodes) {
    $fail('Backfilled entidades do not expose the full institutional module catalog: '.implode(',', $moduleCodes));
}

foreach ($offices as $office) {
    $license = DB::table('entidade_licencas')
        ->where('entidade_id', $office->entidade_id)
        ->where('status', 'ativa')
        ->first();

    if (! $license) {
        $fail("Entidade {$office->entidade_id} has no active license after the backfill.");
    }

    $activeModuleCount = DB::table('entidade_modulos')
        ->where('entidade_id', $office->entidade_id)
        ->where('contratado', 1)
        ->where('ativo', 1)
        ->count();

    if ($activeModuleCount !== count($expectedModuleCodes)) {
        $fail("Entidade {$office->entidade_id} does not have every institutional module active.");
    }
}

// 4. O backfill não deve criar nenhuma mensagem/conteúdo institucional
//    externo (gate "nenhuma mensagem externa criada pelo backfill").
foreach ([
    'entidade_convites',
    'contexto_acesso_eventos',
    'comunicados_institucionais',
    'solicitacoes_institucionais',
    'gabinete_transferencias',
] as $table) {
    $count = DB::table($table)->count();

    if ($count !== 0) {
        $fail("Table {$table} should be empty right after a fresh backfill, found {$count} row(s).");
    }
}

// 5. Bairros ligados à entidade sem mover cidadãos (gate "valide a
//    ligação de bairros de entidade sem mover cidadãos").
$neighborhoods = DB::table('bairros')->get(['id', 'gabinete_id', 'entidade_bairro_id']);

foreach ($neighborhoods as $neighborhood) {
    if ($neighborhood->entidade_bairro_id === null) {
        $fail("Bairro {$neighborhood->id} was not linked to an entidade_bairros reference.");
    }
}

$citizenNeighborhoodColumnUnchanged = DB::table('cidadaos')
    ->select('bairro_id')
    ->whereNotNull('bairro_id')
    ->whereNotIn('bairro_id', $neighborhoods->pluck('id'))
    ->exists();

if ($citizenNeighborhoodColumnUnchanged) {
    $fail('A citizen references a bairro_id that no longer exists — the backfill must not move citizens.');
}

fwrite(STDOUT, sprintf(
    "MariaDB multi-entidade backfill validated: %d offices, %d entidades, %d users, %d bairros.\n",
    $offices->count(),
    $entidadeCount,
    $users->count(),
    $neighborhoods->count(),
));
