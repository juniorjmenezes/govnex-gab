<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bairros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->foreignId('entidade_bairro_id')->nullable()->constrained('entidade_bairros')->nullOnDelete();
            $table->string('nome');
            $table->string('municipio');
            $table->char('estado', 2);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gabinete_id', 'nome', 'municipio', 'estado'], 'bairros_gabinete_local_unique');
            $table->index(['gabinete_id', 'ativo', 'nome']);
            $table->index(['gabinete_id', 'entidade_bairro_id'], 'bairros_gabinete_referencia_index');
        });

        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->string('icone', 50)->nullable();
            $table->string('cor_semantica', 30)->default('neutra');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gabinete_id', 'nome']);
            $table->index(['gabinete_id', 'ativo', 'nome']);
        });

        Schema::create('cidadaos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->constrained('gabinetes')->restrictOnDelete();
            $table->foreignId('bairro_id')->nullable()->constrained('bairros')->nullOnDelete();
            $table->string('nome');
            $table->string('cpf', 11)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('endereco')->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('complemento')->nullable();
            $table->string('ponto_referencia')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('localizacao_origem', 20)->nullable();
            $table->char('estado', 2)->nullable();
            $table->string('municipio', 120)->nullable();
            $table->string('cep', 8)->nullable();
            $table->text('observacoes')->nullable();
            $table->boolean('consentimento_contato')->default(false);
            $table->boolean('eleitor')->default(false);
            $table->timestamp('cadastrado_em')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gabinete_id', 'cpf']);
            $table->index(['gabinete_id', 'nome']);
            $table->index(['gabinete_id', 'bairro_id']);
            $table->index(['gabinete_id', 'telefone']);
            $table->index(['gabinete_id', 'whatsapp']);
            $table->index(['gabinete_id', 'email']);
        });

        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gabinete_id')->unique()->constrained('gabinetes')->cascadeOnDelete();
            $table->string('partido', 30)->nullable();
            $table->string('legislatura', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
        Schema::dropIfExists('cidadaos');
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('bairros');
    }
};
