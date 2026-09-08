<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Enums\DemandStatus;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use App\Models\User;
use App\Services\Demands\DemandAttachmentService;
use App\Services\Demands\DemandTimelineRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Um encaminhamento é um único evento de timeline com metadados estruturados
 * (destino, setor, referência, prazo esperado) — não existe mais um status
 * próprio de encaminhamento nem um sub-workflow de acompanhamento.
 */
class CreateReferral
{
    public function __construct(
        private readonly DemandTimelineRecorder $timeline,
        private readonly DemandAttachmentService $attachments,
        private readonly TransitionDemandStatus $transition,
    ) {}

    /** @param array<string, mixed> $data
     * @param  list<UploadedFile>  $files
     */
    public function handle(Demanda $demand, array $data, User $user, array $files = []): DemandaEvento
    {
        return DB::transaction(function () use ($demand, $data, $user, $files): DemandaEvento {
            $event = $this->timeline->record(
                $demand,
                $user,
                DemandEventType::Encaminhamento,
                $data['descricao'] ?? null,
                null,
                [
                    'destino' => $data['destino'],
                    'setor' => $data['setor'] ?? null,
                    'referencia_externa' => $data['referencia_externa'] ?? null,
                    'prazo_esperado' => $data['prazo_esperado'] ?? null,
                ],
            );

            if ($files !== []) {
                $this->attachments->store($demand, $files, $user, $event);
            }

            // Regra simples e previsível: registrar um encaminhamento é uma ação
            // clara de "estou tratando isso", então uma demanda ainda Nova avança
            // para Em andamento automaticamente. Não tenta adivinhar mais nada.
            if ($demand->fresh()->status === DemandStatus::New) {
                $this->transition->handle(
                    $demand,
                    DemandStatus::InProgress,
                    $user,
                    DemandEventType::StatusAlterado,
                    'Movida automaticamente para Em andamento após o registro de um encaminhamento.',
                );
            }

            return $event;
        });
    }
}
