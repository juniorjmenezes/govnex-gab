<?php

namespace App\Actions\Demands;

use App\Enums\DemandEventType;
use App\Models\Demanda;
use App\Models\DemandaEvento;
use App\Models\User;
use App\Services\Demands\DemandAttachmentService;
use App\Services\Demands\DemandTimelineRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar retorno" é uma ação simples e independente — não um novo estado
 * de um encaminhamento. Pode opcionalmente referenciar o encaminhamento que
 * originou o retorno, só para fechar o prazo esperado dele; nunca altera o
 * status da demanda automaticamente.
 */
class RegisterReferralResponse
{
    public function __construct(
        private readonly DemandTimelineRecorder $timeline,
        private readonly DemandAttachmentService $attachments,
    ) {}

    /** @param list<UploadedFile> $files */
    public function handle(
        Demanda $demand,
        User $user,
        string $descricao,
        ?DemandaEvento $referral,
        array $files = [],
    ): DemandaEvento {
        return DB::transaction(function () use ($demand, $user, $descricao, $referral, $files): DemandaEvento {
            $event = $this->timeline->record(
                $demand,
                $user,
                DemandEventType::RetornoRecebido,
                $descricao,
                null,
                ['retorno_de_evento_id' => $referral?->id],
            );

            if ($referral !== null && $referral->retorno_recebido_em === null) {
                $referral->forceFill(['retorno_recebido_em' => now()])->save();
            }

            if ($files !== []) {
                $this->attachments->store($demand, $files, $user, $event);
            }

            return $event;
        });
    }
}
