<?php

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
});

it('transcribes a voice message with the server whisper binary', function () {
    $binary = tempnam(sys_get_temp_dir(), 'whisper');
    $model = tempnam(sys_get_temp_dir(), 'model');
    $ffmpeg = tempnam(sys_get_temp_dir(), 'ffmpeg');
    file_put_contents($binary, 'whisper');
    file_put_contents($model, 'model');
    file_put_contents($ffmpeg, 'ffmpeg');

    config([
        'services.whisper.binary' => $binary,
        'services.whisper.model' => $model,
        'services.whisper.ffmpeg' => $ffmpeg,
        'services.whisper.language' => 'ru',
    ]);

    Process::fake([
        '*' => Process::result(output: "[00:00:00.000 --> 00:00:01.500]   кофе 350\n"),
    ]);

    $user = User::factory()->create();
    (new CreateWorkspace)->execute($user, 'Семья', 'RUB');

    $this->actingAs($user)
        ->post(route('assistant.transcribe'), [
            'audio' => UploadedFile::fake()->create('voice.webm', 20, 'audio/webm'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('text', 'кофе 350');

    @unlink($binary);
    @unlink($model);
    @unlink($ffmpeg);
});

it('explains that speech recognition is not installed', function () {
    config([
        'services.whisper.binary' => '/missing/whisper-cli',
        'services.whisper.model' => '/missing/model.bin',
        'services.whisper.ffmpeg' => '/missing/ffmpeg',
    ]);

    $user = User::factory()->create();
    (new CreateWorkspace)->execute($user, 'Семья', 'RUB');

    $this->actingAs($user)
        ->post(route('assistant.transcribe'), [
            'audio' => UploadedFile::fake()->create('voice.webm', 20, 'audio/webm'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.audio.0', 'Распознавание речи на сервере не настроено.');
});

it('sends a voice message to the assistant and keeps it playable', function () {
    Storage::fake('local');

    $binary = tempnam(sys_get_temp_dir(), 'whisper');
    $model = tempnam(sys_get_temp_dir(), 'model');
    $ffmpeg = tempnam(sys_get_temp_dir(), 'ffmpeg');
    file_put_contents($binary, 'whisper');
    file_put_contents($model, 'model');
    file_put_contents($ffmpeg, 'ffmpeg');

    config([
        'services.whisper.binary' => $binary,
        'services.whisper.model' => $model,
        'services.whisper.ffmpeg' => $ffmpeg,
        'services.whisper.language' => 'ru',
        'services.ai.provider' => 'openrouter',
        'services.ai.key' => 'test-key',
        'services.ai.base_url' => 'https://openrouter.ai/api/v1/chat/completions',
        'services.ai.model' => 'openrouter/free',
    ]);

    Process::fake([
        '*' => Process::result(output: "[00:00:00.000 --> 00:00:01.500]   кофе 350\n"),
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'text' => 'Записать кофе на 350?',
                        'draft' => null,
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]],
        ], 200),
    ]);

    $user = User::factory()->create();
    (new CreateWorkspace)->execute($user, 'Семья', 'RUB');

    $response = $this->actingAs($user)
        ->post(route('assistant.voice'), [
            'audio' => UploadedFile::fake()->create('voice.webm', 20, 'audio/webm'),
        ], ['Accept' => 'application/json']);

    $response->assertOk()
        ->assertJsonPath('transcript', 'кофе 350')
        ->assertJsonPath('text', 'Записать кофе на 350?');

    $message = AssistantMessage::query()->where('role', 'user')->first();
    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('кофе 350')
        ->and($message->audio_path)->toStartWith('assistant-voice/');

    Storage::disk('local')->assertExists($message->audio_path);

    $this->actingAs($user)
        ->get($response->json('audioUrl'))
        ->assertOk();

    $other = User::factory()->create();
    (new CreateWorkspace)->execute($other, 'Чужой', 'RUB');

    $this->actingAs($other)
        ->get(route('assistant.messages.audio', $message))
        ->assertNotFound();

    Http::assertSent(fn ($request) => str_contains($request->body(), 'кофе 350'));

    @unlink($binary);
    @unlink($model);
    @unlink($ffmpeg);
});
