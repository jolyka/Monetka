<?php

namespace App\Actions\Assistant;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class TranscribeSpeech
{
    public static function ready(): bool
    {
        $binary = (string) config('services.whisper.binary');
        $model = (string) config('services.whisper.model');
        $ffmpeg = (string) config('services.whisper.ffmpeg');

        return is_file($binary) && is_file($model) && is_file($ffmpeg);
    }

    public function execute(UploadedFile $audio): string
    {
        if ($audio->getSize() === 0) {
            throw ValidationException::withMessages([
                'audio' => 'Речь не услышана.',
            ]);
        }

        $source = tempnam(sys_get_temp_dir(), 'speech-');

        if ($source === false) {
            throw ValidationException::withMessages([
                'audio' => 'Не удалось сохранить запись.',
            ]);
        }

        $audio->move(dirname($source), basename($source));

        try {
            return $this->transcribeFile($source);
        } finally {
            @unlink($source);
        }
    }

    public function transcribeFile(string $source): string
    {
        if (! self::ready()) {
            throw ValidationException::withMessages([
                'audio' => 'Распознавание речи на сервере не настроено.',
            ]);
        }

        if (! is_file($source) || filesize($source) === 0) {
            throw ValidationException::withMessages([
                'audio' => 'Речь не услышана.',
            ]);
        }

        $wav = tempnam(sys_get_temp_dir(), 'speech-wav-');

        if ($wav === false) {
            throw ValidationException::withMessages([
                'audio' => 'Не удалось сохранить запись.',
            ]);
        }

        $wavFile = $wav.'.wav';

        try {
            $converted = Process::timeout(30)->run([
                (string) config('services.whisper.ffmpeg'),
                '-y',
                '-i',
                $source,
                '-ar',
                '16000',
                '-ac',
                '1',
                '-c:a',
                'pcm_s16le',
                $wavFile,
            ]);

            if (! $converted->successful()) {
                throw ValidationException::withMessages([
                    'audio' => 'Не удалось прочитать запись.',
                ]);
            }

            $recognized = Process::timeout(120)->run([
                (string) config('services.whisper.binary'),
                '-m',
                (string) config('services.whisper.model'),
                '-l',
                (string) config('services.whisper.language', 'ru'),
                '-f',
                $wavFile,
                '-nt',
            ]);

            if (! $recognized->successful()) {
                throw ValidationException::withMessages([
                    'audio' => 'Не удалось распознать речь.',
                ]);
            }

            $text = $this->transcript($recognized->output());

            if ($text === '') {
                $text = $this->transcript($recognized->errorOutput());
            }

            if ($text === '') {
                throw ValidationException::withMessages([
                    'audio' => 'Речь не услышана.',
                ]);
            }

            return mb_substr($text, 0, 1000);
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages([
                'audio' => 'Распознавание заняло слишком много времени.',
            ]);
        } finally {
            @unlink($wav);
            @unlink($wavFile);
        }
    }

    private function transcript(string $output): string
    {
        $lines = preg_split('/\R/', $output) ?: [];
        $parts = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^\[[0-9:.]+ --> [0-9:.]+\]\s*/', '', $line) ?? $line;
            $line = trim($line);

            if ($line === '' || str_contains($line, 'ggml_') || str_starts_with($line, 'whisper_')) {
                continue;
            }

            $parts[] = $line;
        }

        return trim(implode(' ', $parts));
    }
}
