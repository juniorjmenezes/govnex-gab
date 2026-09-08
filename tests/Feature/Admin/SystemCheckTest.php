<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_can_view_the_system_check_page(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->get('/admin/sistema')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/system/index')
                ->has('php.version')
                ->has('extensions')
                ->has('ini.upload_max_filesize')
                ->has('writablePaths')
                ->has('database.connected')
                ->has('queue.driver')
            );
    }

    public function test_a_non_root_user_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/sistema')
            ->assertForbidden();
    }

    public function test_the_upload_test_endpoint_reports_the_received_file_back(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->from('/admin/sistema')
            ->post('/admin/sistema/teste-upload', [
                'arquivo' => UploadedFile::fake()->create('teste.bin', 2048),
            ])
            ->assertRedirect('/admin/sistema')
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get('/admin/sistema')
            ->assertInertia(fn (Assert $page) => $page
                ->where('uploadTest.name', 'teste.bin')
                ->where('uploadTest.size_bytes', 2048 * 1024)
            );
    }

    public function test_the_upload_test_endpoint_requires_a_file(): void
    {
        $admin = User::factory()->root()->create();

        $this->actingAs($admin)
            ->post('/admin/sistema/teste-upload', [])
            ->assertSessionHasErrors('arquivo');
    }
}
