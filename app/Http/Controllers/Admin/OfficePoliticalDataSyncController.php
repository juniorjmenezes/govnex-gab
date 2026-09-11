<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\GabineteModuleDisabledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelOfficePoliticalDataSyncRequest;
use App\Http\Requests\Admin\RestartOfficePoliticalDataSyncRequest;
use App\Http\Requests\Admin\SyncOfficePoliticalDataRequest;
use App\Models\Gabinete;
use App\Models\SincronizacaoTse;
use App\Services\Politics\OfficePoliticalDataSyncDispatcher;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use RuntimeException;

/**
 * Disparo, reinício e cancelamento do PollingData de um gabinete. A tela que
 * aciona essas rotas é a de sincronização política (PoliticalDataSyncController).
 */
class OfficePoliticalDataSyncController extends Controller
{
    public function store(
        SyncOfficePoliticalDataRequest $request,
        Gabinete $office,
        OfficePoliticalDataSyncDispatcher $dispatcher,
    ): RedirectResponse {
        $result = $dispatcher->queue(
            $office,
            $request->user(),
            $request->validated()['tasks'],
        );
        $runs = $result['runs'];
        $skipped = $result['skipped'];

        $message = $runs->isNotEmpty()
            ? 'Sincronização confirmada na fila. Acompanhe o progresso de cada etapa nesta tela.'
            : 'Nenhuma sincronização pôde ser preparada.';

        if ($skipped !== []) {
            $labels = array_map(
                fn (string $task): string => OfficePoliticalDataSyncDispatcher::TASK_LABELS[$task] ?? $task,
                $skipped,
            );
            $message .= ' Não foi possível preparar: '.implode(', ', $labels).
                ' (nenhuma eleição anterior cadastrada).';
        }

        Inertia::flash('toast', [
            'type' => $runs->isEmpty() ? 'error' : 'success',
            'message' => $message,
        ]);

        return back();
    }

    public function restart(
        RestartOfficePoliticalDataSyncRequest $request,
        Gabinete $office,
        SincronizacaoTse $sync,
        OfficePoliticalDataSyncDispatcher $dispatcher,
    ): RedirectResponse {
        try {
            $dispatcher->restart($sync, $office, $request->user());

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Sincronização reiniciada. Uma nova execução foi adicionada à fila.',
            ]);
        } catch (GabineteModuleDisabledException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    public function cancel(
        CancelOfficePoliticalDataSyncRequest $request,
        Gabinete $office,
        SincronizacaoTse $sync,
        OfficePoliticalDataSyncDispatcher $dispatcher,
    ): RedirectResponse {
        try {
            $dispatcher->cancel($sync, $office, $request->user());

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Sincronização cancelada.',
            ]);
        } catch (RuntimeException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }

        return back();
    }
}
