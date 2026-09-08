<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipios_eleitorais', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_tse', 20)->unique();
            $table->string('codigo_ibge', 20)->nullable()->unique();
            $table->string('nome', 120);
            $table->char('uf', 2);
            $table->timestamps();

            $table->index(['uf', 'nome']);
        });

        Schema::table('gabinetes', function (Blueprint $table) {
            $table->foreignId('municipio_eleitoral_id')
                ->nullable()
                ->after('estado')
                ->constrained('municipios_eleitorais')
                ->nullOnDelete();
        });

        Schema::create('eleicoes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_tse', 30)->nullable()->unique();
            $table->unsignedSmallInteger('ano');
            $table->string('tipo', 20);
            $table->string('nome');
            $table->date('primeiro_turno_em');
            $table->date('segundo_turno_em')->nullable();
            $table->string('situacao', 30)->default('programada');
            $table->timestamp('fonte_atualizada_em')->nullable();
            $table->timestamps();

            $table->unique(['ano', 'tipo']);
        });

        Schema::create('eleitorado_municipio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipio_eleitoral_id')
                ->constrained('municipios_eleitorais')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('ano_referencia');
            $table->date('data_referencia');
            $table->unsignedBigInteger('eleitores_aptos');
            $table->text('fonte_url');
            $table->timestamp('fonte_gerada_em')->nullable();
            $table->timestamps();

            $table->unique(
                ['municipio_eleitoral_id', 'data_referencia'],
                'eleitorado_municipio_referencia_unique',
            );
        });

        Schema::create('candidatos_politicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleicao_id')
                ->constrained('eleicoes')
                ->cascadeOnDelete();
            $table->string('sq_candidato', 30);
            $table->string('abrangencia', 20);
            $table->foreignId('municipio_eleitoral_id')
                ->nullable()
                ->constrained('municipios_eleitorais')
                ->nullOnDelete();
            $table->char('uf', 2)->nullable();
            $table->string('cargo', 100);
            $table->string('nome');
            $table->string('nome_urna');
            $table->string('numero', 20)->nullable();
            $table->string('partido_sigla', 30)->nullable();
            $table->string('partido_nome')->nullable();
            $table->string('situacao', 100)->nullable();
            $table->string('situacao_detalhada', 150)->nullable();
            $table->text('foto_url')->nullable();
            $table->timestamp('fonte_atualizada_em')->nullable();
            $table->timestamps();

            $table->unique(['eleicao_id', 'sq_candidato']);
            $table->index(['eleicao_id', 'abrangencia', 'uf']);
            $table->index(['eleicao_id', 'municipio_eleitoral_id', 'cargo'], 'candidatos_eleicao_municipio_cargo_index');
            $table->index(['eleicao_id', 'cargo', 'partido_sigla']);
        });

        Schema::create('candidatos_favoritos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')
                ->constrained('gabinetes')
                ->cascadeOnDelete();
            $table->foreignId('candidato_politico_id')
                ->constrained('candidatos_politicos')
                ->cascadeOnDelete();
            $table->foreignId('escolhido_por_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['gabinete_id', 'candidato_politico_id']);
        });

        Schema::create('sincronizacoes_tse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->nullable()->constrained('gabinetes')->nullOnDelete();
            $table->foreignId('solicitado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dataset', 50);
            $table->unsignedSmallInteger('ano');
            // Só preenchido para datasets segmentados por UF (ex.: eleitorado,
            // votação por seção) — permite distinguir o histórico de estados
            // diferentes no mesmo dataset/ano em vez de colapsar tudo em um.
            $table->string('uf', 2)->nullable();
            $table->text('fonte_url');
            $table->string('checksum_sha256', 64)->nullable();
            // Caminho do ZIP retido após um processamento bem-sucedido — só
            // o mais recente por dataset/ano/UF é mantido (o anterior é
            // apagado ao ser substituído). Permite reprocessar um gabinete
            // recém-criado sem pedir upload de novo.
            $table->string('arquivo_retido_path')->nullable();
            $table->string('situacao', 30);
            $table->unsignedBigInteger('registros_processados')->default(0);
            // Progresso do processamento em andamento — 'lendo_arquivo'
            // (percentual de bytes já lidos do CSV dentro do ZIP) e depois
            // 'gravando_registros' (percentual dos lotes já gravados no
            // banco). Permite uma barra real em vez de indeterminada.
            $table->string('progresso_etapa', 30)->nullable();
            $table->unsignedTinyInteger('progresso_percentual')->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('iniciada_em');
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            $table->index(['dataset', 'ano', 'uf', 'iniciada_em']);
            $table->index(['gabinete_id', 'situacao', 'iniciada_em']);
        });

        $now = now();
        DB::table('eleicoes')->insert([
            [
                'codigo_tse' => '2024-municipal',
                'ano' => 2024,
                'tipo' => 'municipal',
                'nome' => 'Eleições Municipais 2024',
                'primeiro_turno_em' => '2024-10-06',
                'segundo_turno_em' => '2024-10-27',
                'situacao' => 'concluida',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'codigo_tse' => '2026-geral',
                'ano' => 2026,
                'tipo' => 'geral',
                'nome' => 'Eleições Gerais 2026',
                'primeiro_turno_em' => '2026-10-04',
                'segundo_turno_em' => '2026-10-25',
                'situacao' => 'programada',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sincronizacoes_tse');
        Schema::dropIfExists('candidatos_favoritos');
        Schema::dropIfExists('candidatos_politicos');
        Schema::dropIfExists('eleitorado_municipio_snapshots');
        Schema::dropIfExists('eleicoes');

        Schema::table('gabinetes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipio_eleitoral_id');
        });

        Schema::dropIfExists('municipios_eleitorais');
    }
};
