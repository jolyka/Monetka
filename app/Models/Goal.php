<?php

namespace App\Models;

use App\Enums\TransactionType;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property int $target_amount
 * @property Carbon|null $target_date
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'target_amount', 'target_date', 'notes'])]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<self> $query */
    public function scopeWithProgress($query): void
    {
        $query->withSum(['transactions as contribution_sum' => fn ($q) => $q->where('type', TransactionType::GoalContribution)], 'amount')
            ->withSum(['transactions as withdrawal_sum' => fn ($q) => $q->where('type', TransactionType::GoalWithdrawal)], 'amount');
    }

    public function progress(): int
    {
        if (array_key_exists('contribution_sum', $this->getAttributes())) {
            return (int) $this->getAttribute('contribution_sum') - (int) $this->getAttribute('withdrawal_sum');
        }

        $contributions = (int) $this->transactions()
            ->where('type', TransactionType::GoalContribution)
            ->sum('amount');

        $withdrawals = (int) $this->transactions()
            ->where('type', TransactionType::GoalWithdrawal)
            ->sum('amount');

        return $contributions - $withdrawals;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'target_date' => 'date',
        ];
    }
}
