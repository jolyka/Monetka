<?php

use App\Actions\Accounts\CreateAccount;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\DebtDirection;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountBalance;

it('matches individual balances for all ledger transaction types', function () {
    $user = User::factory()->create();
    $workspace = (new CreateWorkspace)->execute($user, 'Семья', 'RUB');
    $create = new CreateAccount;
    $data = ['type' => 'checking', 'bank_id' => null, 'user_id' => null, 'opening_balance' => 0];
    $first = $create->execute($workspace, $user, ['name' => 'First', ...$data]);
    $second = $create->execute($workspace, $user, ['name' => 'Second', ...$data]);
    $debts = [];
    foreach ([DebtDirection::TheyOwe, DebtDirection::IOwe] as $direction) {
        $debts[] = Debt::query()->create(['workspace_id' => $workspace->id, 'direction' => $direction, 'counterparty_name' => 'Test', 'original_amount' => 1000]);
    }
    foreach (TransactionType::cases() as $type) {
        $base = ['workspace_id' => $workspace->id, 'type' => $type, 'amount' => 100, 'occurred_on' => '2026-09-01', 'account_id' => $first->id];
        if ($type === TransactionType::Transfer) {
            $base['counterparty_account_id'] = $second->id;
        }
        if ($type === TransactionType::DebtRepayment) {
            foreach ($debts as $debt) {
                Transaction::query()->create([...$base, 'debt_id' => $debt->id]);
            }
        } else {
            Transaction::query()->create($base);
        }
    }
    Transaction::query()->create(['workspace_id' => $workspace->id, 'type' => TransactionType::Adjustment, 'amount' => -30, 'occurred_on' => '2026-09-01', 'account_id' => $second->id]);

    $balances = AccountBalance::forWorkspace($workspace->id);
    expect($balances->get($first->id, 0))->toBe(AccountBalance::for($first))
        ->and($balances->get($second->id, 0))->toBe(AccountBalance::for($second));
});
