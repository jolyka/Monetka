<?php

namespace App\Models;

use App\Enums\RecommendationKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property RecommendationKind $kind
 * @property string $body
 * @property Carbon $recommended_on
 */
#[Fillable(['workspace_id', 'kind', 'body', 'recommended_on'])]
class BudgetRecommendation extends Model
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RecommendationKind::class,
            'recommended_on' => 'date',
        ];
    }
}
