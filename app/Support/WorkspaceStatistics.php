<?php

namespace App\Support;

use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

final class WorkspaceStatistics
{
    /**
     * @return array{
     *     income: int,
     *     expense: int,
     *     previousIncome: int,
     *     previousExpense: int,
     *     expenseByMonth: list<array{label: string, amount: int}>,
     *     topExpenseCategories: list<array{id: int, name: string, emoji: string, amount: int}>,
     *     forecast: int|null
     * }
     */
    public static function for(Workspace $ws, int $year, int $month): array
    {
        $selected = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $previous = $selected->copy()->subMonthNoOverflow();
        $windowStart = $selected->copy()->subMonthsNoOverflow(6)->startOfMonth();
        $windowEnd = $selected->copy()->endOfMonth();

        $totals = self::monthlyTotals($ws, $windowStart, $windowEnd);
        $selectedKey = $selected->format('Y-m');
        $previousKey = $previous->format('Y-m');

        $expenseByMonth = [];

        for ($i = 5; $i >= 0; $i--) {
            $cursor = $selected->copy()->subMonthsNoOverflow($i);
            $label = $cursor->format('Y-m');
            $expenseByMonth[] = [
                'label' => $label,
                'amount' => $totals[$label]['expense'] ?? 0,
            ];
        }

        $expense = $totals[$selectedKey]['expense'] ?? 0;

        return [
            'income' => $totals[$selectedKey]['income'] ?? 0,
            'expense' => $expense,
            'previousIncome' => $totals[$previousKey]['income'] ?? 0,
            'previousExpense' => $totals[$previousKey]['expense'] ?? 0,
            'expenseByMonth' => $expenseByMonth,
            'topExpenseCategories' => self::topExpenseCategories($ws, $selected),
            'forecast' => self::forecast($selected, $expense, $totals),
        ];
    }

    /**
     * @return array<string, array{income: int, expense: int}>
     */
    private static function monthlyTotals(Workspace $workspace, Carbon $from, Carbon $to): array
    {
        $rows = Transaction::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('type', [TransactionType::Income, TransactionType::Expense])
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('type, occurred_on, SUM(amount) as amount')
            ->groupBy('type', 'occurred_on')
            ->get();

        $totals = [];

        foreach ($rows as $transaction) {
            $label = $transaction->occurred_on->format('Y-m');

            if (! isset($totals[$label])) {
                $totals[$label] = ['income' => 0, 'expense' => 0];
            }

            $kind = $transaction->type === TransactionType::Income ? 'income' : 'expense';
            $totals[$label][$kind] += (int) $transaction->amount;
        }

        return $totals;
    }

    /**
     * @return list<array{id: int, name: string, emoji: string, amount: int}>
     */
    private static function topExpenseCategories(Workspace $workspace, Carbon $month): array
    {
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $rows = Transaction::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', TransactionType::Expense)
            ->whereNotNull('category_id')
            ->whereBetween('occurred_on', [$from, $to])
            ->selectRaw('category_id, SUM(amount) as amount')
            ->groupBy('category_id')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $categories = Category::query()
            ->whereIn('id', $rows->pluck('category_id'))
            ->get()
            ->keyBy('id');

        $top = [];

        foreach ($rows as $row) {
            $category = $categories->get($row->category_id);

            if (! $category instanceof Category) {
                continue;
            }

            $top[] = [
                'id' => $category->id,
                'name' => $category->name,
                'emoji' => $category->emoji,
                'amount' => (int) $row->amount,
            ];
        }

        return $top;
    }

    /**
     * @param  array<string, array{income: int, expense: int}>  $totals
     */
    private static function forecast(Carbon $selected, int $spent, array $totals): ?int
    {
        $now = Carbon::now();

        if ($now->year !== $selected->year || $now->month !== $selected->month) {
            return null;
        }

        $remainingDays = $now->daysInMonth - $now->day;
        $dailyAvg = self::previousDailyAverage($selected, $totals);

        if ($dailyAvg === null) {
            $dailyAvg = $spent / max($now->day, 1);
        }

        return $spent + (int) round($dailyAvg * $remainingDays);
    }

    /**
     * @param  array<string, array{income: int, expense: int}>  $totals
     */
    private static function previousDailyAverage(Carbon $selected, array $totals): ?float
    {
        $total = 0;
        $days = 0;

        for ($i = 1; $i <= 6; $i++) {
            $cursor = $selected->copy()->subMonthsNoOverflow($i);
            $amount = $totals[$cursor->format('Y-m')]['expense'] ?? 0;

            if ($amount === 0) {
                continue;
            }

            $total += $amount;
            $days += $cursor->daysInMonth;
        }

        if ($days === 0) {
            return null;
        }

        return $total / $days;
    }
}
