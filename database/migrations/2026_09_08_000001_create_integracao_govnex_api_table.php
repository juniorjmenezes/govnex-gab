<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração da integração com a GOVNEX API.
 *
 * É de plataforma, não de gabinete: a mesma URL e chave servem todas as
 * sincronizações globais de eleitorado, disparadas de /admin/gabinetes. Por
 * isso a tabela não tem `gabinete_id` e não usa o escopo de tenant — o
 * acesso é restrito pelo middleware `root` na rota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integracao_govnex_api', function (Blueprint $table): void {
            $table->id();
            $table->string('url', 512);
            // Guardada criptografada pelo cast `encrypted` do model: é um
            // segredo de acesso a serviço, não um hash de verificação — o
            // cliente HTTP precisa do valor original para enviar no header.
            $table->text('chave')->nullable();
            $table->timestamp('verificada_em')->nullable();
            $table->string('verificado_resultado', 24)->nullable();
            $table->text('verificado_detalhe')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integracao_govnex_api');
    }
};
