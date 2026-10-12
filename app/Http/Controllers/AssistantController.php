<?php

namespace App\Http\Controllers;

use App\Actions\Assistant\AskAssistant;
use App\Http\Requests\Assistant\AskAssistantRequest;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Workspace;
use App\Support\AiSettings;
use App\Support\AssistantSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AssistantController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        $provider = (string) config('services.ai.provider', 'openrouter');
        $key = config('services.ai.key') ?: ($provider === 'openrouter' ? config('services.openrouter.key') : null);
        $conversations = AssistantConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('workspace_id', $workspace->id)
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'title', 'last_message_at'])
            ->map(fn (AssistantConversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title ?: 'Новый разговор',
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'messages_count' => $conversation->messages_count,
            ])
            ->values();

        $active = $conversations->first();
        $activeMessages = $active
            ? AssistantMessage::query()
                ->where('assistant_conversation_id', $active['id'])
                ->oldest()
                ->get(['role', 'content', 'draft'])
                ->map(fn (AssistantMessage $message): array => [
                    'role' => $message->role,
                    'text' => $message->content,
                    'draft' => $message->draft,
                ])
                ->values()
            : collect();

        return Inertia::render('assistant/Index', [
            'snapshot' => AssistantSnapshot::for($workspace),
            'model' => config('services.ai.model') ?: config('services.openrouter.model'),
            'provider' => $provider,
            'configured' => (is_string($key) && $key !== '') || ! AiSettings::requiresKey($provider),
            'conversations' => $conversations,
            'activeConversationId' => $active['id'] ?? null,
            'activeMessages' => $activeMessages,
        ]);
    }

    public function store(AskAssistantRequest $request, AskAssistant $askAssistant): JsonResponse
    {
        $data = $request->validated();

        $workspace = $this->currentWorkspace($request);
        $conversation = $this->resolveConversation($request, $workspace, $data['conversation_id'] ?? null);

        $reply = $askAssistant->execute(
            $workspace,
            $data['message'],
            $data['history'] ?? [],
        );

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $data['message'],
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply['text'],
            'draft' => $reply['draft'],
        ]);
        $conversation->forceFill([
            'title' => $conversation->title ?: mb_substr($data['message'], 0, 120),
            'last_message_at' => now(),
        ])->save();

        return response()->json($reply + ['conversation_id' => $conversation->id]);
    }

    public function conversation(Request $request, AssistantConversation $conversation): JsonResponse
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        abort_unless(
            $conversation->user_id === $request->user()->id
                && $conversation->workspace_id === $workspace->id,
            404,
        );

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $conversation->messages()
                ->oldest()
                ->get(['role', 'content', 'draft'])
                ->map(fn (AssistantMessage $message): array => [
                    'role' => $message->role,
                    'text' => $message->content,
                    'draft' => $message->draft,
                ])
                ->values(),
        ]);
    }

    private function resolveConversation(Request $request, Workspace $workspace, ?int $id): AssistantConversation
    {
        if ($id !== null) {
            $conversation = AssistantConversation::query()
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->where('workspace_id', $workspace->id)
                ->first();

            if ($conversation) {
                return $conversation;
            }
        }

        return AssistantConversation::create([
            'user_id' => $request->user()->id,
            'workspace_id' => $workspace->id,
        ]);
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
