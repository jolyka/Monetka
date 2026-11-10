<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SetAccountBalance;
use App\Actions\Accounts\UpdateAccount;
use App\Enums\DebtDirection;
use App\Enums\TransactionType;
use App\Http\Requests\Accounts\SetAccountBalanceRequest;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Transaction;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AccountBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        return Inertia::render('accounts/Index', $this->indexProps($workspace));
    }

    public function show(Request $request, Account $account): Response
    {
        Gate::authorize('view', $account);
        $workspace = $this->assertInWorkspace($request, $account->workspace_id);

        return Inertia::render('accounts/Show', $this->showProps($workspace, $account, $request));
    }

    public function store(StoreAccountRequest $request, CreateAccount $createAccount): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $data = $request->validated();

        $createAccount->execute($this->currentWorkspace($request), $user, [
            'name' => $data['name'],
            'type' => $data['type'],
            'bank_id' => $data['bank_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'opening_balance' => (int) $data['opening_balance'],
        ]);

        return redirect()->route('accounts.index');
    }

    public function update(UpdateAccountRequest $request, Account $account, UpdateAccount $updateAccount): RedirectResponse
    {
        Gate::authorize('update', $account);
        $this->assertInWorkspace($request, $account->workspace_id);

        $data = $request->validated();

        $updateAccount->execute($account, [
            'name' => $data['name'],
            'type' => $data['type'],
            'bank_id' => $data['bank_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
        ]);

        return back();
    }

    public function setBalance(SetAccountBalanceRequest $request, Account $account, SetAccountBalance $setAccountBalance): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        Gate::authorize('update', $account);
        $this->assertInWorkspace($request, $account->workspace_id);

        $data = $request->validated();

        $setAccountBalance->execute(
            $account,
            $user,
            (int) $data['amount'],
            $data['occurred_on'],
        );

        return back();
    }

    public function archive(Request $request, Account $account, ArchiveAccount $archiveAccount): RedirectResponse
    {
        Gate::authorize('update', $account);
        $this->assertInWorkspace($request, $account->workspace_id);

        $archiveAccount->execute($account);

        return back();
    }

    public function destroy(Request $request, Account $account, DeleteAccount $deleteAccount): RedirectResponse
    {
        Gate::authorize('delete', $account);
        $this->assertInWorkspace($request, $account->workspace_id);

        $deleteAccount->execute($account);

        return back();
    }

    /**
     * @return array{
     *     groups: array<int, array<string, mixed>>,
     *     banks: array<int, array{id: int, name: string, color: string|null}>,
     *     members: array<int, array{id: int, name: string}>
     * }
     */
    private function indexProps(Workspace $workspace): array
    {
        $accounts = $workspace->accounts()
            ->whereNull('archived_at')
            ->with(['user'])
            ->orderBy('name')
            ->get();

        $balances = AccountBalance::forWorkspace($workspace->id);

        $banks = $workspace->banks()->orderBy('name')->get();

        $groups = $banks
            ->map(fn (Bank $bank): array => $this->groupPayload(
                $bank->id,
                $bank->name,
                $bank->color,
                $accounts->where('bank_id', $bank->id)->values(),
                $balances,
            ))
            ->values()
            ->all();

        $groups[] = $this->groupPayload(
            null,
            'Без банка',
            null,
            $accounts->whereNull('bank_id')->values(),
            $balances,
        );

        return [
            'groups' => $groups,
            'banks' => $banks
                ->map(fn (Bank $bank): array => [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'color' => $bank->color,
                ])
                ->values()
                ->all(),
            'members' => $workspace->memberships()
                ->with('user')
                ->get()
                ->map(function (WorkspaceMembership $membership): ?array {
                    $member = $membership->user;

                    if ($member === null) {
                        return null;
                    }

                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     account: array{id: int, name: string, type: string, balance: int},
     *     transactions: array<int, array<string, mixed>>,
     *     filters: array{month: string}
     * }
     */
    private function showProps(Workspace $workspace, Account $account, Request $request): array
    {
        $month = $this->resolvedMonth($request);
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $accountId = $account->id;

        $transactions = $workspace->transactions()
            ->with(['account', 'category', 'counterpartyAccount', 'debt'])
            ->where(function ($query) use ($accountId): void {
                $query->where('account_id', $accountId)
                    ->orWhere('counterparty_account_id', $accountId);
            })
            ->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'balance' => AccountBalance::for($account),
            ],
            'transactions' => $transactions
                ->map(fn (Transaction $transaction): array => $this->historyPayload($transaction, $accountId))
                ->values()
                ->all(),
            'filters' => [
                'month' => $month,
            ],
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     type: string,
     *     amount: int,
     *     effect: int,
     *     occurred_on: string,
     *     description: string|null,
     *     account: array{id: int, name: string}|null,
     *     counterparty_account: array{id: int, name: string}|null,
     *     category: array{id: int, name: string, emoji: string, color: string}|null
     * }
     */
    private function historyPayload(Transaction $transaction, int $accountId): array
    {
        $ledgerAccount = $transaction->account;
        $counterparty = $transaction->counterpartyAccount;
        $category = $transaction->category;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'amount' => $transaction->amount,
            'effect' => $this->effectFor($transaction, $accountId),
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'description' => $transaction->description,
            'account' => $this->accountOption($ledgerAccount),
            'counterparty_account' => $this->accountOption($counterparty),
            'category' => $category === null ? null : [
                'id' => $category->id,
                'name' => $category->name,
                'emoji' => $category->emoji,
                'color' => $category->color,
            ],
        ];
    }

    private function effectFor(Transaction $transaction, int $accountId): int
    {
        $amount = $transaction->amount;

        return match ($transaction->type) {
            TransactionType::Income => $transaction->account_id === $accountId ? $amount : 0,
            TransactionType::Expense => $transaction->account_id === $accountId ? -$amount : 0,
            TransactionType::Transfer => $transaction->account_id === $accountId
                ? -$amount
                : ($transaction->counterparty_account_id === $accountId ? $amount : 0),
            TransactionType::Adjustment => $transaction->account_id === $accountId ? $amount : 0,
            TransactionType::GoalContribution => $transaction->account_id === $accountId ? -$amount : 0,
            TransactionType::GoalWithdrawal => $transaction->account_id === $accountId ? $amount : 0,
            TransactionType::DebtRepayment => $this->debtRepaymentEffect($transaction, $accountId),
        };
    }

    private function debtRepaymentEffect(Transaction $transaction, int $accountId): int
    {
        if ($transaction->account_id !== $accountId) {
            return 0;
        }

        return match ($transaction->debt?->direction) {
            DebtDirection::TheyOwe => $transaction->amount,
            DebtDirection::IOwe => -$transaction->amount,
            default => 0,
        };
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function accountOption(?Account $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'id' => $account->id,
            'name' => $account->name,
        ];
    }

    private function resolvedMonth(Request $request): string
    {
        $month = $request->string('month')->toString();

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return now()->format('Y-m');
        }

        return $month;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int, int>  $balances
     * @return array{id: int|null, name: string, color: string|null, total: int, shared: array<int, array<string, mixed>>, personal: array<int, array<string, mixed>>}
     */
    private function groupPayload(?int $id, string $name, ?string $color, Collection $accounts, Collection $balances): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'color' => $color,
            'total' => $accounts->sum(fn (Account $account): int => (int) $balances->get($account->id, 0)),
            'shared' => $this->accountPayloads($accounts->whereNull('user_id')->values(), $balances),
            'personal' => $this->accountPayloads($accounts->whereNotNull('user_id')->values(), $balances),
        ];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int, int>  $balances
     * @return array<int, array{id: int, name: string, type: string, user_id: int|null, owner_name: string|null, balance: int}>
     */
    private function accountPayloads(Collection $accounts, Collection $balances): array
    {
        return $accounts
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'user_id' => $account->user_id,
                'owner_name' => $account->user?->name,
                'balance' => (int) $balances->get($account->id, 0),
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

    private function assertInWorkspace(Request $request, int $workspaceId): Workspace
    {
        $workspace = $this->currentWorkspace($request);
        abort_unless($workspaceId === $workspace->id, 404);

        return $workspace;
    }
}
