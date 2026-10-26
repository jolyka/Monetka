<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\BudgetRecommendation;
use App\Models\Category;
use App\Models\CategoryLimit;
use App\Models\Debt;
use App\Models\Goal;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\Workspace;
use App\Support\AccountBalance;
use App\Support\LimitStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        $now = now();
        $accounts = $workspace->accounts()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        return Inertia::render('Overview', [
            'total' => $this->total($accounts),
            'hasAccounts' => $accounts->isNotEmpty(),
            'expenseLine' => $this->expenseLine($workspace, $now),
            'limits' => $this->limitPayloads($workspace, $now->year, $now->month),
            'goals' => $this->goalPayloads($workspace->goals()->orderBy('name')->get()),
            'debts' => $this->debtPayloads($workspace->debts()->orderBy('counterparty_name')->get()),
            'upcomingRecurrences' => $this->recurrencePayloads(
                $workspace->recurrences()
                    ->where('is_active', true)
                    ->orderBy('next_occurred_on')
                    ->get(),
            ),
            'recommendations' => $this->recommendationPayloads($workspace, $now),
        ]);
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function total(Collection $accounts): int
    {
        return (int) $accounts->sum(fn (Account $account): int => AccountBalance::for($account));
    }

    /**
     * @return list<array{date: string, amount: int}>
     */
    private function expenseLine(Workspace $workspace, CarbonInterface $now): array
    {
        $start = $now->toMutable()->startOfMonth();
        $end = $now->toMutable()->endOfMonth();

        $sums = Transaction::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', TransactionType::Expense)
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('occurred_on, SUM(amount) as amount')
            ->groupBy('occurred_on')
            ->get()
            ->mapWithKeys(fn (Transaction $row): array => [
                $row->occurred_on->toDateString() => (int) $row->amount,
            ]);

        $line = [];
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $line[] = [
                'date' => $date,
                'amount' => (int) ($sums[$date] ?? 0),
            ];
            $cursor->addDay();
        }

        return $line;
    }

    /**
     * @return array<int, array{id: int, category_id: int, category_name: string, category_emoji: string, category_color: string, amount: int, spent: int, exceeded: bool}>
     */
    private function limitPayloads(Workspace $workspace, int $year, int $month): array
    {
        $limits = $workspace->categoryLimits()
            ->with('category')
            ->get()
            ->sortBy(fn (CategoryLimit $limit): string => $limit->category->name);

        return $limits
            ->map(function (CategoryLimit $limit) use ($year, $month): array {
                $status = LimitStatus::for($limit, $year, $month);
                $category = $limit->category;

                if (! $category instanceof Category) {
                    abort(404);
                }

                return [
                    'id' => $limit->id,
                    'category_id' => $limit->category_id,
                    'category_name' => $category->name,
                    'category_emoji' => $category->emoji,
                    'category_color' => $category->color,
                    'amount' => $status['amount'],
                    'spent' => $status['spent'],
                    'exceeded' => $status['exceeded'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Goal>  $goals
     * @return array<int, array{id: int, name: string, target_amount: int, target_date: string|null, notes: string|null, progress: int}>
     */
    private function goalPayloads(Collection $goals): array
    {
        return $goals
            ->map(fn (Goal $goal): array => [
                'id' => $goal->id,
                'name' => $goal->name,
                'target_amount' => $goal->target_amount,
                'target_date' => $goal->target_date?->toDateString(),
                'notes' => $goal->notes,
                'progress' => $goal->progress(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Debt>  $debts
     * @return array<int, array{id: int, direction: string, counterparty_name: string, original_amount: int, notes: string|null, remainder: int}>
     */
    private function debtPayloads(Collection $debts): array
    {
        return $debts
            ->map(fn (Debt $debt): array => [
                'id' => $debt->id,
                'direction' => $debt->direction->value,
                'counterparty_name' => $debt->counterparty_name,
                'original_amount' => $debt->original_amount,
                'notes' => $debt->notes,
                'remainder' => $debt->remainder(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Recurrence>  $recurrences
     * @return array<int, array{id: int, type: string, amount: int, description: string|null, next_occurred_on: string}>
     */
    private function recurrencePayloads(Collection $recurrences): array
    {
        return $recurrences
            ->map(fn (Recurrence $recurrence): array => [
                'id' => $recurrence->id,
                'type' => $recurrence->type->value,
                'amount' => $recurrence->amount,
                'description' => $recurrence->description,
                'next_occurred_on' => $recurrence->next_occurred_on->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{kind: string, label: string, body: string, date: string}>
     */
    private function recommendationPayloads(Workspace $workspace, CarbonInterface $now): array
    {
        $start = $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $end = $now->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        return $workspace->budgetRecommendations()
            ->whereBetween('recommended_on', [$start, $end])
            ->orderBy('recommended_on')
            ->get()
            ->map(fn (BudgetRecommendation $recommendation): array => [
                'kind' => $recommendation->kind->value,
                'label' => $recommendation->kind->label(),
                'body' => $recommendation->body,
                'date' => $recommendation->recommended_on->toDateString(),
            ])
            ->values()
            ->all();
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        return $workspace;
    }
}
