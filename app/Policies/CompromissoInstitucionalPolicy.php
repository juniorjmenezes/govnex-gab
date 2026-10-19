<?php

namespace App\Policies;

use App\Enums\GabineteType;
use App\Models\CompromissoInstitucional;
use App\Models\Gabinete;
use App\Models\User;

class CompromissoInstitucionalPolicy
{
    /**
     * Mesma regra da agenda (`CompromissoInstitucional::visibleTo()`): mesma
     * entidade e, havendo alvos, o gabinete na lista — ou quem publicou.
     */
    public function view(User $user, CompromissoInstitucional $compromisso): bool
    {
        $gabinete = $this->gabinete($user);

        return $gabinete !== null
            && CompromissoInstitucional::query()
                ->whereKey($compromisso->id)
                ->visibleTo($gabinete)
                ->exists();
    }

    /** Só a Secretaria legislativa publica, e o auditor só lê. */
    public function create(User $user): bool
    {
        return $this->gabinete($user)?->tipo_gabinete === GabineteType::LegislativeSecretariat
            && $user->role->canWrite();
    }

    /**
     * A Secretaria altera só o que ela publicou — na prática, tudo da Câmara
     * dela, já que há uma por Câmara.
     */
    public function update(User $user, CompromissoInstitucional $compromisso): bool
    {
        return $this->create($user)
            && $compromisso->isPublishedBy($user->gabinete_id)
            && $compromisso->entidade_id === $this->gabinete($user)?->entidade_id;
    }

    public function delete(User $user, CompromissoInstitucional $compromisso): bool
    {
        return $this->update($user, $compromisso);
    }

    /**
     * Convocar por WhatsApp a equipe dos gabinetes do público: a mesma
     * Secretaria que pode alterar o compromisso. Se o compromisso ainda cabe
     * convocação (não cancelado, não encerrado) é regra de negócio, conferida
     * no controller — aqui fica só quem pode.
     */
    public function broadcastWhatsApp(User $user, CompromissoInstitucional $compromisso): bool
    {
        return $this->update($user, $compromisso);
    }

    /**
     * O gabinete em contexto (`users.gabinete_id`). Lido sem o escopo global
     * para a regra não depender de haver contexto resolvido (ex.: checagem
     * fora de uma requisição) — o id já é o do próprio usuário.
     */
    private function gabinete(User $user): ?Gabinete
    {
        if ($user->gabinete_id === null) {
            return null;
        }

        $gabinete = $user->gabinete;

        return $gabinete?->id === $user->gabinete_id
            ? $gabinete
            : Gabinete::withoutGlobalScopes()->find($user->gabinete_id);
    }
}
