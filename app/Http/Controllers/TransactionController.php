<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\DeleteTransaction;
use App\Actions\Transactions\RecordExpense;
use App\Actions\Transactions\RecordIncome;
use App\Actions\Transactions\TransferBetweenAccounts;
use App\Actions\Transactions\UpdateTransaction;
use App\Enums\CategoryKind;
use App\Enums\TransactionType;
use App\Http\Requests\Transactions\StoreExpenseRequest;
use App\Http\Requests\Transactions\StoreIncomeRequest;
use App\Http\Requests\Transactions\StoreMoneyMovementRequest;
use App\Http\Requests\Transactions\StoreTransferRequest;
use App\Http\Requests\Transactions\UpdateTransactionRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        return Inertia::render('transactions/Index', $this->indexProps($workspace, $request));
    }

    public function income(StoreIncomeRequest $request, RecordIncome $recordIncome): RedirectResponse
    {
        $this->record($request, $recordIncome);

        return back();
    }

    public function expense(StoreExpenseRequest $request, RecordExpense $recordExpense): RedirectResponse
    {
        $this->record($request, $recordExpense);

        return back();
    }

    public function transfer(StoreTransferRequest $request, TransferBetweenAccounts $transferBetweenAccounts): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $data = $request->validated();

        $transferBetweenAccounts->execute($this->currentWorkspace($request), $user, [
            'account_id' => (int) $data['account_id'],
            'counterparty_account_id' => (int) $data['counterparty_account_id'],
            'amount' => (int) $data['amount'],
            'occurred_on' => $data['occurred_on'],
            'description' => $data['description'] ?? null,
        ]);

        return back();
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction, UpdateTransaction $updateTransaction): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        Gate::authorize('update', $transaction);

        $data = $request->validated();
        $payload = [
            'account_id' => (int) $data['account_id'],
            'amount' => (int) $data['amount'],
            'occurred_on' => $data['occurred_on'],
            'description' => $data['description'] ?? null,
        ];

        if (array_key_exists('category_id', $data)) {
            $payload['category_id'] = (int) $data['category_id'];
        }

        if (array_key_exists('counterparty_account_id', $data)) {
            $payload['counterparty_account_id'] = (int) $data['counterparty_account_id'];
        }

        $updateTransaction->execute($transaction, $user, $payload);

        return back();
    }

    public function destroy(Transaction $transaction, DeleteTransaction $deleteTransaction): RedirectResponse
    {
        Gate::authorize('delete', $transaction);

        $deleteTransaction->execute($transaction);

        return back();
    }

    private function record(StoreMoneyMovementRequest $request, RecordIncome|RecordExpense $action): void
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $data = $request->validated();

        $action->execute($this->currentWorkspace($request), $user, [
            'account_id' => (int) $data['account_id'],
            'category_id' => (int) $data['category_id'],
            'amount' => (int) $data['amount'],
            'occurred_on' => $data['occurred_on'],
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @return array{
     *     transactions: array<int, array<string, mixed>>,
     *     filters: array{month: string, account_id: int|null, category_id: int|null, type: string|null},
     *     accounts: array<int, array{id: int, name: string}>,
     *     incomeCategories: array<int, array{id: int, name: string, emoji: string, color: string}>,
     *     expenseCategories: array<int, array{id: int, name: string, emoji: string, color: string}>
     * }
     */
    private function indexProps(Workspace $workspace, Request $request): array
    {
        $month = $this->resolvedMonth($request);

        $accountId = $request->filled('account_id') ? $request->integer('account_id') : null;
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;
        $type = $this->resolvedType($request);

        $transactions = $workspace->transactions()
            ->with(['account', 'category', 'counterpartyAccount'])
            ->when($month !== 'all', function ($query) use ($month): void {
                $start = Carbon::parse($month.'-01')->startOfMonth();
                $end = $start->copy()->endOfMonth();
                $query->whereBetween('occurred_on', [$start->toDateString(), $end->toDateString()]);
            })
            ->when($accountId, fn ($query) => $query->where(function ($accountQuery) use ($accountId): void {
                $accountQuery->where('account_id', $accountId)
                    ->orWhere('counterparty_account_id', $accountId);
            }))
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($type, fn ($query) => $query->where('type', $type))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();

        $categories = $workspace->categories()->orderBy('name')->get();

        return [
            'transactions' => $transactions
                ->map(fn (Transaction $transaction): array => $this->transactionPayload($transaction))
                ->values()
                ->all(),
            'filters' => [
                'month' => $month,
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'type' => $type,
            ],
            'accounts' => $workspace->accounts()
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get()
                ->map(fn (Account $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                ])
                ->values()
                ->all(),
            'incomeCategories' => $this->categoryPayloads(
                $categories
                    ->filter(fn (Category $category): bool => $category->kind === CategoryKind::Income)
                    ->values(),
            ),
            'expenseCategories' => $this->categoryPayloads(
                $categories
                    ->filter(fn (Category $category): bool => $category->kind === CategoryKind::Expense)
                    ->values(),
            ),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     type: string,
     *     amount: int,
     *     occurred_on: string,
     *     description: string|null,
     *     account: array{id: int, name: string}|null,
     *     counterparty_account: array{id: int, name: string}|null,
     *     category: array{id: int, name: string, emoji: string, color: string}|null
     * }
     */
    private function transactionPayload(Transaction $transaction): array
    {
        $account = $transaction->account;
        $counterparty = $transaction->counterpartyAccount;
        $category = $transaction->category;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'amount' => $transaction->amount,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'description' => $transaction->description,
            'account' => $this->accountOption($account),
            'counterparty_account' => $this->accountOption($counterparty),
            'category' => $category === null ? null : [
                'id' => $category->id,
                'name' => $category->name,
                'emoji' => $category->emoji,
                'color' => $category->color,
            ],
        ];
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

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, array{id: int, name: string, emoji: string, color: string}>
     */
    private function categoryPayloads(Collection $categories): array
    {
        return $categories
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'emoji' => $category->emoji,
                'color' => $category->color,
            ])
            ->values()
            ->all();
    }

    private function resolvedMonth(Request $request): string
    {
        $month = $request->string('month')->toString();

        if ($month === 'all' || preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return $month;
        }

        return now()->format('Y-m');
    }

    private function resolvedType(Request $request): ?string
    {
        $type = $request->string('type')->toString();

        $allowed = [
            TransactionType::Income->value,
            TransactionType::Expense->value,
            TransactionType::Transfer->value,
        ];

        if (! in_array($type, $allowed, true)) {
            return null;
        }

        return $type;
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
