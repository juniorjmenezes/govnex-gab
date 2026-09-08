<?php

namespace Tests\Feature;

use App\Models\Gabinete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapDemoEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private string $bootstrapDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'govnexgab-bootstrap-'.bin2hex(random_bytes(8));
        mkdir($this->bootstrapDirectory, 0700, true);
        config()->set('govnexgab.bootstrap_directory', $this->bootstrapDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->bootstrapDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->bootstrapDirectory);

        parent::tearDown();
    }

    public function test_bootstraps_demo_with_unique_strong_credentials_without_printing_them(): void
    {
        $this->artisan('govnexgab:bootstrap-demo', [
            '--admin-name' => 'Admin F3',
            '--admin-email' => 'fabriciovlw1@gmail.com',
        ])->assertSuccessful();

        $credentialsPath = $this->bootstrapDirectory.DIRECTORY_SEPARATOR.'credentials.txt';
        $this->assertFileExists($credentialsPath);
        $contents = file_get_contents($credentialsPath);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('password', $contents);

        $accounts = [];
        foreach (array_slice(array_filter(explode(PHP_EOL, $contents)), 2) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) === 4) {
                $accounts[$parts[0]] = [
                    'name' => $parts[1],
                    'email' => $parts[2],
                    'password' => $parts[3],
                ];
            }
        }

        $this->assertCount(5, $accounts);
        $this->assertCount(5, array_unique(array_column($accounts, 'password')));
        $this->assertSame('Admin F3', $accounts['admin']['name']);
        $this->assertSame('fabriciovlw1@gmail.com', $accounts['admin']['email']);

        foreach ($accounts as $account) {
            $this->assertGreaterThanOrEqual(24, strlen($account['password']));
            $user = User::query()->where('email', $account['email'])->firstOrFail();
            $this->assertTrue(Hash::check($account['password'], $user->password));
            $this->assertNotNull($user->email_verified_at);
        }

        $this->assertSame(2, Gabinete::withoutGlobalScopes()->count());
        $this->assertSame(5, User::query()->count());
    }

    public function test_refuses_to_run_again_or_overwrite_the_credentials_file(): void
    {
        $this->artisan('govnexgab:bootstrap-demo')->assertSuccessful();
        $credentialsPath = $this->bootstrapDirectory.DIRECTORY_SEPARATOR.'credentials.txt';
        $originalHash = hash_file('sha256', $credentialsPath);

        $this->artisan('govnexgab:bootstrap-demo')->assertFailed();

        $this->assertSame($originalHash, hash_file('sha256', $credentialsPath));
        $this->assertSame(2, Gabinete::withoutGlobalScopes()->count());
        $this->assertSame(5, User::query()->count());
    }
}
