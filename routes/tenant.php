<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AtendimentoController;
use App\Http\Controllers\BairroController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CidadaoController;
use App\Http\Controllers\CitizenLocationController;
use App\Http\Controllers\DemandaController;
use App\Http\Controllers\DemandAttachmentController;
use App\Http\Controllers\DemandReferralController;
use App\Http\Controllers\DemandUpdateController;
use App\Http\Controllers\ElectoralHeatmapController;
use App\Http\Controllers\EventoController;
use App\Http\Controllers\GabineteSettingsController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PoliticalPanelController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\VoterProspectingMapController;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::bind('usuario', function (string $value): User {
    $unitParameter = request()->route('gabinete');
    $unitId = $unitParameter instanceof Gabinete
        ? $unitParameter->id
        : (is_string($unitParameter)
            ? Gabinete::withoutGlobalScopes()
                ->where(fn ($query) => $query->where('slug', $unitParameter)
                    ->when(ctype_digit($unitParameter), fn ($query) => $query->orWhere('id', (int) $unitParameter)))
                ->value('id')
            : request()->user()?->gabinete_id);

    return User::query()
        ->whereHas('gabinetes', fn ($query) => $query->where('gabinete_id', $unitId))
        ->findOrFail($value);
});

$registerTenantRoutes = static function (): void {
    Route::patch('notificacoes/{notification}/ler', [NotificationController::class, 'read'])->name('notifications.read');
    Route::patch('notificacoes/ler-todas', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::middleware('module:AGENDA')->group(function () {
        Route::get('agenda', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::get('agenda/novo', [AppointmentController::class, 'create'])->name('appointments.create');
        Route::post('agenda', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::put('agenda/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
        Route::patch('agenda/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status');
        Route::patch('agenda/{appointment}/cancelar', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::delete('agenda/{appointment}', [AppointmentController::class, 'destroy'])->name('appointments.destroy');
    });

    Route::middleware('module:RELATORIOS')->group(function () {
        Route::get('relatorios', [ReportController::class, 'index'])->name('reports.index');
        Route::post('relatorios/exportacoes', [ReportController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('reports.exports.store');
        Route::get('relatorios/exportacoes/{reportExport}/download', [ReportController::class, 'download'])
            ->name('reports.exports.download');
    });

    Route::middleware('module:DEMANDAS')->group(function () {
        Route::get('demandas/kanban', [DemandaController::class, 'kanban'])->name('demands.kanban');
        Route::patch('demandas/{demanda}/kanban-status', [DemandaController::class, 'transitionFromKanban'])->name('demands.kanban.transition');
        Route::patch('demandas/{demanda}/status', [DemandaController::class, 'transition'])->name('demands.transition');
        Route::patch('demandas/{demanda}/resolver', [DemandaController::class, 'resolve'])->name('demands.resolve');
        Route::patch('demandas/{demanda}/encerrar', [DemandaController::class, 'close'])->name('demands.close');
        Route::patch('demandas/{demanda}/reabrir', [DemandaController::class, 'reopen'])->name('demands.reopen');
        Route::post('demandas/{demanda}/proxima-acao', [DemandaController::class, 'setNextAction'])->name('demands.next-action.store');
        Route::patch('demandas/{demanda}/proxima-acao/concluir', [DemandaController::class, 'completeNextAction'])->name('demands.next-action.complete');
        Route::patch('demandas/{demanda}/favorito', [DemandaController::class, 'toggleFavorite'])->name('demands.favorite.toggle');
        Route::post('demandas/{demanda}/atualizacoes', [DemandUpdateController::class, 'store'])->name('demands.updates.store');
        Route::post('demandas/{demanda}/encaminhamentos', [DemandReferralController::class, 'store'])->name('demands.referrals.store');
        Route::post('demandas/{demanda}/retornos', [DemandReferralController::class, 'respond'])->name('demands.referrals.respond');
        Route::post('demandas/{demanda}/anexos', [DemandAttachmentController::class, 'store'])->name('demands.attachments.store');
        Route::get('demandas/{demanda}/anexos/{anexo}/download', [DemandAttachmentController::class, 'download'])->name('demands.attachments.download');
        Route::get('demandas/{demanda}/anexos/{anexo}/preview', [DemandAttachmentController::class, 'preview'])->name('demands.attachments.preview');
        Route::delete('demandas/{demanda}/anexos/{anexo}', [DemandAttachmentController::class, 'destroy'])->name('demands.attachments.destroy');
        Route::resource('demandas', DemandaController::class)
            ->parameters(['demandas' => 'demanda'])
            ->names('demands');

        Route::get('categorias', [CategoriaController::class, 'index'])->name('categories.index');
        Route::post('categorias', [CategoriaController::class, 'store'])->name('categories.store');
        Route::put('categorias/{categoria}', [CategoriaController::class, 'update'])->name('categories.update');
        Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categories.destroy');
    });

    Route::middleware('module:ATENDIMENTOS')->group(function () {
        Route::resource('atendimentos', AtendimentoController::class)
            ->parameters(['atendimentos' => 'atendimento'])
            ->names('attendances');
    });

    Route::middleware('module:EVENTOS')->group(function () {
        Route::get('eventos/participantes/cidadaos', [EventoController::class, 'citizenParticipants'])
            ->name('events.participants.citizens');
        Route::resource('eventos', EventoController::class)
            ->parameters(['eventos' => 'evento'])
            ->names('events');
    });

    Route::middleware('module:POLITICA')->group(function () {
        Route::get('painel-politico', [PoliticalPanelController::class, 'index'])->name('politics.index');
        Route::post('painel-politico/favoritos/{candidate}', [PoliticalPanelController::class, 'favorite'])
            ->name('politics.favorites.store');
        Route::delete('painel-politico/favoritos/{candidate}', [PoliticalPanelController::class, 'unfavorite'])
            ->name('politics.favorites.destroy');
        Route::get('painel-politico/candidatos/{candidate}/noticias', [PoliticalPanelController::class, 'news'])
            ->name('politics.candidates.news');
        Route::get('eleitores/mapa', ElectoralHeatmapController::class)
            ->name('voters.map');
        Route::get('eleitores/prospeccao', VoterProspectingMapController::class)
            ->name('voters.prospecting-map');
    });

    Route::middleware('module:RELACIONAMENTO')->group(function () {
        Route::get('cidadaos/localizacao/buscar', CitizenLocationController::class)
            ->middleware('throttle:geocoding')
            ->name('citizens.location.search');
        Route::resource('cidadaos', CidadaoController::class)
            ->parameters(['cidadaos' => 'cidadao'])
            ->names('citizens');

        Route::get('bairros', [BairroController::class, 'index'])->name('neighborhoods.index');
        Route::post('bairros', [BairroController::class, 'store'])->name('neighborhoods.store');
        Route::post('bairros/referencias/{reference}', [BairroController::class, 'importReference'])
            ->name('neighborhoods.references.import');
        Route::put('bairros/{bairro}', [BairroController::class, 'update'])->name('neighborhoods.update');
        Route::delete('bairros/{bairro}', [BairroController::class, 'destroy'])->name('neighborhoods.destroy');
    });

    Route::middleware('module:BASE_CONHECIMENTO')->group(function () {
        Route::get('conhecimento', [KnowledgeBaseController::class, 'index'])->name('knowledge.index');
        Route::post('conhecimento', [KnowledgeBaseController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('knowledge.store');
        Route::get('conhecimento/{documento}', [KnowledgeBaseController::class, 'show'])->name('knowledge.show');
        Route::get('conhecimento/{documento}/arquivo', [KnowledgeBaseController::class, 'file'])
            ->name('knowledge.file');
        Route::post('conhecimento/{documento}/leitura', [KnowledgeBaseController::class, 'progress'])
            ->middleware('throttle:120,1')
            ->name('knowledge.progress');
        Route::delete('conhecimento/{documento}', [KnowledgeBaseController::class, 'destroy'])
            ->name('knowledge.destroy');
    });

    Route::get('equipe', [TeamController::class, 'index'])->name('team.index');
    Route::post('equipe', [TeamController::class, 'store'])->name('team.store');
    Route::put('equipe/{usuario}', [TeamController::class, 'update'])->name('team.update');
    Route::put('equipe/{usuario}/senha', [TeamController::class, 'resetPassword'])->name('team.password.update');
    Route::delete('equipe/{usuario}', [TeamController::class, 'destroy'])->name('team.destroy');

    Route::get('configuracoes/gabinete', [GabineteSettingsController::class, 'edit'])->name('office-settings.edit');
    Route::put('configuracoes/gabinete', [GabineteSettingsController::class, 'update'])->name('office-settings.update');
};

Route::middleware(['auth', 'verified', 'user.active', 'tenant.legacy', 'gabinete.active', 'tenant.user'])
    ->group($registerTenantRoutes);

Route::prefix('entidades/{entidade}/gabinetes/{gabinete}')
    ->name('context.')
    ->middleware(['auth', 'verified', 'user.active', 'entidade.context', 'gabinete.active', 'tenant.user'])
    ->group($registerTenantRoutes);
