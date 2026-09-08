<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de portais, mantido pelo root na administração.
        Schema::create('fontes_rss', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 120);
            $table->string('url', 500);
            $table->boolean('ativo')->default(true);
            $table->timestamp('ultima_coleta_em')->nullable();
            // ETag e Last-Modified evitam baixar o feed quando nada mudou.
            $table->string('etag', 255)->nullable();
            $table->string('modificado_em', 255)->nullable();
            $table->unsignedInteger('itens_importados')->default(0);
            $table->string('ultimo_erro', 500)->nullable();
            $table->timestamp('ultimo_erro_em')->nullable();
            $table->timestamps();

            $table->index('ativo');
        });

        // Itens coletados. Globais: a mesma notícia serve a todos os gabinetes.
        Schema::create('noticias_rss', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fonte_rss_id')->constrained('fontes_rss')->cascadeOnDelete();
            // O guid do feed pode ser longo demais para índice; o hash resolve.
            $table->char('guid_hash', 64);
            $table->string('guid', 500);
            $table->string('titulo', 500);
            $table->text('resumo')->nullable();
            $table->string('url', 1000);
            $table->string('imagem_url', 1000)->nullable();
            $table->timestamp('publicado_em')->nullable();
            $table->timestamps();

            $table->unique(['fonte_rss_id', 'guid_hash'], 'noticias_rss_fonte_guid_unique');
            $table->index('publicado_em');
        });

        // Casamento notícia x candidato favorito, por gabinete: os termos de
        // busca são configurados pelo gabinete, então o resultado é dele.
        Schema::create('noticias_candidatos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('noticia_rss_id')->constrained('noticias_rss')->cascadeOnDelete();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->cascadeOnDelete();
            $table->foreignId('candidato_politico_id')->constrained('candidatos_politicos')->cascadeOnDelete();
            $table->string('termo_casado', 160)->nullable();
            $table->timestamps();

            $table->unique(
                ['noticia_rss_id', 'gabinete_id', 'candidato_politico_id'],
                'noticias_candidatos_unique'
            );
            $table->index(['gabinete_id', 'created_at']);
        });

        // Termos extras por favorito, para apelidos e para cortar homônimos.
        Schema::table('candidatos_favoritos', function (Blueprint $table): void {
            $table->json('termos_busca')->nullable()->after('escolhido_por_id');
            $table->json('termos_exclusao')->nullable()->after('termos_busca');
        });
    }

    public function down(): void
    {
        Schema::table('candidatos_favoritos', function (Blueprint $table): void {
            $table->dropColumn(['termos_busca', 'termos_exclusao']);
        });

        Schema::dropIfExists('noticias_candidatos');
        Schema::dropIfExists('noticias_rss');
        Schema::dropIfExists('fontes_rss');
    }
};
