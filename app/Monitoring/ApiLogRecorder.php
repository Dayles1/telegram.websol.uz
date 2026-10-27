<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Models\ApiLog;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes what an API client sent and what it got back (table api_logs) for the
 * methods in telegram.api_log.methods. Secrets are masked before anything is
 * stored, files are kept as name/size/type only, long bodies are cut.
 */
final class ApiLogRecorder
{
    private const MASK = '***';

    /** Exact keys that hold a secret; any key containing "password", "token" or "secret" too. */
    private const SECRET_KEYS = ['code', 'api_hash', 'authorization'];

    public function shouldLog(Request $request): bool
    {
        return in_array($request->method(), (array) config('telegram.api_log.methods', []), true);
    }

    /** Read in the middleware's handle(): uploaded files are gone by terminate(). */
    public function requestBody(Request $request): ?string
    {
        $body = $this->mask($request->input());

        foreach ($request->allFiles() as $key => $files) {
            $body[$key] = is_array($files) ? array_map($this->describeFile(...), $files) : $this->describeFile($files);
        }

        return $body === [] ? null : $this->cut($this->json($body));
    }

    public function record(Request $request, Response $response, ?string $requestBody, float $startedAt): void
    {
        $route = $request->route();

        try {
            ApiLog::query()->create([
                'method' => $request->method(),
                'endpoint' => $route ? $route->uri() : 'unmatched:'.mb_substr($request->path(), 0, 150),
                'path' => mb_substr($request->path(), 0, 255),
                'user_id' => $request->user()?->getKey(),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'request_body' => $requestBody,
                'response_body' => $this->responseBody($response),
            ]);
        } catch (Throwable $e) {
            Log::warning('API call not logged', ['exception' => $e->getMessage()]);
        }
    }

    private function responseBody(Response $response): ?string
    {
        $content = $response->getContent();
        if ($content === false || $content === '') {
            return null; // streamed or file download
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $this->cut($this->json($this->mask($decoded)));
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'text') || $decoded !== null
            ? $this->cut($content)
            : null;
    }

    private function mask(array $data): array
    {
        // Arrays are walked, not masked: "errors": {"password": ["..."]} keeps its validation message.
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->mask($value);
            } elseif (is_string($key) && $this->isSecret($key) && $value !== null && $value !== '') {
                $data[$key] = self::MASK;
            }
        }

        return $data;
    }

    private function isSecret(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, self::SECRET_KEYS, true)
            || str_contains($key, 'password')
            || str_contains($key, 'token')
            || str_contains($key, 'secret');
    }

    private function describeFile(mixed $file): mixed
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        return [
            'file' => $file->getClientOriginalName(),
            'size' => $file->isValid() ? $file->getSize() : null,
            'type' => $file->getClientMimeType(),
        ];
    }

    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function cut(string $text): string
    {
        $max = (int) config('telegram.api_log.max_body', 65536);

        return strlen($text) > $max ? mb_strcut($text, 0, $max).' …[cut]' : $text;
    }
}
