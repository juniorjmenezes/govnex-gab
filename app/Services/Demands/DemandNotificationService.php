<?php

namespace App\Services\Demands;

use App\Enums\DemandStatus;
use App\Enums\GabineteModule;
use App\Enums\UserRole;
use App\Models\Demanda;
use App\Models\User;
use App\Notifications\DemandActivityNotification;
use App\Services\Modules\GabineteModuleManager;
use App\Services\WhatsApp\WhatsAppEventNotificationService;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Gatilhos de notificação da demanda. Deliberadamente curto: a timeline
 * registra tudo que aconteceu, mas só uma fração disso deve interromper
 * alguém — atribuição, próxima ação e prazos. Notificar a cada evento da
 * timeline (cada atualização, cada encaminhamento) viraria ruído.
 */
class DemandNotificationService
{
    public function __construct(
        private readonly WhatsAppEventNotificationService $whatsApp,
        private readonly GabineteModuleManager $modules,
    ) {}

    public function assigned(Demanda $demand, ?int $previousResponsibleId = null): void
    {
        if (! $this->modules->isActive($demand->gabinete_id, GabineteModule::Demands)) {
            return;
        }
        if (! $demand->responsavel_id || $demand->responsavel_id === $previousResponsibleId) {
            return;
        }

        $recipient = User::query()->whereKey($demand->responsavel_id)->first();
        $this->sendOnce(
            $recipient,
            "demand:{$demand->id}:assigned:{$demand->responsavel_id}",
            'Nova demanda atribuída',
            "{$demand->protocolo} · {$demand->titulo}",
            $demand,
        );
        if ($recipient) {
            $this->whatsApp->demandAssigned($demand, $recipient);
        }
    }

    public function nextActionAssigned(Demanda $demand, User $definedBy): void
    {
        if (! $this->modules->isActive($demand->gabinete_id, GabineteModule::Demands)) {
            return;
        }
        $responsibleId = $demand->proxima_acao_responsavel_id;
        if (! $responsibleId || $responsibleId === $definedBy->id) {
            return;
        }

        $this->sendOnce(
            User::query()->whereKey($responsibleId)->first(),
            "demand:{$demand->id}:next-action:".now()->format('YmdHisv'),
            'Próxima ação atribuída a você',
            "{$demand->protocolo} · {$demand->proxima_acao_descricao}",
            $demand,
        );
    }

    /** Comunica qualquer mudança de status ao responsável (interno) e ao cidadão (WhatsApp). */
    public function statusChanged(Demanda $demand, DemandStatus $status): void
    {
        if (! $this->modules->isActive($demand->gabinete_id, GabineteModule::Demands)) {
            return;
        }
        $recipient = $demand->responsavel_id
            ? User::query()->whereKey($demand->responsavel_id)->first()
            : null;

        $this->sendOnce(
            $recipient,
            "demand:{$demand->id}:status:{$status->value}:".now()->format('YmdHi'),
            'Status alterado',
            "{$demand->protocolo} agora está como {$status->label()}.",
            $demand,
        );
        $this->whatsApp->demandStatusChanged($demand);
    }

    public function updateAdded(Demanda $demand, User $author): void
    {
        if (! $this->modules->isActive($demand->gabinete_id, GabineteModule::Demands)) {
            return;
        }
        if (! $demand->responsavel_id || $demand->responsavel_id === $author->id) {
            return;
        }

        $this->sendOnce(
            User::query()->whereKey($demand->responsavel_id)->first(),
            "demand:{$demand->id}:update:".now()->format('YmdHisv'),
            'Nova atualização',
            "{$author->name} adicionou uma atualização em {$demand->protocolo}.",
            $demand,
        );
        $this->whatsApp->demandUpdateAdded($demand, $author);
    }

    /** Job agendado: prazo da demanda e próxima ação chegando/atrasados. */
    public function dispatchAttention(): void
    {
        $officeIds = $this->modules->activeOfficeIds(GabineteModule::Demands);
        if ($officeIds === []) {
            return;
        }

        Demanda::withoutGlobalScopes()
            ->whereIn('gabinete_id', $officeIds)
            ->whereNotNull('prazo')
            ->whereIn('status', DemandStatus::openValues())
            ->where('prazo', '<=', now()->addDay())
            ->with('responsavel')
            ->chunkById(200, function ($demands): void {
                foreach ($demands as $demand) {
                    $overdue = $demand->prazo->isPast();
                    foreach ($this->recipients($demand) as $recipient) {
                        $this->sendOnce(
                            $recipient,
                            "demand:{$demand->id}:deadline:".$demand->prazo->toDateString().':'.($overdue ? 'overdue' : 'soon'),
                            $overdue ? 'Demanda atrasada' : 'Prazo próximo',
                            "{$demand->protocolo} · {$demand->titulo}",
                            $demand,
                        );
                    }
                }
            });

        Demanda::withoutGlobalScopes()
            ->whereIn('gabinete_id', $officeIds)
            ->whereNotNull('proxima_acao_data')
            ->whereNull('proxima_acao_concluida_em')
            ->where('proxima_acao_data', '<=', now()->addDay())
            ->with('proximaAcaoResponsavel')
            ->chunkById(200, function ($demands): void {
                foreach ($demands as $demand) {
                    $recipient = $demand->proximaAcaoResponsavel ?? $demand->responsavel;
                    if (! $recipient) {
                        continue;
                    }
                    $overdue = $demand->proxima_acao_data->isPast();
                    $this->sendOnce(
                        $recipient,
                        "demand:{$demand->id}:next-action:".$demand->proxima_acao_data->toDateString().':'.($overdue ? 'overdue' : 'today'),
                        $overdue ? 'Próxima ação atrasada' : 'Próxima ação para hoje',
                        "{$demand->protocolo} · {$demand->proxima_acao_descricao}",
                        $demand,
                    );
                }
            });
    }

    /** @return array<int, User> */
    private function recipients(Demanda $demand): array
    {
        if ($demand->responsavel) {
            return [$demand->responsavel];
        }

        return User::query()
            ->where('gabinete_id', $demand->gabinete_id)
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Councilor, UserRole::ChiefOfStaff])
            ->get()
            ->values()
            ->all();
    }

    private function sendOnce(
        ?User $recipient,
        string $key,
        string $title,
        string $message,
        Demanda $demand,
    ): void {
        if (! $recipient || $recipient->gabinete_id !== $demand->gabinete_id) {
            return;
        }

        $exists = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $recipient->id)
            ->where('data', 'like', '%"key":"'.$key.'"%')
            ->exists();

        if (! $exists) {
            $recipient->notify(new DemandActivityNotification($key, $title, $message, $demand->id));
        }
    }
}
