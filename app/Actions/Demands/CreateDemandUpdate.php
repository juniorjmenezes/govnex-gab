<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use App\Models\User;
use App\Services\Demands\DemandAttachmentService;
use App\Services\Demands\DemandNotificationService;
use App\Services\Demands\DemandTimelineRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * "Adicionar atualização" substitui o antigo conceito de observação: texto
 * livre, opcionalmente com anexos, sempre interno ao gabinete, sempre um
 * evento de timeline — nunca um sub-workflow próprio.
 */
class CreateDemandUpdate
{
    public function __construct(
        private readonly DemandTimelineRecorder $timeline,
        private readonly DemandAttachmentService $attachments,
        private readonly DemandNotificationService $notifications,
    ) {}

    /** @param list<UploadedFile> $files */
    public function handle(Demanda $demand, User $user, string $texto, array $files = []): DemandaEvento
    {
        return DB::transaction(function () use ($demand, $user, $texto, $files): DemandaEvento {
            $event = $this->timeline->record($demand, $user, DemandEventType::Atualizacao, $texto);

            if ($files !== []) {
                $this->attachments->store($demand, $files, $user, $event);
            }

            $demand->refresh();
            $this->notifications->updateAdded($demand, $user);

            return $event;
        });
    }
}
