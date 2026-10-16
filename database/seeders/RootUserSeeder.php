<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Conta root inicial da plataforma — não é dado de demonstração, por isso
 * roda independente do ambiente. `firstOrCreate` por e-mail: se a conta já
 * existir (produção, depois do primeiro deploy), o seeder não toca na senha
 * nem em nenhum outro campo dela.
 *
 * Uso isolado (ex.: em produção): `php artisan db:seed --class="Database\Seeders\RootUserSeeder"`.
 */
class RootUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'root@govnex.app.br'],
            [
                'name' => 'Root Govnex',
                'role' => UserRole::Root,
                'gabinete_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
                'password' => 'Gabriel@10052015##',
            ],
        );
    }
}
