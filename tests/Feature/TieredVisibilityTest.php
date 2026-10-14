<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TieredVisibilityRecord;
use Tests\TestCase;

class TieredVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const ENTIDADE_A = 10;

    private const ENTIDADE_B = 20;

    private const GABINETE_A1 = 101;

    private const GABINETE_A2 = 102;

    private const GABINETE_B1 = 201;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(TieredVisibilityRecord::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('gabinete_id')->nullable();
            $table->unsignedBigInteger('entidade_id')->nullable();
            $table->string('titulo');
        });

        foreach ([
            ['plataforma', null, null],
            ['camara-a', null, self::ENTIDADE_A],
            ['camara-b', null, self::ENTIDADE_B],
            // O dono preenche também `entidade_id`: continua privado do gabinete.
            ['gabinete-a1', self::GABINETE_A1, self::ENTIDADE_A],
            ['gabinete-a2', self::GABINETE_A2, self::ENTIDADE_A],
            ['gabinete-b1', self::GABINETE_B1, self::ENTIDADE_B],
            ['gabinete-a1-sem-entidade', self::GABINETE_A1, null],
        ] as [$titulo, $gabineteId, $entidadeId]) {
            TieredVisibilityRecord::query()->create([
                'titulo' => $titulo,
                'gabinete_id' => $gabineteId,
                'entidade_id' => $entidadeId,
            ]);
        }
    }

    public function test_gabinete_sees_its_own_records_its_entidade_and_the_platform(): void
    {
        $this->assertSame(
            ['camara-a', 'gabinete-a1', 'gabinete-a1-sem-entidade', 'plataforma'],
            $this->titles(TieredVisibilityRecord::query()->visibleTo(self::GABINETE_A1, self::ENTIDADE_A)),
        );

        $this->assertSame(
            ['camara-b', 'gabinete-b1', 'plataforma'],
            $this->titles(TieredVisibilityRecord::query()->visibleTo(self::GABINETE_B1, self::ENTIDADE_B)),
        );
    }

    public function test_without_entidade_falls_back_to_two_tiers(): void
    {
        $this->assertSame(
            ['gabinete-a1', 'gabinete-a1-sem-entidade', 'plataforma'],
            $this->titles(TieredVisibilityRecord::query()->visibleTo(self::GABINETE_A1)),
        );
    }

    public function test_visibility_stays_grouped_when_combined_with_other_filters(): void
    {
        $this->assertSame(
            [],
            $this->titles(TieredVisibilityRecord::query()
                ->where('titulo', 'gabinete-b1')
                ->visibleTo(self::GABINETE_A1, self::ENTIDADE_A)),
        );

        $this->assertSame(
            ['camara-a'],
            $this->titles(TieredVisibilityRecord::query()
                ->where('titulo', 'like', 'camara-%')
                ->visibleTo(self::GABINETE_A2, self::ENTIDADE_A)),
        );
    }

    public function test_owned_and_shared_scopes_isolate_each_tier(): void
    {
        $this->assertSame(
            ['gabinete-a1', 'gabinete-a1-sem-entidade'],
            $this->titles(TieredVisibilityRecord::query()->ownedByGabinete(self::GABINETE_A1)),
        );

        $this->assertSame(
            ['camara-a'],
            $this->titles(TieredVisibilityRecord::query()->sharedWithEntidade(self::ENTIDADE_A)),
        );
    }

    public function test_tier_helpers(): void
    {
        $records = TieredVisibilityRecord::query()->get()->keyBy('titulo');

        foreach ([
            'plataforma' => [true, false, false],
            'camara-a' => [false, true, false],
            'gabinete-a1' => [false, false, true],
            'gabinete-a1-sem-entidade' => [false, false, true],
        ] as $titulo => [$platform, $entidadeWide, $gabinete]) {
            $record = $records[$titulo];

            $this->assertSame($platform, $record->isPlatform(), $titulo);
            $this->assertSame($entidadeWide, $record->isEntidadeWide(), $titulo);
            $this->assertSame($gabinete, $record->isGabinete(), $titulo);
        }
    }

    /**
     * @param  Builder<TieredVisibilityRecord>  $query
     * @return list<string>
     */
    private function titles($query): array
    {
        return $query->orderBy('titulo')->pluck('titulo')->all();
    }
}
