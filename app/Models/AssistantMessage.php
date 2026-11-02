<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['assistant_conversation_id', 'role', 'content', 'draft', 'audio_path'])]
class AssistantMessage extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (AssistantMessage $message): void {
            if (is_string($message->audio_path) && $message->audio_path !== '') {
                Storage::disk('local')->delete($message->audio_path);
            }
        });
    }

    /**
     * @return BelongsTo<AssistantConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }

    protected function casts(): array
    {
        return ['draft' => 'array'];
    }
}
