<?php

use App\Enums\EntidadeWhatsAppConnectionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidade_whatsapp_conexoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->constrained('entidades')->cascadeOnDelete();
            $table->unsignedBigInteger('gateway_account_id');
            $table->string('tipo', 16);
            $table->string('nome_exibicao', 160);
            $table->char('telefone_final', 4)->nullable();
            $table->string('status', 20)->default(EntidadeWhatsAppConnectionStatus::Active->value);
            $table->boolean('ativo')->default(false);
            $table->json('metadados')->nullable();
            $table->foreignId('atribuido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('atribuido_em')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamp('desativado_em')->nullable();
            $table->timestamps();

            $table->unique(['entidade_id', 'gateway_account_id'], 'org_wa_connection_account_uk');
            $table->index(['gateway_account_id', 'ativo'], 'org_wa_connection_active_idx');
        });

        Schema::create('whatsapp_configuracoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->unique()->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('entidade_id')->nullable()->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('entidade_whatsapp_conexao_id')->nullable()
                ->constrained('entidade_whatsapp_conexoes', indexName: 'wa_config_connection_fk')
                ->nullOnDelete();
            $table->string('modo', 12)->default('OFF');
            $table->time('resumo_diario_em')->default('08:00:00');
            $table->json('finalidades_habilitadas')->nullable();
            $table->timestamps();

            $table->index(['entidade_id', 'entidade_whatsapp_conexao_id'], 'wa_config_org_connection_idx');
        });

        Schema::create('whatsapp_contatos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('cidadao_id')->nullable()->constrained('cidadaos')->cascadeOnDelete();
            $table->text('telefone_criptografado')->nullable();
            $table->char('telefone_hash', 64)->nullable()->index();
            $table->char('telefone_final', 4)->nullable();
            $table->string('status', 16)->default('DECLARED');
            $table->boolean('piloto')->default(false);
            $table->string('origem', 32);
            $table->timestamp('declarado_em')->nullable();
            $table->timestamp('revogado_em')->nullable();
            $table->timestamp('invalido_em')->nullable();
            $table->timestamps();

            $table->unique(['gabinete_id', 'usuario_id'], 'wa_contacts_office_user_uk');
            $table->unique(['gabinete_id', 'cidadao_id'], 'wa_contacts_office_citizen_uk');
            $table->index(['gabinete_id', 'status', 'piloto'], 'wa_contacts_office_status_idx');
        });

        Schema::create('whatsapp_consentimentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('whatsapp_contato_id')->constrained('whatsapp_contatos')->cascadeOnDelete();
            $table->foreignId('registrado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acao', 16);
            $table->string('versao', 20);
            $table->char('texto_hash', 64);
            $table->string('origem', 32);
            $table->timestamp('ocorrido_em');
            $table->timestamps();

            $table->index(['gabinete_id', 'whatsapp_contato_id', 'ocorrido_em'], 'wa_consents_contact_idx');
        });

        Schema::create('whatsapp_template_finalidades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entidade_id')->nullable()->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('entidade_whatsapp_conexao_id')->nullable()
                ->constrained('entidade_whatsapp_conexoes', indexName: 'wa_template_connection_fk')
                ->cascadeOnDelete();
            $table->string('finalidade', 80);
            $table->unsignedBigInteger('gateway_template_id')->nullable()->index();
            $table->string('nome_meta', 512);
            $table->string('idioma', 16)->default('pt_BR');
            $table->string('categoria', 24)->default('UTILITY');
            $table->string('status', 32)->default('ABSENT');
            $table->json('componentes')->nullable();
            $table->char('contrato_hash', 64);
            $table->boolean('ativo')->default(false);
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(
                ['entidade_id', 'entidade_whatsapp_conexao_id', 'finalidade'],
                'wa_template_org_connection_purpose_uk',
            );
        });

        Schema::create('whatsapp_notificacoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('entidade_id')->nullable()->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('entidade_whatsapp_conexao_id')->nullable()
                ->constrained('entidade_whatsapp_conexoes', indexName: 'wa_notification_connection_fk')
                ->nullOnDelete();
            $table->foreignId('whatsapp_contato_id')->nullable()->constrained('whatsapp_contatos')->nullOnDelete();
            $table->foreignId('whatsapp_template_finalidade_id')->nullable()->constrained('whatsapp_template_finalidades')->nullOnDelete();
            $table->uuid('client_request_id')->unique();
            $table->char('idempotency_key', 64)->unique();
            $table->string('finalidade', 80);
            $table->nullableMorphs('origem');
            $table->text('telefone_criptografado')->nullable();
            $table->char('telefone_hash', 64)->index();
            $table->char('telefone_final', 4);
            $table->text('parametros_corpo_criptografados')->nullable();
            $table->text('parametros_botoes_criptografados')->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->string('gateway_status', 40)->nullable();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->timestamp('proxima_tentativa_em')->nullable();
            $table->timestamp('expira_em')->nullable();
            $table->timestamp('submetido_em')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('entregue_em')->nullable();
            $table->timestamp('lido_em')->nullable();
            $table->timestamp('falhou_em')->nullable();
            $table->timestamp('expurgar_sensiveis_em')->nullable();
            $table->timestamp('consumo_reservado_em')->nullable();
            $table->timestamp('consumo_registrado_em')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();

            $table->index(['status', 'proxima_tentativa_em'], 'wa_notifications_retry_idx');
            $table->index(['gabinete_id', 'finalidade', 'created_at'], 'wa_notifications_office_purpose_idx');
            $table->index(['entidade_id', 'created_at'], 'wa_notification_org_created_idx');
        });

        Schema::create('whatsapp_callback_eventos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('gabinete_id')->nullable()->constrained('gabinetes')->nullOnDelete();
            $table->foreignId('entidade_id')->nullable()->constrained('entidades')->nullOnDelete();
            $table->foreignId('entidade_whatsapp_conexao_id')->nullable()
                ->constrained('entidade_whatsapp_conexoes', indexName: 'wa_callback_connection_fk')
                ->nullOnDelete();
            $table->foreignId('whatsapp_notificacao_id')->nullable()->constrained('whatsapp_notificacoes')->nullOnDelete();
            $table->uuid('client_request_id')->nullable()->index();
            $table->string('tipo', 40);
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('RECEIVED');
            $table->timestamp('ocorrido_em')->nullable();
            $table->timestamp('processado_em')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_callback_eventos');
        Schema::dropIfExists('whatsapp_notificacoes');
        Schema::dropIfExists('whatsapp_template_finalidades');
        Schema::dropIfExists('whatsapp_consentimentos');
        Schema::dropIfExists('whatsapp_contatos');
        Schema::dropIfExists('whatsapp_configuracoes');
        Schema::dropIfExists('entidade_whatsapp_conexoes');
    }
};
