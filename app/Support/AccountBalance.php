<?php

namespace App\Support;

use App\Enums\DebtDirection;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;

final class AccountBalance
{
    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function forWorkspace(int $workspaceId): \Illuminate\Support\Collection
    {
        $rows = Transaction::query()
            ->leftJoin('debts', 'debts.id', '=', 'transactions.debt_id')
            ->where('transactions.workspace_id', $workspaceId)
            ->whereNotNull('transactions.account_id')
            ->selectRaw('transactions.account_id, SUM(CASE
                WHEN transactions.type IN (?, ?, ?) THEN transactions.amount
                WHEN transactions.type IN (?, ?, ?) THEN -transactions.amount
                WHEN transactions.type = ? AND debts.direction = ? THEN transactions.amount
                WHEN transactions.type = ? AND debts.direction = ? THEN -transactions.amount
                ELSE 0 END) AS balance', [
                TransactionType::Income->value, TransactionType::Adjustment->value, TransactionType::GoalWithdrawal->value,
                TransactionType::Expense->value, TransactionType::Transfer->value, TransactionType::GoalContribution->value,
                TransactionType::DebtRepayment->value, DebtDirection::TheyOwe->value,
                TransactionType::DebtRepayment->value, DebtDirection::IOwe->value,
            ])
            ->groupBy('transactions.account_id')
            ->get();

        $balances = $rows->mapWithKeys(fn ($row): array => [$row->account_id => (int) $row->balance]);
        $incoming = Transaction::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', TransactionType::Transfer)
            ->whereNotNull('counterparty_account_id')
            ->selectRaw('counterparty_account_id, SUM(amount) AS balance')
            ->groupBy('counterparty_account_id')
            ->get();

        foreach ($incoming as $row) {
            $balances[$row->counterparty_account_id] = ($balances[$row->counterparty_account_id] ?? 0) + (int) $row->balance;
        }

        return $balances;
    }

    public static function for(Account $account): int
    {
        $accountId = $account->id;

        $sum = Transaction::query()
            ->leftJoin('debts', 'debts.id', '=', 'transactions.debt_id')
            ->where('transactions.workspace_id', $account->workspace_id)
            ->where(function ($query) use ($accountId): void {
                $query->where('transactions.account_id', $accountId)
                    ->orWhere('transactions.counterparty_account_id', $accountId);
            })
            ->selectRaw(
                'SUM(CASE
                    WHEN transactions.type = ? AND transactions.account_id = ? THEN transactions.amount
                    WHEN transactions.type = ? AND transactions.account_id = ? THEN -transactions.amount
                    WHEN transactions.type = ? AND transactions.account_id = ? THEN -transactions.amount
                    WHEN transactions.type = ? AND transactions.counterparty_account_id = ? THEN transactions.amount
                    WHEN transactions.type = ? AND transactions.account_id = ? THEN transactions.amount
                    WHEN transactions.type = ? AND transactions.account_id = ? THEN -transactions.amount
                    WHEN transactions.type = ? AND transactions.account_id = ? THEN transactions.amount
                    WHEN transactions.type = ? AND transactions.account_id = ? AND debts.direction = ? THEN transactions.amount
                    WHEN transactions.type = ? AND transactions.account_id = ? AND debts.direction = ? THEN -transactions.amount
                    WHEN transactions.type = ? THEN 0
                    ELSE 0
                END) as balance',
                [
                    TransactionType::Income->value,
                    $accountId,
                    TransactionType::Expense->value,
                    $accountId,
                    TransactionType::Transfer->value,
                    $accountId,
                    TransactionType::Transfer->value,
                    $accountId,
                    TransactionType::Adjustment->value,
                    $accountId,
                    TransactionType::GoalContribution->value,
                    $accountId,
                    TransactionType::GoalWithdrawal->value,
                    $accountId,
                    TransactionType::DebtRepayment->value,
                    $accountId,
                    DebtDirection::TheyOwe->value,
                    TransactionType::DebtRepayment->value,
                    $accountId,
                    DebtDirection::IOwe->value,
                    TransactionType::DebtRepayment->value,
                ],
            )
            ->value('balance');

        return (int) ($sum ?? 0);
    }
}
