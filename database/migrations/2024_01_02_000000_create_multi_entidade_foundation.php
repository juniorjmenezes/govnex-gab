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
            $table->string('papel', 30);
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
            $table->string('papel', 20);
            $table->boolean('ativo')->default(true);
            $table->timestamp('ingressou_em');
            $table->timestamp('desativado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['gabinete_id', 'usuario_id']);
            $table->index(['gabinete_id', 'papel', 'ativo'], 'gabinete_membros_papel_ativo_index');
        });

        Schema::create('gabinete_liderancas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome_snapshot', 180);
            $table->string('rotulo', 80);
            $table->date('inicio_em');
            $table->date('fim_em')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['gabinete_id', 'inicio_em', 'fim_em'], 'gabinete_liderancas_periodo_index');
        });

        Schema::create('entidade_convites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('gabinete_id')->nullable()->constrained('gabinetes')->cascadeOnDelete();
            $table->string('email');
            $table->string('papel_entidade', 30);
            $table->string('papel_gabinete', 20)->nullable();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('contexto_acesso_eventos');
        Schema::dropIfExists('entidade_convites');
        Schema::dropIfExists('gabinete_liderancas');
        Schema::dropIfExists('gabinete_membros');
        Schema::dropIfExists('entidade_membros');
    }
};
