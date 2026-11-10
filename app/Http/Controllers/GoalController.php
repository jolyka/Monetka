<?php

namespace App\Http\Controllers;

use App\Actions\Goals\ContributeToGoal;
use App\Actions\Goals\CreateGoal;
use App\Actions\Goals\DeleteGoal;
use App\Actions\Goals\UpdateGoal;
use App\Actions\Goals\WithdrawFromGoal;
use App\Http\Requests\Goals\MoveGoalMoneyRequest;
use App\Http\Requests\Goals\StoreGoalRequest;
use App\Http\Requests\Goals\UpdateGoalRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        $goals = $workspace->goals()->withProgress()->orderBy('name')->get();

        return Inertia::render('goals/Index', [
            'goals' => $this->payloads($goals),
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

    public function store(StoreGoalRequest $request, CreateGoal $createGoal): RedirectResponse
    {
        $data = $request->validated();

        $createGoal->execute($this->currentWorkspace($request), [
            'name' => $data['name'],
            'target_amount' => (int) $data['target_amount'],
            'target_date' => $data['target_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('goals.index');
    }

    public function update(UpdateGoalRequest $request, Goal $goal, UpdateGoal $updateGoal): RedirectResponse
    {
        Gate::authorize('update', $goal);
        $this->assertInWorkspace($request, $goal->workspace_id);

        $data = $request->validated();

        $updateGoal->execute($goal, [
            'name' => $data['name'],
            'target_amount' => (int) $data['target_amount'],
            'target_date' => $data['target_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back();
    }

    public function destroy(Request $request, Goal $goal, DeleteGoal $deleteGoal): RedirectResponse
    {
        Gate::authorize('delete', $goal);
        $this->assertInWorkspace($request, $goal->workspace_id);

        $deleteGoal->execute($goal);

        return back();
    }

    public function contribute(MoveGoalMoneyRequest $request, Goal $goal, ContributeToGoal $contributeToGoal): RedirectResponse
    {
        $this->moveMoney($request, $goal, $contributeToGoal);

        return back();
    }

    public function withdraw(MoveGoalMoneyRequest $request, Goal $goal, WithdrawFromGoal $withdrawFromGoal): RedirectResponse
    {
        $this->moveMoney($request, $goal, $withdrawFromGoal);

        return back();
    }

    private function moveMoney(MoveGoalMoneyRequest $request, Goal $goal, ContributeToGoal|WithdrawFromGoal $action): void
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        Gate::authorize('update', $goal);
        $this->assertInWorkspace($request, $goal->workspace_id);

        $data = $request->validated();
        $account = Account::query()->findOrFail((int) $data['account_id']);

        $action->execute(
            $goal,
            $user,
            $account,
            (int) $data['amount'],
            $data['occurred_on'],
        );
    }

    /**
     * @param  Collection<int, Goal>  $goals
     * @return array<int, array{id: int, name: string, target_amount: int, target_date: string|null, notes: string|null, progress: int}>
     */
    private function payloads(Collection $goals): array
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
