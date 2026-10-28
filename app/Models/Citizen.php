<?php

namespace App\Models;

use Database\Factories\CitizenFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Citizen extends TenantModel
{
    /** @use HasFactory<CitizenFactory> */
    use HasFactory, SoftDeletes;

    // Tabela já publicada em português (ver AGENTS.md): só o nome da classe
    // segue a convenção em inglês, o schema não muda.
    protected $table = 'cidadaos';

    /** @return BelongsTo<Neighborhood, $this> */
    public function bairro(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class, 'bairro_id')->withTrashed();
    }

    /** @return Attribute<?string, ?string> */
    protected function cpf(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $this->digits($value));
    }

    /** @return Attribute<?string, ?string> */
    protected function telefone(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $this->digits($value));
    }

    /** @return Attribute<?string, ?string> */
    protected function whatsapp(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $this->digits($value));
    }

    /** @return Attribute<?string, ?string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value ? Str::lower(trim($value)) : null);
    }

    /** @return Attribute<?string, ?string> */
    protected function cep(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $this->digits($value));
    }

    /** @return Attribute<?string, ?string> */
    protected function estado(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value ? Str::upper(trim($value)) : null);
    }

    /** @return Attribute<?string, ?string> */
    protected function municipio(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value ? Str::squish($value) : null);
    }

    /** @return HasMany<Demand, $this> */
    public function demandas(): HasMany
    {
        return $this->hasMany(Demand::class, 'cidadao_id');
    }

    /** @return HasMany<Attendance, $this> */
    public function atendimentos(): HasMany
    {
        return $this->hasMany(Attendance::class, 'cidadao_id');
    }

    /** @return HasOne<WhatsAppContact, $this> */
    public function whatsappContact(): HasOne
    {
        return $this->hasOne(WhatsAppContact::class, 'cidadao_id');
    }

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'consentimento_contato' => 'boolean',
            'eleitor' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'cadastrado_em' => 'datetime',
        ];
    }

    private function digits(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits !== '' ? $digits : null;
    }
}
