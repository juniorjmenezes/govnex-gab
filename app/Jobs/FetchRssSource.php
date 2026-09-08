<?php

namespace App\Jobs;

use App\Models\FonteRss;
use App\Services\News\RssFeedFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Coleta um feed. Um job por fonte para que um portal fora do ar não
 * atrase os demais.
 */
class FetchRssSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(private readonly int $fonteId) {}

    public function handle(RssFeedFetcher $fetcher): void
    {
        $fonte = FonteRss::query()->find($this->fonteId);

        if (! $fonte instanceof FonteRss || ! $fonte->ativo) {
            return;
        }

        $fetcher->fetch($fonte);
    }

    public function uniqueId(): string
    {
        return (string) $this->fonteId;
    }
}
