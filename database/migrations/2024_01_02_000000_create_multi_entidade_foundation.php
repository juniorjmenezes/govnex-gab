<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidade_membros', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            // Inerte: o GEP não lê mais papel do Hub (o cargo local decide).
            // Mantida até a limpeza do schema; o sync do Hub grava null.
            $table->string('papel', 30)->nullable()->comment('Inerte (legado): papel do Hub, não é lido pelo GEP');
            $table->boolean('ativo')->default(true);
            $table->timestamp('ingressou_em');
            $table->timestamp('desativado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['entidade_id', 'usuario_id']);
            $table->index(['entidade_id', 'papel', 'ativo'], 'entidade_membros_papel_ativo_index');
        });

        Schema::create('gabinete_membros', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            // Inerte: o GEP não lê mais papel do Hub (o cargo local decide).
            // Mantida até a limpeza do schema; o sync do Hub grava null.
            $table->string('papel', 20)->nullable()->comment('Inerte (legado): papel do Hub, não é lido pelo GEP');
            $table->string('cargo', 30)->nullable()->comment('Cargo local do GEP (App\Enums\OfficePosition): VEREADOR, CHEFE_GABINETE, SECRETARIO, ASSESSOR ou AUDITOR; nunca vem do Hub. Sem cargo = sem acesso ao gabinete');
            $table->boolean('ativo')->default(true);
            $table->timestamp('ingressou_em');
            $table->timestamp('desativado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Apoio no banco à regra de no máximo 1 vereador e 1 chefe de
            // gabinete ATIVOS por gabinete (a regra em si é do
            // OfficePositionAssigner, com lock): a coluna gerada só tem valor
            // para esses cargos em vínculo ativo, e NULL não colide no índice
            // único (MariaDB e SQLite).
            $table->string('cargo_lider_ativo', 30)
                ->storedAs("CASE WHEN ativo = 1 AND cargo IN ('VEREADOR', 'CHEFE_GABINETE') THEN cargo ELSE NULL END")
                ->nullable();
            $table->unique(['gabinete_id', 'usuario_id']);
            $table->unique(['gabinete_id', 'cargo_lider_ativo'], 'gabinete_membros_lider_ativo_unique');
            $table->index(['gabinete_id', 'papel', 'ativo'], 'gabinete_membros_papel_ativo_index');
        });

        Schema::create('entidade_convites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('gabinete_id')->nullable()->constrained('gabinetes')->cascadeOnDelete();
            $table->string('email');
            $table->string('papel_entidade', 30)->comment('ADMINISTRADOR, OPERADOR ou AUDITOR (App\Enums\AccessRole)');
            $table->string('papel_gabinete', 20)->nullable()->comment('ADMINISTRADOR, OPERADOR ou AUDITOR (App\Enums\AccessRole)');
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->index();
            $table->string('modo_entrega', 30)->default('EMAIL');
            $table->boolean('exige_troca_senha')->default(false);
            $table->timestamp('expira_em')->nullable();
            $table->timestamp('aceito_em')->nullable();
            $table->foreignId('convidado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aceito_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['entidade_id', 'email', 'status'], 'entidade_convites_email_status_index');
        });

        Schema::create('contexto_acesso_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->nullable()->constrained('entidades')->nullOnDelete();
            $table->foreignId('gabinete_id')->nullable()->constrained('gabinetes')->nullOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evento', 40);
            $table->string('resultado', 20);
            $table->string('rota', 190)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->boolean('administrador_plataforma')->default(false);
            $table->json('contexto')->nullable();
            $table->timestamp('ocorrido_em');
            $table->index(['usuario_id', 'ocorrido_em']);
            $table->index(['entidade_id', 'gabinete_id', 'ocorrido_em'], 'contexto_acesso_entidade_gabinete_index');
        });

        // Caixa de entrada durável dos webhooks do Govnex Hub
        // (App\Models\HubWebhookEvent). Idempotência pelo UNIQUE de
        // `evento_id`; `payload` guarda o evento validado para reprocesso e é
        // apagado pela retenção (`hub:limpar-webhooks`).
        Schema::create('hub_webhook_eventos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('evento_id')->unique()->comment('id do evento no Hub');
            $table->string('tipo', 64);
            $table->string('sistema', 16);
            $table->string('status', 32)->comment('App\Enums\HubWebhookStatus');
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->json('payload');
            $table->string('resultado', 64)->nullable()->comment('acao devolvida pelo processamento');
            $table->string('ultimo_erro', 500)->nullable();
            $table->timestamp('ocorrido_em')->nullable();
            $table->timestamp('recebido_em');
            $table->timestamp('ultima_tentativa_em')->nullable();
            $table->timestamp('proxima_tentativa_em')->nullable();
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();
            $table->index(['status', 'proxima_tentativa_em']);
            $table->index(['status', 'processado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_webhook_eventos');
        Schema::dropIfExists('contexto_acesso_eventos');
        Schema::dropIfExists('entidade_convites');
        Schema::dropIfExists('gabinete_membros');
        Schema::dropIfExists('entidade_membros');
    }
};
