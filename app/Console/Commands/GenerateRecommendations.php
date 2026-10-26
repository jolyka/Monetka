<?php

namespace App\Console\Commands;

use App\Actions\Recommendations\GenerateDailyRecommendation;
use App\Models\BudgetRecommendation;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateRecommendations extends Command
{
    protected $signature = 'recommendations:generate';

    protected $description = 'Собрать ежедневную рекомендацию по каждому бюджету';

    public function handle(GenerateDailyRecommendation $generate): int
    {
        $created = 0;

        Workspace::query()->orderBy('id')->each(function (Workspace $workspace) use ($generate, &$created): void {
            try {
                $recommendation = $generate->execute($workspace);
            } catch (Throwable $exception) {
                Log::warning('Daily recommendation failed', [
                    'workspace_id' => $workspace->id,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            if ($recommendation?->wasRecentlyCreated) {
                $created++;
            }
        });

        $deleted = BudgetRecommendation::query()
            ->where('recommended_on', '<', now()->subDays(21)->toDateString())
            ->delete();

        $this->info("Новых рекомендаций: {$created}. Удалено старых: {$deleted}.");

        return self::SUCCESS;
    }
}
