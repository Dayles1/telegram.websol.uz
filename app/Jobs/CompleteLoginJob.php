<?php

namespace App\Jobs;

use App\Models\UserPhone;
use App\Models\TelegramAuthSession;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CompleteLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $phone,
        public int $userId,
        public ?string $password,
        public int $sessionId
    ) {}

    protected function madeline($phone, $userId): API
    {
        $sessionPath = storage_path("app/sessions/{$phone}_user_{$userId}.madeline");
        if (!is_dir(dirname($sessionPath))) {
            mkdir(dirname($sessionPath), 0775, true);
        }
        return new API($sessionPath);
    }

    public function handle(): void
    {
        $session = TelegramAuthSession::find($this->sessionId);

        if (!$session) {
            Log::error("CompleteLoginJob: session not found {$this->sessionId}");
            return;
        }

        if (!$this->password) {
            $session->update([
                'status' => 'need_password',
                'message_key' => '2fa_password_required',
                'message' => null,
            ]);
            return;
        }

        $sessionPath = storage_path("app/sessions/{$this->phone}_user_{$this->userId}.madeline");
        if (!file_exists($sessionPath) && !is_dir($sessionPath)) {
            $session->update([
                'status' => 'failed',
                'message_key' => 'session_not_found',
                'message' => null,
            ]);
            return;
        }

        $Madeline = $this->madeline($this->phone, $this->userId);

        try {
            Log::info("CompleteLoginJob: complete2falogin {$this->phone}");

            $authorization = $Madeline->complete2falogin($this->password);

            if (isset($authorization['_']) && $authorization['_'] === 'account.needSignup') {
                throw new \Exception('ACCOUNT_NOT_REGISTERED');
            }

            $self = $Madeline->getSelf();

            UserPhone::updateOrCreate(
                ['user_id' => $this->userId, 'phone' => $this->phone],
                [
                    'telegram_user_id' => $self['id'],
                    'session_path' => $sessionPath
                ]
            );

            $session->update([
                'status' => 'success',
                'telegram_user_id' => $self['id'],
                'session_path' => $sessionPath,
                'message_key' => 'telegram_verified',
                'message' => null,
            ]);

        } catch (\Throwable $e) {
            Log::error("CompleteLoginJob error {$this->phone}: {$e->getMessage()}");

            $session->update([
                'status' => 'failed',
                'message_key' => null,
                'message' => $e->getMessage(), // 👈 original error
            ]);
        }
    }
}

