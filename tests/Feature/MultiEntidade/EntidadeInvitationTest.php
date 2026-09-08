<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\EntidadeInvitationStatus;
use App\Enums\EntidadeRole;
use App\Enums\GabineteRole;
use App\Models\EntidadeConvite;
use App\Models\Gabinete;
use App\Models\User;
use App\Notifications\EntidadeInvitationNotification;
use App\Services\Entidades\EntidadeInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EntidadeInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_invitation_is_hashed_notified_and_accepted_once(): void
    {
        Notification::fake();
        $office = Gabinete::factory()->create();
        $actor = User::factory()->councilor()->forGabinete($office)->create();
        $service = app(EntidadeInvitationService::class);

        $result = $service->invite(
            $office->entidade,
            $office,
            'novo@example.test',
            EntidadeRole::Operator,
            GabineteRole::Member,
            $actor,
        );

        $this->assertNotSame($result['credential'], $result['invitation']->token_hash);
        $this->assertSame(hash('sha256', $result['credential']), $result['invitation']->token_hash);
        Notification::assertSentOnDemand(EntidadeInvitationNotification::class);

        $user = $service->accept(
            $result['credential'],
            null,
            'Novo Integrante',
            'Senha-muito-segura-123',
        );

        $this->assertDatabaseHas('entidade_membros', [
            'entidade_id' => $office->entidade_id,
            'usuario_id' => $user->id,
            'papel' => EntidadeRole::Operator->value,
        ]);
        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $office->id,
            'usuario_id' => $user->id,
            'papel' => GabineteRole::Member->value,
        ]);
        $this->assertSame(EntidadeInvitationStatus::Accepted, $result['invitation']->fresh()->status);

        $this->expectException(ValidationException::class);
        $service->accept($result['credential'], $user);
    }

    public function test_existing_account_must_authenticate_as_the_invited_user(): void
    {
        Notification::fake();
        $office = Gabinete::factory()->create();
        $actor = User::factory()->councilor()->forGabinete($office)->create();
        $existing = User::factory()->create(['email' => 'existente@example.test']);
        $service = app(EntidadeInvitationService::class);
        $result = $service->invite(
            $office->entidade,
            $office,
            $existing->email,
            EntidadeRole::Manager,
            GabineteRole::Manager,
            $actor,
        );

        try {
            $service->accept($result['credential'], null);
            $this->fail('Uma conta existente não deveria ser vinculada sem autenticação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $accepted = $service->accept($result['credential'], $existing);
        $this->assertSame($existing->id, $accepted->id);
    }

    public function test_expired_invitation_is_rejected_and_marked_expired(): void
    {
        Notification::fake();
        $office = Gabinete::factory()->create();
        $actor = User::factory()->councilor()->forGabinete($office)->create();
        $service = app(EntidadeInvitationService::class);
        $result = $service->invite(
            $office->entidade,
            null,
            'expirado@example.test',
            EntidadeRole::Auditor,
            null,
            $actor,
        );
        EntidadeConvite::query()->whereKey($result['invitation']->id)->update([
            'expira_em' => now()->subMinute(),
        ]);

        try {
            $service->accept($result['credential'], null, 'Teste', 'Senha-muito-segura-123');
            $this->fail('O convite expirado deveria ser rejeitado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invitation', $exception->errors());
        }

        $this->assertSame(
            EntidadeInvitationStatus::Expired,
            $result['invitation']->fresh()->status,
        );
    }

    public function test_invitation_rejects_unit_from_another_entidade(): void
    {
        Notification::fake();
        $first = Gabinete::factory()->create();
        $second = Gabinete::factory()->create();
        $actor = User::factory()->councilor()->forGabinete($first)->create();

        $this->expectException(ValidationException::class);
        app(EntidadeInvitationService::class)->invite(
            $first->entidade,
            $second,
            'fora@example.test',
            EntidadeRole::Operator,
            GabineteRole::Member,
            $actor,
        );
    }

    public function test_manager_cannot_grant_entidade_administrator_role(): void
    {
        Notification::fake();
        $office = Gabinete::factory()->create();
        $manager = User::factory()->chiefOfStaff()->forGabinete($office)->create();

        $this->actingAs($manager)
            ->from(route('entidades.show', $office->entidade))
            ->post(route('entidades.invitations.store', $office->entidade), [
                'email' => 'escalacao@example.test',
                'entidade_role' => EntidadeRole::Administrator->value,
                'gabinete_id' => null,
                'papel_gabinete' => null,
                'delivery_mode' => 'EMAIL',
            ])
            ->assertRedirect(route('entidades.show', $office->entidade))
            ->assertSessionHasErrors('entidade_role');

        $this->assertDatabaseMissing('entidade_convites', [
            'email' => 'escalacao@example.test',
        ]);
    }

    public function test_manager_cannot_grant_access_to_another_unit_without_management_role(): void
    {
        Notification::fake();
        $managedOffice = Gabinete::factory()->create();
        $otherOffice = Gabinete::factory()->for($managedOffice->entidade, 'entidade')->create();
        $manager = User::factory()->chiefOfStaff()->forGabinete($managedOffice)->create();

        $this->actingAs($manager)
            ->post(route('entidades.invitations.store', $managedOffice->entidade), [
                'email' => 'outro-gabinete@example.test',
                'entidade_role' => EntidadeRole::Operator->value,
                'gabinete_id' => $otherOffice->id,
                'papel_gabinete' => GabineteRole::Member->value,
                'delivery_mode' => 'EMAIL',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('entidade_convites', [
            'email' => 'outro-gabinete@example.test',
        ]);
    }

    public function test_generic_invitation_cannot_assign_office_leadership(): void
    {
        Notification::fake();
        $office = Gabinete::factory()->create();
        $administrator = User::factory()->councilor()->forGabinete($office)->create();

        $this->actingAs($administrator)
            ->from(route('entidades.show', $office->entidade))
            ->post(route('entidades.invitations.store', $office->entidade), [
                'email' => 'lider@example.test',
                'entidade_role' => EntidadeRole::Manager->value,
                'gabinete_id' => $office->id,
                'papel_gabinete' => GabineteRole::Leader->value,
                'delivery_mode' => 'EMAIL',
            ])
            ->assertRedirect(route('entidades.show', $office->entidade))
            ->assertSessionHasErrors('papel_gabinete');
    }
}
