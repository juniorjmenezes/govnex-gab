<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RootUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_root_can_list_invite_reset_password_and_deactivate_root_users(): void
    {
        $root = User::factory()->root()->create();

        $this->actingAs($root)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/index')
                ->where('users', fn ($users) => collect($users)
                    ->contains(fn (array $item): bool => $item['id'] === $root->id)));

        $this->actingAs($root)
            ->post(route('admin.users.store'), [
                'name' => 'Segundo Root',
                'email' => 'segundo-root@example.test',
                'password' => 'senha-muito-segura-123',
                'password_confirmation' => 'senha-muito-segura-123',
            ])
            ->assertRedirect();

        $second = User::query()->where('email', 'segundo-root@example.test')->firstOrFail();
        $this->assertSame(UserRole::Root, $second->role);
        $this->assertNull($second->gabinete_id);

        $this->actingAs($root)
            ->post(route('admin.users.reset-password', $second), [
                'password' => 'outra-senha-segura-123',
                'password_confirmation' => 'outra-senha-segura-123',
            ])
            ->assertRedirect();
        $this->assertTrue(Hash::check('outra-senha-segura-123', $second->refresh()->password));

        $this->actingAs($root)
            ->delete(route('admin.users.destroy', $second))
            ->assertRedirect();
        $this->assertFalse($second->refresh()->is_active);
    }

    public function test_non_root_users_cannot_access_root_user_management(): void
    {
        $gabinete = Gabinete::factory()->create();
        $councilor = User::factory()->councilor()->forGabinete($gabinete)->create();
        $target = User::factory()->root()->create();

        $this->actingAs($councilor)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($councilor)->post(route('admin.users.store'), [
            'name' => 'Tentativa',
            'email' => 'tentativa@example.test',
            'password' => 'senha-muito-segura-123',
            'password_confirmation' => 'senha-muito-segura-123',
        ])->assertForbidden();
        $this->actingAs($councilor)
            ->patch(route('admin.users.update', $target), ['is_active' => false])
            ->assertForbidden();
        $this->actingAs($councilor)
            ->post(route('admin.users.reset-password', $target), [
                'password' => 'outra-senha-segura-123',
                'password_confirmation' => 'outra-senha-segura-123',
            ])
            ->assertForbidden();
        $this->actingAs($councilor)
            ->delete(route('admin.users.destroy', $target))
            ->assertForbidden();
    }

    public function test_root_cannot_deactivate_or_remove_itself(): void
    {
        $root = User::factory()->root()->create();

        $this->actingAs($root)
            ->patch(route('admin.users.update', $root), ['is_active' => false])
            ->assertForbidden();

        $this->actingAs($root)
            ->delete(route('admin.users.destroy', $root))
            ->assertForbidden();

        $this->assertTrue($root->refresh()->is_active);
    }
}
