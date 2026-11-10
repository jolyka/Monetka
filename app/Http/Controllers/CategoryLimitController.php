<?php

namespace App\Http\Controllers;

use App\Actions\Limits\DeleteCategoryLimit;
use App\Actions\Limits\UpsertCategoryLimit;
use App\Enums\CategoryKind;
use App\Http\Requests\Limits\StoreCategoryLimitRequest;
use App\Http\Requests\Limits\UpdateCategoryLimitRequest;
use App\Models\Category;
use App\Models\CategoryLimit;
use App\Models\Workspace;
use App\Support\LimitStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CategoryLimitController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        $now = now();
        $year = $now->year;
        $month = $now->month;

        $limits = $workspace->categoryLimits()
            ->with('category')
            ->get()
            ->sortBy(fn (CategoryLimit $limit): string => $limit->category?->name ?? '');

        return Inertia::render('limits/Index', [
            'year' => $year,
            'month' => $month,
            'limits' => $this->payloads($limits, $workspace, $year, $month),
            'categories' => $workspace->categories()
                ->where('kind', CategoryKind::Expense)
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'emoji' => $category->emoji,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreCategoryLimitRequest $request, UpsertCategoryLimit $upsertCategoryLimit): RedirectResponse
    {
        $data = $request->validated();
        $workspace = $this->currentWorkspace($request);
        $category = Category::query()->findOrFail((int) $data['category_id']);

        $upsertCategoryLimit->execute($workspace, $category, (int) $data['amount']);

        return redirect()->route('limits.index');
    }

    public function update(UpdateCategoryLimitRequest $request, CategoryLimit $categoryLimit, UpsertCategoryLimit $upsertCategoryLimit): RedirectResponse
    {
        Gate::authorize('update', $categoryLimit);
        $this->assertInWorkspace($request, $categoryLimit->workspace_id);

        $data = $request->validated();
        $category = $categoryLimit->category()->firstOrFail();

        $upsertCategoryLimit->execute(
            $this->currentWorkspace($request),
            $category,
            (int) $data['amount'],
        );

        return back();
    }

    public function destroy(Request $request, CategoryLimit $categoryLimit, DeleteCategoryLimit $deleteCategoryLimit): RedirectResponse
    {
        Gate::authorize('delete', $categoryLimit);
        $this->assertInWorkspace($request, $categoryLimit->workspace_id);

        $deleteCategoryLimit->execute($categoryLimit);

        return back();
    }

    /**
     * @param  Collection<int, CategoryLimit>  $limits
     * @return array<int, array{id: int, category_id: int, category_name: string, category_emoji: string, category_color: string, amount: int, spent: int, exceeded: bool}>
     */
    private function payloads(Collection $limits, Workspace $workspace, int $year, int $month): array
    {
        $spending = LimitStatus::spendingByCategory($workspace->id, $year, $month);

        return $limits
            ->map(function (CategoryLimit $limit) use ($spending): array {
                $status = LimitStatus::fromSpent($limit, (int) $spending->get($limit->category_id, 0));
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
