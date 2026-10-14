<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agenda institucional da Secretaria legislativa (Fase 1). O compromisso
     * pertence à Câmara (`entidade_id`), não a um gabinete: quem publicou fica
     * em `criado_por_gabinete_id`. O público vem de
     * `compromisso_institucional_gabinetes` — sem linha, vale para todos os
     * gabinetes de vereador da Câmara; com linhas, só para os listados.
     *
     * Sem recorrência nem lembretes nesta fase: quando vierem, serão colunas
     * e tabelas próprias, como em `compromissos`.
     *
     * `comissao_id` liga o compromisso a uma comissão legislativa (reunião da
     * comissão). A coluna nasce aqui, mas a FK para `comissoes_legislativas`
     * só é criada na migration das comissões, que roda depois desta.
     */
    public function up(): void
    {
        Schema::create('compromissos_institucionais', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('criado_por_gabinete_id')
                ->constrained('gabinetes', 'id', 'institutional_appointments_office_fk')
                ->restrictOnDelete();
            $table->foreignId('criado_por_id')
                ->constrained('users', 'id', 'institutional_appointments_creator_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('comissao_id')->nullable();
            $table->string('titulo', 180);
            $table->text('descricao')->nullable();
            $table->dateTime('inicio_em');
            $table->dateTime('fim_em');
            $table->boolean('dia_inteiro')->default(false);
            $table->string('local', 220)->nullable();
            $table->string('tipo', 80);
            $table->string('status', 30)->default('agendado');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entidade_id', 'inicio_em'], 'institutional_appointments_entity_start_idx');
            $table->index(['criado_por_gabinete_id', 'inicio_em'], 'institutional_appointments_office_start_idx');
            $table->index('comissao_id', 'institutional_appointments_committee_idx');
        });

        Schema::create('compromisso_institucional_gabinetes', function (Blueprint $table): void {
            $table->foreignId('compromisso_institucional_id')
                ->constrained('compromissos_institucionais', 'id', 'institutional_targets_appointment_fk')
                ->cascadeOnDelete();
            $table->foreignId('gabinete_id')
                ->constrained('gabinetes', 'id', 'institutional_targets_office_fk')
                ->cascadeOnDelete();
            $table->timestamp('confirmado_em')->nullable();
            $table->timestamps();

            $table->primary(['compromisso_institucional_id', 'gabinete_id'], 'institutional_targets_primary');
            $table->index('gabinete_id', 'institutional_targets_office_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compromisso_institucional_gabinetes');
        Schema::dropIfExists('compromissos_institucionais');
    }
};
