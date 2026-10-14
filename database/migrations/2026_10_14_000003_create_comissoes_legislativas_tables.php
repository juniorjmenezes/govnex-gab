<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comissões legislativas da Câmara (Fase 3 da Secretaria legislativa).
     *
     * A comissão pertence à entidade (`entidade_id`), não a um gabinete — como
     * `entidade_bairros`. O membro aponta para o gabinete de vereador de
     * origem (`gabinete_id`), que é só referência: o registro não passa a ser
     * daquele gabinete. `usuario_id` é a pessoa indicada e pode trocar sem
     * duplicar a linha; fica nula quando o titular do gabinete
     * (`vereador_nome`) não tem conta.
     *
     * Documentos não têm arquivo próprio: `comissao_documentos` só liga a
     * comissão a um `conhecimento_documentos` já compartilhado com a Câmara.
     */
    public function up(): void
    {
        Schema::create('comissoes_legislativas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->string('nome', 180);
            $table->string('sigla', 20)->nullable();
            $table->string('tipo', 30);
            $table->string('status', 30)->default('ativa');
            $table->foreignId('criado_por_id')
                ->constrained('users', 'id', 'committees_creator_fk')
                ->restrictOnDelete();
            $table->timestamp('encerrada_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entidade_id', 'status'], 'committees_entity_status_idx');
        });

        Schema::create('comissao_membros', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comissao_id')
                ->constrained('comissoes_legislativas', 'id', 'committee_members_committee_fk')
                ->cascadeOnDelete();
            $table->foreignId('gabinete_id')
                ->constrained('gabinetes', 'id', 'committee_members_office_fk')
                ->cascadeOnDelete();
            $table->foreignId('usuario_id')
                ->nullable()
                ->constrained('users', 'id', 'committee_members_user_fk')
                ->nullOnDelete();
            $table->string('cargo', 30)->default('membro');
            $table->date('ingressou_em');
            $table->date('saiu_em')->nullable();
            $table->timestamps();

            $table->unique(['comissao_id', 'gabinete_id'], 'committee_members_office_unique');
            $table->index('gabinete_id', 'committee_members_office_idx');
        });

        Schema::create('comissao_documentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comissao_id')
                ->constrained('comissoes_legislativas', 'id', 'committee_documents_committee_fk')
                ->cascadeOnDelete();
            $table->foreignId('documento_id')
                ->constrained('conhecimento_documentos', 'id', 'committee_documents_document_fk')
                ->cascadeOnDelete();
            $table->string('tipo_vinculo', 30)->nullable();
            $table->timestamps();

            $table->unique(['comissao_id', 'documento_id'], 'committee_documents_unique');
            $table->index('documento_id', 'committee_documents_document_idx');
        });

        // Coluna criada com a tabela de compromissos institucionais (migration
        // anterior); a FK só pode nascer depois de `comissoes_legislativas`.
        // Excluir a comissão não apaga a reunião já marcada: só tira o vínculo.
        Schema::table('compromissos_institucionais', function (Blueprint $table): void {
            $table->foreign('comissao_id', 'institutional_appointments_committee_fk')
                ->references('id')
                ->on('comissoes_legislativas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compromissos_institucionais', function (Blueprint $table): void {
            $table->dropForeign('institutional_appointments_committee_fk');
        });
        Schema::dropIfExists('comissao_documentos');
        Schema::dropIfExists('comissao_membros');
        Schema::dropIfExists('comissoes_legislativas');
    }
};
