<?php

use App\Enums\EntidadeModule;
use App\Enums\EntidadeQuota;
use App\Enums\LicenseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_comerciais', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('nome', 160);
            $table->text('descricao')->nullable();
            $table->boolean('ativo')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('plano_comercial_versoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plano_comercial_id')->constrained('planos_comerciais')->restrictOnDelete();
            $table->unsignedInteger('versao');
            $table->json('modulos');
            $table->json('cotas');
            $table->json('configuracoes')->nullable();
            $table->timestamp('publicado_em');
            $table->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['plano_comercial_id', 'versao'], 'plano_comercial_versoes_unique');
        });

        Schema::create('entidade_licencas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('plano_versao_id')->constrained('plano_comercial_versoes')->restrictOnDelete();
            $table->string('status', 20)->default(LicenseStatus::Active->value);
            $table->timestamp('inicio_em');
            $table->timestamp('fim_em')->nullable();
            $table->json('cotas_snapshot');
            $table->json('ajustes')->nullable();
            $table->foreignId('administrador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['entidade_id', 'status', 'inicio_em'], 'entidade_licencas_status_index');
        });

        Schema::create('entidade_modulos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->string('modulo', 50);
            $table->string('escopo', 20);
            $table->boolean('contratado')->default(false);
            $table->boolean('ativo')->default(false);
            $table->timestamp('ativado_em')->nullable();
            $table->timestamp('desativado_em')->nullable();
            $table->foreignId('administrador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['entidade_id', 'modulo']);
            $table->index(['modulo', 'contratado', 'ativo'], 'entidade_modulos_disponibilidade_index');
        });

        Schema::create('entidade_modulo_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->string('modulo', 50);
            $table->string('acao', 30);
            $table->foreignId('administrador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('contexto')->nullable();
            $table->timestamp('ocorrido_em');
            $table->index(['entidade_id', 'ocorrido_em']);
        });

        Schema::create('entidade_consumos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->string('metrica', 50);
            $table->string('periodo', 20);
            $table->unsignedBigInteger('quantidade')->default(0);
            $table->timestamps();
            $table->unique(['entidade_id', 'metrica', 'periodo'], 'entidade_consumos_unique');
        });

        Schema::create('entidade_bairros', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->string('municipio', 120);
            $table->char('estado', 2);
            $table->boolean('ativo')->default(true);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['entidade_id', 'nome', 'municipio', 'estado'], 'entidade_bairros_unique');
        });

        $this->seedLegacyCompatiblePlan();
    }

    /**
     * Cria o plano de referência "LEGADO_COMPLETO" consumido por
     * EntidadeEntitlementService::provisionLegacyCompatible() sempre que uma
     * nova entidade independente é criada (cadastro administrativo e seeder).
     */
    private function seedLegacyCompatiblePlan(): void
    {
        $now = now();
        $modules = array_column(EntidadeModule::cases(), 'value');
        $quotas = [
            EntidadeQuota::ActiveGabinetes->value => null,
            EntidadeQuota::ActiveUsers->value => null,
            EntidadeQuota::StorageBytes->value => null,
            EntidadeQuota::MonthlyWhatsAppMessages->value => null,
        ];

        $planId = DB::table('planos_comerciais')->insertGetId([
            'codigo' => 'LEGADO_COMPLETO',
            'nome' => 'Legado completo',
            'descricao' => 'Plano de compatibilidade aplicado aos clientes existentes.',
            'ativo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('plano_comercial_versoes')->insert([
            'plano_comercial_id' => $planId,
            'versao' => 1,
            'modulos' => json_encode($modules, JSON_THROW_ON_ERROR),
            'cotas' => json_encode($quotas, JSON_THROW_ON_ERROR),
            'configuracoes' => null,
            'publicado_em' => $now,
            'publicado_por' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('entidade_bairros');
        Schema::dropIfExists('entidade_consumos');
        Schema::dropIfExists('entidade_modulo_eventos');
        Schema::dropIfExists('entidade_modulos');
        Schema::dropIfExists('entidade_licencas');
        Schema::dropIfExists('plano_comercial_versoes');
        Schema::dropIfExists('planos_comerciais');
    }
};
