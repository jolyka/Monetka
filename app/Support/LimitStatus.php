<?php

namespace App\Support;

use App\Enums\TransactionType;
use App\Models\CategoryLimit;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

final class LimitStatus
{
    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function spendingByCategory(int $workspaceId, int $year, int $month): \Illuminate\Support\Collection
    {
        $from = Carbon::createFromDate($year, $month, 1)->startOfMonth();

        return Transaction::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', TransactionType::Expense)
            ->whereBetween('occurred_on', [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()])
            ->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(amount) as spent')
            ->groupBy('category_id')
            ->pluck('spent', 'category_id');
    }

    /** @return array{spent: int, amount: int, exceeded: bool} */
    public static function fromSpent(CategoryLimit $limit, int $spent): array
    {
        $amount = (int) $limit->amount;

        return ['spent' => $spent, 'amount' => $amount, 'exceeded' => $spent > $amount];
    }

    /**
     * @return array{spent: int, amount: int, exceeded: bool}
     */
    public static function for(CategoryLimit $limit, int $year, int $month): array
    {
        $from = Carbon::createFromDate($year, $month, 1)->toDateString();
        $to = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $spent = (int) Transaction::query()
            ->where('workspace_id', $limit->workspace_id)
            ->where('category_id', $limit->category_id)
            ->where('type', TransactionType::Expense)
            ->whereBetween('occurred_on', [$from, $to])
            ->sum('amount');

        $amount = (int) $limit->amount;

        return [
            'spent' => $spent,
            'amount' => $amount,
            'exceeded' => $spent > $amount,
        ];
    }
}
