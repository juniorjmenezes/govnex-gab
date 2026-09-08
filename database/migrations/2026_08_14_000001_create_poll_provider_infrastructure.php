<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proveniência: toda tentativa de coleta (bem-sucedida ou não) de
        // resultados para uma pesquisa fica registrada aqui, rastreável até a
        // fonte exata. pesquisa_eleitoral_id é nulo quando a fonte foi
        // descoberta (ex.: RSS) antes de existir uma pesquisa persistida
        // correspondente.
        Schema::create('pesquisa_fontes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesquisa_eleitoral_id')
                ->nullable()
                ->constrained('pesquisas_eleitorais')
                ->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('provider', 60);
            $table->text('url')->nullable();
            $table->string('status', 20);
            $table->unsignedTinyInteger('confidence_score');
            $table->timestamp('coletado_em')->nullable();
            $table->string('hash_conteudo', 64)->nullable();
            $table->text('erro')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['pesquisa_eleitoral_id', 'confidence_score']);
            $table->index(['provider', 'status']);
            $table->index('hash_conteudo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesquisa_fontes');
    }
};
