<?php

namespace App\Support;

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\CategoryLimit;
use App\Models\Debt;
use App\Models\Goal;
use App\Models\Recurrence;
use App\Models\Workspace;
use Carbon\CarbonInterface;

final class AssistantSnapshot
{
    /**
     * Снимок, который уйдёт в модель. Не содержит операций, паролей, email и чужих бюджетов.
     *
     * @return array{
     *     month: string,
     *     month_label: string,
     *     currency: string,
     *     workspace_name: string,
     *     total_visible: int,
     *     accounts: list<array{id: int, name: string, balance: int}>,
     *     income: int,
     *     expense: int,
     *     previous_expense: int,
     *     forecast: int|null,
     *     top_expense_categories: list<array{name: string, emoji: string, amount: int}>,
     *     limits: list<array{category: string, amount: int, spent: int, remaining: int, exceeded: bool}>,
     *     goals: list<array{name: string, progress: int, target: int, date: string|null}>,
     *     debts: list<array{direction: string, name: string, remainder: int}>,
     *     recurrences: list<array{description: string|null, type: string, amount: int, next_date: string}>,
     *     expense_categories: list<array{id: int, name: string, emoji: string}>,
     *     income_categories: list<array{id: int, name: string, emoji: string}>,
     *     banks: list<array{id: int, name: string}>,
     *     today: string
     * }
     */
    public static function for(Workspace $workspace, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $stats = WorkspaceStatistics::for($workspace, $now->year, $now->month);
        $accounts = $workspace->accounts()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        return [
            'today' => $now->toDateString(),
            'month' => $now->format('Y-m'),
            'month_label' => mb_ucfirst($now->locale('ru')->isoFormat('MMMM YYYY')),
            'currency' => $workspace->currency,
            'workspace_name' => $workspace->name,
            'total_visible' => (int) $accounts->sum(fn (Account $account): int => AccountBalance::for($account)),
            'accounts' => $accounts
                ->map(fn (Account $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'balance' => AccountBalance::for($account),
                ])
                ->values()
                ->all(),
            'income' => $stats['income'],
            'expense' => $stats['expense'],
            'previous_expense' => $stats['previousExpense'],
            'forecast' => $stats['forecast'],
            'top_expense_categories' => array_map(
                fn (array $row): array => [
                    'name' => $row['name'],
                    'emoji' => $row['emoji'],
                    'amount' => $row['amount'],
                ],
                $stats['topExpenseCategories'],
            ),
            'limits' => self::limits($workspace, $now->year, $now->month),
            'goals' => $workspace->goals()
                ->orderBy('name')
                ->get()
                ->map(fn (Goal $goal): array => [
                    'name' => $goal->name,
                    'progress' => $goal->progress(),
                    'target' => $goal->target_amount,
                    'date' => $goal->target_date?->toDateString(),
                ])
                ->values()
                ->all(),
            'debts' => $workspace->debts()
                ->orderBy('counterparty_name')
                ->get()
                ->map(fn (Debt $debt): array => [
                    'direction' => $debt->direction->value,
                    'name' => $debt->counterparty_name,
                    'remainder' => $debt->remainder(),
                ])
                ->values()
                ->all(),
            'recurrences' => $workspace->recurrences()
                ->where('is_active', true)
                ->orderBy('next_occurred_on')
                ->get()
                ->map(fn (Recurrence $recurrence): array => [
                    'description' => $recurrence->description,
                    'type' => $recurrence->type->value,
                    'amount' => $recurrence->amount,
                    'next_date' => $recurrence->next_occurred_on->toDateString(),
                ])
                ->values()
                ->all(),
            'expense_categories' => self::categories($workspace, CategoryKind::Expense),
            'income_categories' => self::categories($workspace, CategoryKind::Income),
            'banks' => $workspace->banks()
                ->orderBy('name')
                ->get()
                ->map(fn (Bank $bank): array => [
                    'id' => $bank->id,
                    'name' => $bank->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, emoji: string}>
     */
    private static function categories(Workspace $workspace, CategoryKind $kind): array
    {
        return $workspace->categories()
            ->where('kind', $kind)
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'emoji' => $category->emoji,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{category: string, amount: int, spent: int, remaining: int, exceeded: bool}>
     */
    private static function limits(Workspace $workspace, int $year, int $month): array
    {
        return $workspace->categoryLimits()
            ->with('category')
            ->get()
            ->sortBy(fn (CategoryLimit $limit): string => $limit->category->name)
            ->map(function (CategoryLimit $limit) use ($year, $month): array {
                $status = LimitStatus::for($limit, $year, $month);
                $category = $limit->category;

                if (! $category instanceof Category) {
                    abort(404);
                }

                return [
                    'category' => $category->name,
                    'amount' => $status['amount'],
                    'spent' => $status['spent'],
                    'remaining' => $status['amount'] - $status['spent'],
                    'exceeded' => $status['exceeded'],
                ];
            })
            ->values()
            ->all();
    }
}
