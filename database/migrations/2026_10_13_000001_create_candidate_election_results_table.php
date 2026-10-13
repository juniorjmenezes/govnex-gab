<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resultado final, por turno, das disputas decididas fora do município —
     * presidente (Brasil), governador, senador e deputados (UF) —, gravado a
     * partir da apuração ao vivo do TSE (ver ElectionResultRecorder).
     * Prefeito e vereador continuam em `votacoes_candidatos_municipio`, que é
     * por município; aqui não há município, e a candidatura já carrega a UF.
     *
     * `situacao_totalizacao` usa o mesmo vocabulário do DS_SIT_TOT_TURNO do
     * TSE ("ELEITO", "NÃO ELEITO", "2º TURNO", "ELEITO POR QP", "ELEITO POR
     * MÉDIA", "SUPLENTE") para o painel ler as duas tabelas do mesmo jeito.
     */
    public function up(): void
    {
        Schema::create('resultados_candidatos_eleicao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_politico_id');
            $table->foreign('candidato_politico_id', 'resultado_candidato_fk')
                ->references('id')
                ->on('candidatos_politicos')
                ->cascadeOnDelete();
            $table->foreignId('eleicao_id')
                ->constrained('eleicoes')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('turno');
            $table->unsignedBigInteger('votos');
            // Percentual sobre os votos válidos, como o TSE publica (`pvap`).
            $table->decimal('percentual_validos', 5, 2);
            $table->string('situacao_totalizacao', 100);
            $table->boolean('eleito')->default(false);
            $table->timestamps();

            $table->unique(['candidato_politico_id', 'turno'], 'resultado_candidato_turno_unique');
            $table->index(['eleicao_id', 'turno']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_candidatos_eleicao');
    }
};
