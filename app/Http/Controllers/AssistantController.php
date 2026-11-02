<?php

namespace App\Http\Controllers;

use App\Actions\Assistant\AskAssistant;
use App\Actions\Assistant\TranscribeSpeech;
use App\Http\Requests\Assistant\AskAssistantRequest;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Workspace;
use App\Support\AiSettings;
use App\Support\AssistantSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
                ->get(['id', 'role', 'content', 'draft', 'audio_path'])
                ->map(fn (AssistantMessage $message): array => $this->presentMessage($message))
                ->values()
            : collect();

        return Inertia::render('assistant/Index', [
            'snapshot' => AssistantSnapshot::for($workspace),
            'model' => config('services.ai.model') ?: config('services.openrouter.model'),
            'provider' => $provider,
            'configured' => (is_string($key) && $key !== '') || ! AiSettings::requiresKey($provider),
            'speech' => TranscribeSpeech::ready(),
            'conversations' => $conversations,
            'activeConversationId' => $active['id'] ?? null,
            'activeMessages' => $activeMessages,
        ]);
    }

    public function transcribe(Request $request, TranscribeSpeech $transcribe): JsonResponse
    {
        Gate::authorize('view', $this->currentWorkspace($request));

        $audio = $request->file('audio');

        if ($audio === null || ! $audio->isValid()) {
            return response()->json(['message' => 'Запись не получена.'], 422);
        }

        return response()->json([
            'text' => $transcribe->execute($audio),
        ]);
    }

    public function voice(Request $request, TranscribeSpeech $transcribe, AskAssistant $askAssistant): JsonResponse
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        $audio = $request->file('audio');

        if ($audio === null || ! $audio->isValid()) {
            return response()->json(['message' => 'Запись не получена.'], 422);
        }

        $extension = strtolower($audio->getClientOriginalExtension() ?: 'webm');

        if (! in_array($extension, ['webm', 'ogg', 'mp4', 'wav', 'm4a'], true)) {
            $extension = 'webm';
        }

        $path = $audio->storeAs('assistant-voice', Str::uuid()->toString().'.'.$extension, 'local');

        try {
            $transcript = $transcribe->transcribeFile(Storage::disk('local')->path($path));
            $reply = $askAssistant->execute($workspace, $transcript, $this->voiceHistory($request));
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        $conversationId = $request->filled('conversation_id') ? $request->integer('conversation_id') : null;
        $conversation = $this->resolveConversation($request, $workspace, $conversationId);
        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $transcript,
            'audio_path' => $path,
        ]);
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply['text'],
            'draft' => $reply['draft'],
        ]);
        $conversation->forceFill([
            'title' => $conversation->title ?: mb_substr($transcript, 0, 120),
            'last_message_at' => now(),
        ])->save();

        return response()->json($reply + [
            'conversation_id' => $conversation->id,
            'message_id' => $assistantMessage->id,
            'user_message_id' => $userMessage->id,
            'transcript' => $transcript,
            'audioUrl' => route('assistant.messages.audio', $userMessage),
        ]);
    }

    public function audio(Request $request, AssistantMessage $message): BinaryFileResponse
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('view', $workspace);

        $conversation = $message->conversation;

        abort_unless(
            $conversation instanceof AssistantConversation
                && $conversation->user_id === $request->user()->id
                && $conversation->workspace_id === $workspace->id,
            404,
        );

        $path = $message->audio_path;

        abort_unless(is_string($path) && str_starts_with($path, 'assistant-voice/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
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
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply['text'],
            'draft' => $reply['draft'],
        ]);
        $conversation->forceFill([
            'title' => $conversation->title ?: mb_substr($data['message'], 0, 120),
            'last_message_at' => now(),
        ])->save();

        return response()->json($reply + [
            'conversation_id' => $conversation->id,
            'message_id' => $assistantMessage->id,
        ]);
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
                ->get(['id', 'role', 'content', 'draft', 'audio_path'])
                ->map(fn (AssistantMessage $message): array => $this->presentMessage($message))
                ->values(),
        ]);
    }

    public function recordDraft(Request $request, AssistantMessage $message): JsonResponse
    {
        $workspace = $this->currentWorkspace($request);
        Gate::authorize('update', $workspace);

        $conversation = $message->conversation;

        abort_unless(
            $conversation instanceof AssistantConversation
                && $conversation->user_id === $request->user()->id
                && $conversation->workspace_id === $workspace->id,
            404,
        );

        $draft = $message->draft;
        abort_unless(is_array($draft), 404);

        $draft['recorded'] = true;
        $message->forceFill(['draft' => $draft])->save();

        return response()->json(['recorded' => true]);
    }

    /**
     * @return array{id: int, role: string, text: string, draft: array<string, mixed>|null, audioUrl: string|null}
     */
    private function presentMessage(AssistantMessage $message): array
    {
        $path = $message->audio_path;

        return [
            'id' => $message->id,
            'role' => $message->role,
            'text' => $message->content,
            'draft' => $message->draft,
            'audioUrl' => is_string($path) && str_starts_with($path, 'assistant-voice/')
                ? route('assistant.messages.audio', $message)
                : null,
        ];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function voiceHistory(Request $request): array
    {
        $history = json_decode((string) $request->input('history', '[]'), true);

        if (! is_array($history)) {
            return [];
        }

        $rows = [];

        foreach ($history as $row) {
            if (! is_array($row)) {
                continue;
            }

            $role = $row['role'] ?? null;
            $content = $row['content'] ?? null;

            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || $content === '') {
                continue;
            }

            $rows[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 4000),
            ];

            if (count($rows) === 10) {
                break;
            }
        }

        return $rows;
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
