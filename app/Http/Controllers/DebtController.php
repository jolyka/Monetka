<?php

namespace App\Http\Controllers;

use App\Actions\Debts\CreateDebt;
use App\Actions\Debts\DeleteDebt;
use App\Actions\Debts\RepayDebt;
use App\Actions\Debts\UpdateDebt;
use App\Http\Requests\Debts\RepayDebtRequest;
use App\Http\Requests\Debts\StoreDebtRequest;
use App\Http\Requests\Debts\UpdateDebtRequest;
use App\Models\Account;
use App\Models\Debt;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DebtController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        $debts = $workspace->debts()->withRepaid()->orderBy('counterparty_name')->get();

        return Inertia::render('debts/Index', [
            'debts' => $this->payloads($debts),
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
        ]);
    }

    public function store(StoreDebtRequest $request, CreateDebt $createDebt): RedirectResponse
    {
        $data = $request->validated();

        $createDebt->execute($this->currentWorkspace($request), [
            'direction' => $data['direction'],
            'counterparty_name' => $data['counterparty_name'],
            'original_amount' => (int) $data['original_amount'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('debts.index');
    }

    public function update(UpdateDebtRequest $request, Debt $debt, UpdateDebt $updateDebt): RedirectResponse
    {
        Gate::authorize('update', $debt);
        $this->assertInWorkspace($request, $debt->workspace_id);

        $data = $request->validated();

        $updateDebt->execute($debt, [
            'direction' => $data['direction'],
            'counterparty_name' => $data['counterparty_name'],
            'original_amount' => (int) $data['original_amount'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back();
    }

    public function destroy(Request $request, Debt $debt, DeleteDebt $deleteDebt): RedirectResponse
    {
        Gate::authorize('delete', $debt);
        $this->assertInWorkspace($request, $debt->workspace_id);

        $deleteDebt->execute($debt);

        return back();
    }

    public function repay(RepayDebtRequest $request, Debt $debt, RepayDebt $repayDebt): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        Gate::authorize('update', $debt);
        $this->assertInWorkspace($request, $debt->workspace_id);

        $data = $request->validated();
        $account = isset($data['account_id'])
            ? Account::query()->findOrFail((int) $data['account_id'])
            : null;

        $repayDebt->execute(
            $debt,
            $user,
            (int) $data['amount'],
            $account,
            $data['occurred_on'],
            $data['description'] ?? '',
        );

        return back();
    }

    /**
     * @param  Collection<int, Debt>  $debts
     * @return array<int, array{id: int, direction: string, counterparty_name: string, original_amount: int, notes: string|null, remainder: int}>
     */
    private function payloads(Collection $debts): array
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
