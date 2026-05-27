<?php

namespace App\Console\Commands;

use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TitleDiversityService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class GeoFlowDiversifyTitlesCommand extends Command
{
    protected $signature = 'geoflow:diversify-titles
        {--library= : Optional title library ID to process}
        {--dry-run : Preview the changes without saving them}';

    protected $description = 'Diversify repetitive title library entries so future generation rotates across families';

    public function __construct(
        private readonly TitleDiversityService $titleDiversityService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $libraryId = trim((string) $this->option('library'));
        $dryRun = (bool) $this->option('dry-run');

        $libraries = $this->resolveLibraries($libraryId);
        if ($libraries->isEmpty()) {
            $this->warn('No title libraries found.');

            return self::SUCCESS;
        }

        $scanned = 0;
        $updated = 0;

        foreach ($libraries as $library) {
            $libraryRecentTitles = [];
            $titles = Title::query()
                ->where('library_id', (int) $library->id)
                ->orderBy('id')
                ->get();

            foreach ($titles as $title) {
                $scanned++;
                $articleType = $this->titleDiversityService->inferArticleType((string) $title->title, (string) $title->keyword);
                $history = array_slice(array_merge($libraryRecentTitles), 0, 8);
                $rewritten = $this->titleDiversityService->rewriteTitle(
                    (string) $title->title,
                    (string) $title->keyword,
                    $articleType,
                    $history,
                    true
                );

                if ($rewritten !== (string) $title->title) {
                    $updated++;
                    if (! $dryRun) {
                        Title::query()->whereKey((int) $title->id)->update(['title' => $rewritten]);
                    }
                }

                array_unshift($libraryRecentTitles, $rewritten);
                $libraryRecentTitles = array_slice($libraryRecentTitles, 0, 8);
            }
        }

        $this->info(sprintf(
            'Title diversification done: libraries=%d, scanned=%d, updated=%d, dry_run=%s',
            $libraries->count(),
            $scanned,
            $updated,
            $dryRun ? 'yes' : 'no'
        ));

        return self::SUCCESS;
    }

    private function resolveLibraries(string $libraryId): Collection
    {
        if ($libraryId !== '') {
            $library = TitleLibrary::query()->find((int) $libraryId);

            return $library ? collect([$library]) : collect();
        }

        return TitleLibrary::query()->orderBy('id')->get();
    }
}
