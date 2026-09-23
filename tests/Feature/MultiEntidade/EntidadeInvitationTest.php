<?php

namespace Tests\Feature\MultiEntidade;

use App\Enums\AccessRole;
use App\Enums\EntidadeInvitationStatus;
use App\Enums\EntidadeType;
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
        $actor = User::factory()->administrator()->forGabinete($office)->create();
        $service = app(EntidadeInvitationService::class);

        $result = $service->invite(
            $office->entidade,
            $office,
            'novo@example.test',
            AccessRole::Operator,
            AccessRole::Operator,
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
            'papel' => AccessRole::Operator->value,
        ]);
        $this->assertDatabaseHas('gabinete_membros', [
            'gabinete_id' => $office->id,
            'usuario_id' => $user->id,
            'papel' => AccessRole::Operator->value,
        ]);
        $this->assertSame(EntidadeInvitationStatus::Accepted, $result['invitation']->fresh()->status);

        $this->expectException(ValidationException::class);
        $service->accept($result['credential'], $user);
    }

    public function test_existing_account_must_authenticate_as_the_invited_user(): void
    {
        Notification::fake();
        $office = Gabinete::factory()->create();
        $actor = User::factory()->administrator()->forGabinete($office)->create();
        $existing = User::factory()->create(['email' => 'existente@example.test']);
        $service = app(EntidadeInvitationService::class);
        $result = $service->invite(
            $office->entidade,
            $office,
            $existing->email,
            AccessRole::Administrator,
            AccessRole::Administrator,
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
        $actor = User::factory()->administrator()->forGabinete($office)->create();
        $service = app(EntidadeInvitationService::class);
        $result = $service->invite(
            $office->entidade,
            null,
            'expirado@example.test',
            AccessRole::Auditor,
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
        $actor = User::factory()->administrator()->forGabinete($first)->create();

        $this->expectException(ValidationException::class);
        app(EntidadeInvitationService::class)->invite(
            $first->entidade,
            $second,
            'fora@example.test',
            AccessRole::Operator,
            AccessRole::Operator,
            $actor,
        );
    }

    public function test_entidade_operator_cannot_invite(): void
    {
        Notification::fake();
        $office = Gabinete::factory()->create();
        $operator = User::factory()->operator()->forGabinete($office)->create();

        $this->actingAs($operator)
            ->post(route('entidades.invitations.store', $office->entidade), [
                'email' => 'escalacao@example.test',
                'entidade_role' => AccessRole::Administrator->value,
                'gabinete_id' => null,
                'papel_gabinete' => null,
                'delivery_mode' => 'EMAIL',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('entidade_convites', [
            'email' => 'escalacao@example.test',
        ]);
    }

    public function test_office_administrator_in_city_council_cannot_invite_at_entidade_level(): void
    {
        Notification::fake();
        $managedOffice = Gabinete::factory()->create();
        $managedOffice->entidade->forceFill(['tipo' => EntidadeType::CityCouncil])->save();
        $otherOffice = Gabinete::factory()->for($managedOffice->entidade, 'entidade')->create();
        $manager = User::factory()->administrator()->forGabinete($managedOffice)->create();

        $this->assertSame(AccessRole::Operator, $manager->entidadeRole($managedOffice->entidade_id));

        $this->actingAs($manager)
            ->post(route('entidades.invitations.store', $managedOffice->entidade), [
                'email' => 'outro-gabinete@example.test',
                'entidade_role' => AccessRole::Operator->value,
                'gabinete_id' => $otherOffice->id,
                'papel_gabinete' => AccessRole::Operator->value,
                'delivery_mode' => 'EMAIL',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('entidade_convites', [
            'email' => 'outro-gabinete@example.test',
        ]);
    }

    public function test_entidade_administrator_can_invite_an_office_administrator(): void
    {
        Notification::fake();
        $office = Gabinete::factory()->create();
        $administrator = User::factory()->administrator()->forGabinete($office)->create();

        $this->actingAs($administrator)
            ->from(route('entidades.show', $office->entidade))
            ->post(route('entidades.invitations.store', $office->entidade), [
                'email' => 'administrador@example.test',
                'entidade_role' => AccessRole::Administrator->value,
                'gabinete_id' => $office->id,
                'papel_gabinete' => AccessRole::Administrator->value,
                'delivery_mode' => 'EMAIL',
            ])
            ->assertRedirect(route('entidades.show', $office->entidade))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('entidade_convites', [
            'email' => 'administrador@example.test',
            'papel_entidade' => AccessRole::Administrator->value,
            'papel_gabinete' => AccessRole::Administrator->value,
        ]);
    }
}
