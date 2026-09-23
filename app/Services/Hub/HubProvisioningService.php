<?php

namespace App\Services\Hub;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use RuntimeException;

/**
 * Cria e atualiza a cópia local da pessoa a partir do Hub.
 *
 * A tabela `users` não pode desaparecer com a integração: ela é chave
 * estrangeira de demanda, compromisso, atendimento e auditoria. O que muda é
 * quem manda nela — o Hub — e o fato de que nada aqui exclui conta, só desativa.
 */
class HubProvisioningService
{
    public function __construct(
        private readonly GovnexHubApiClient $api,
        private readonly HubVinculoSyncService $vinculos,
    ) {}

    /**
     * Fluxo do login por SSO: casa a conta, atualiza o perfil e aplica os
     * vínculos que o Hub declara agora.
     *
     * @throws RuntimeException quando a conta está desativada ou há divergência
     *                          de e-mail que exija revisão manual
     */
    public function provisionarDoLogin(SocialiteUser $hubUser): User
    {
        $hubUserId = trim((string) $hubUser->getId());

        if ($hubUserId === '') {
            throw new RuntimeException('O Govnex Hub não informou o identificador da pessoa.');
        }

        $user = $this->sincronizarPessoa([
            'id' => $hubUserId,
            'nome' => $hubUser->getName(),
            'email' => $hubUser->getEmail(),
            // Quem chegou até aqui foi autenticado pelo Hub. Exigir uma
            // segunda verificação de e-mail no GAB (as rotas internas passam
            // por `verified`) só prenderia a pessoa numa tela de confirmação
            // logo depois de ela provar quem é.
            'email_verificado' => true,
        ]);

        if ($user === null) {
            throw new RuntimeException('Não foi possível provisionar a conta a partir do Govnex Hub.');
        }

        if (! $user->is_active) {
            throw new RuntimeException('Esta conta está desativada no GOVNEX GAB.');
        }

        // Uma queda do Hub entre o token e esta chamada não pode barrar o
        // login: a pessoa já foi autenticada, e os vínculos que o GAB tem
        // valem até o próximo aviso (decisão #10). Só o login novo depende do
        // Hub estar no ar, não a exatidão momentânea dos vínculos.
        try {
            $acesso = $this->api->acessoDaPessoa($hubUserId);
            $this->vinculos->aplicar($user, $acesso['vinculos']);
        } catch (RuntimeException $e) {
            Log::warning('Vínculos do Hub não puderam ser lidos no login; mantendo os locais.', [
                'hub_user_id' => $hubUserId,
                'erro' => $e->getMessage(),
            ]);
        }

        return $user->refresh();
    }

    /**
     * Espelha a pessoa do Hub em `users`, casando pelo `hub_user_id` e, na
     * falta dele, pelo e-mail.
     *
     * O casamento por e-mail é a carga inicial acontecendo sozinha, no momento
     * do primeiro acesso (decisão #7): a conta que já existe no GAB recebe o
     * `hub_user_id` e segue com todo o histórico. Só vale para conta ainda sem
     * ponte — um e-mail que já pertence a outra pessoa do Hub é divergência de
     * cadastro e precisa de gente, não de heurística.
     *
     * @param  array<string, mixed>  $pessoa  bloco `dados.pessoa` do webhook ou
     *                                        as claims do login
     */
    public function sincronizarPessoa(array $pessoa, bool $criarSeNaoExistir = true): ?User
    {
        $hubUserId = trim((string) ($pessoa['id'] ?? ''));

        if ($hubUserId === '') {
            return null;
        }

        $email = Str::lower(trim((string) ($pessoa['email'] ?? '')));
        $user = User::withTrashed()->where('hub_user_id', $hubUserId)->first();

        if ($user === null && $email !== '') {
            $porEmail = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($porEmail !== null && $porEmail->hub_user_id !== null) {
                throw new RuntimeException(
                    "O e-mail {$email} já está vinculado a outra pessoa do Govnex Hub. Resolva a duplicidade no Hub antes de seguir.",
                );
            }

            $user = $porEmail;
        }

        if ($user === null) {
            if (! $criarSeNaoExistir) {
                return null;
            }

            $user = new User;
            $user->forceFill([
                // Conta que nasce pelo Hub não tem senha utilizável: quem
                // autentica é o Hub. Não deixamos o campo vazio porque ele é
                // obrigatório no schema e um hash de valor aleatório nunca
                // casa com nada.
                'password' => Str::password(64),
                'role' => UserRole::Operator,
                'is_active' => true,
            ]);
        }

        // `ativo` só é reescrito quando o bloco recebido fala dele. As claims
        // do login não falam — e assumir "ativo" ali reabriria, no primeiro
        // acesso, toda conta desativada aqui dentro. Quem desliga alguém é o
        // Hub, por webhook, ou o próprio GAB; nunca a ausência de um campo.
        $ativo = array_key_exists('ativo', $pessoa)
            ? (filter_var($pessoa['ativo'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true)
            : ($user->exists ? (bool) $user->is_active : true);

        $user->forceFill([
            'hub_user_id' => $hubUserId,
            'name' => trim((string) ($pessoa['nome'] ?? $user->name ?? '')) ?: ($user->name ?? 'Sem nome'),
            'email' => $email !== '' ? $email : $user->email,
            'is_active' => $ativo,
        ]);

        $verificado = filter_var($pessoa['email_verificado'] ?? false, FILTER_VALIDATE_BOOL);

        if ($verificado && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()]);
        }

        if ($user->trashed() && $ativo) {
            // O GAB nunca exclui de verdade; se o Hub reativa a pessoa, a conta
            // volta com o histórico dela em vez de nascer de novo.
            $user->restore();
        }

        $user->saveQuietly();

        return $user;
    }
}
