<?php

use App\Enums\EntidadeStatus;
use App\Enums\GabineteStatus;
use App\Enums\GabineteType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidades', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 40)->index();
            $table->string('nome', 180);
            $table->string('slug', 190)->unique();
            $table->string('status', 20)->default(EntidadeStatus::Active->value)->index();
            $table->string('municipio', 120);
            $table->char('estado', 2);
            $table->string('timezone', 80)->default('America/Sao_Paulo');
            $table->string('logo_path')->nullable();
            $table->string('cor_principal', 20)->nullable();
            $table->string('cor_secundaria', 20)->nullable();
            $table->boolean('interface_simplificada')->default(false);
            $table->json('configuracoes')->nullable();
            $table->unsignedBigInteger('gabinete_origem_id')->nullable()->unique();
            $table->timestamp('suspensa_em')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['estado', 'municipio']);
        });

        Schema::create('gabinetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entidade_id')
                ->constrained('entidades')
                ->restrictOnDelete();
            $table->string('tipo_gabinete', 40)
                ->default(GabineteType::IndependentOffice->value)
                ->index();
            $table->string('nome');
            $table->string('slug')->unique();
            $table->string('status')->default(GabineteStatus::Active->value)->index();
            $table->string('vereador_nome')->nullable();
            $table->string('municipio');
            $table->char('estado', 2);
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('telefone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('endereco')->nullable();
            $table->string('bairro', 120)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('complemento', 255)->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cor_principal', 20)->nullable();
            $table->string('formato_protocolo')->default('{ANO}-{SEQUENCIAL}');
            $table->text('cabecalho_relatorios')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'municipio']);
            $table->index(['entidade_id', 'status'], 'gabinetes_entidade_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gabinetes');
        Schema::dropIfExists('entidades');
    }
};
