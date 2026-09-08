<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('compromissos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cidadao_id')->nullable()->constrained('cidadaos')->nullOnDelete();
            $table->foreignId('demanda_id')->nullable()->constrained('demandas')->nullOnDelete();
            $table->foreignId('criado_por_id')->constrained('users')->restrictOnDelete();
            $table->string('titulo', 180);
            $table->text('descricao')->nullable();
            $table->dateTime('inicio_em');
            $table->dateTime('fim_em');
            $table->boolean('dia_inteiro')->default(false);
            $table->string('local', 220)->nullable();
            $table->string('tipo', 80)->default('compromisso');
            $table->string('status', 30)->default('agendado');
            $table->text('observacoes')->nullable();
            $table->string('recorrencia', 20)->default('nenhuma');
            $table->date('recorrencia_ate')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['gabinete_id', 'inicio_em'], 'appointments_office_start_idx');
            $table->index(['gabinete_id', 'status', 'inicio_em'], 'appointments_office_status_idx');
            $table->index(['gabinete_id', 'responsavel_id', 'inicio_em'], 'appointments_responsible_idx');
        });

        Schema::create('compromisso_participantes', function (Blueprint $table): void {
            $table->foreignId('compromisso_id')->constrained('compromissos')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['compromisso_id', 'usuario_id']);
        });

        Schema::create('compromisso_lembretes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('compromisso_id')->constrained('compromissos')->cascadeOnDelete();
            $table->string('canal', 30);
            $table->unsignedInteger('antecedencia_minutos');
            $table->json('destinatarios');
            $table->boolean('ativo')->default(true);
            $table->dateTime('agendado_para');
            $table->string('status', 30)->default('pendente');
            $table->timestamp('processado_em')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();

            $table->index(['status', 'ativo', 'agendado_para'], 'appointment_reminders_due_idx');
            $table->index(['gabinete_id', 'compromisso_id'], 'appointment_reminders_office_idx');
        });

        Schema::create('notificacao_tentativas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('lembrete_id')->constrained('compromisso_lembretes')->cascadeOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('canal', 30);
            $table->string('destinatario', 180);
            $table->string('status', 30);
            $table->json('payload');
            $table->unsignedSmallInteger('tentativas')->default(1);
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();

            $table->index(['gabinete_id', 'status', 'created_at'], 'notification_attempts_office_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacao_tentativas');
        Schema::dropIfExists('compromisso_lembretes');
        Schema::dropIfExists('compromisso_participantes');
        Schema::dropIfExists('compromissos');
        Schema::dropIfExists('notifications');
    }
};
