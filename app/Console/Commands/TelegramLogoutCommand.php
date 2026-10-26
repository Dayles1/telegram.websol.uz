<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserPhone;
use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramLogoutCommand extends Command
{
    protected $signature = 'telegram:logout {userPhoneId} {userId} {reason?}';
    protected $description = 'Logout Telegram for a given phone ID and clean worker/session artifacts';

    protected function madeline(string $sessionPath): API
    {
        $settings = new \danog\MadelineProto\Settings();

        $appInfo = new AppInfo();
        $apiId = (int) env('TELEGRAM_API_ID', 0);
        $apiHash = env('TELEGRAM_API_HASH', '');

        if (!$apiId || !$apiHash) {
            Log::channel('logout')->warning('TELEGRAM_API_ID or TELEGRAM_API_HASH not set, assuming bot token session');
        }

        $appInfo->setApiId($apiId);
        $appInfo->setApiHash($apiHash);
        $settings->setAppInfo($appInfo);

        $loggerSettings = (new LoggerSettings())->setType(Logger::ERROR);
        $settings->setLogger($loggerSettings);

        return new API($sessionPath, $settings);
    }

    public function handle(): int
    {
        $userPhoneId = (int) $this->argument('userPhoneId');
        $userId = (int) $this->argument('userId');
        $reason = trim((string) ($this->argument('reason') ?? 'logout')) ?: 'logout';

        $userPhone = UserPhone::find($userPhoneId);
        $user = User::find($userId);

        if (!$userPhone) {
            Log::channel('logout')->warning('telegram_logout_userphone_not_found', [
                'user_phone_id' => $userPhoneId,
                'user_id' => $userId,
                'reason' => $reason,
            ]);

            return self::FAILURE;
        }

        if (!$user) {
            Log::channel('logout')->warning('telegram_logout_user_not_found', [
                'user_phone_id' => $userPhoneId,
                'user_id' => $userId,
                'reason' => $reason,
            ]);

            return self::FAILURE;
        }

        Log::channel('logout')->info('telegram_logout_command_started', [
            'user_phone_id' => $userPhoneId,
            'user_id' => $userId,
            'reason' => $reason,
            'phone' => $userPhone->phone ?? null,
            'is_superadmin' => $user->hasRole('superadmin'),
        ]);

        $this->failAllPendingMessages($userPhone);

        $sessionPath = $userPhone->session_path;
        $logoutSucceeded = false;
        $logoutWasCancelled = false;
        $failureText = null;

        if ($sessionPath && File::exists($sessionPath)) {
            try {
                $madeline = $this->madeline($sessionPath);
                $madeline->logOut();

                $logoutSucceeded = true;

                Log::channel('logout')->info('telegram_logged_out_directly', [
                    'user_phone_id' => $userPhoneId,
                    'reason' => $reason,
                ]);
            } catch (Throwable $e) {
                $message = $e->getMessage();

                if ($this->isOperationCancelled($message)) {
                    $logoutWasCancelled = true;

                    Log::channel('logout')->warning('telegram_logout_operation_cancelled', [
                        'user_phone_id' => $userPhoneId,
                        'reason' => $reason,
                        'error' => $message,
                        'session_path' => $sessionPath,
                    ]);

                    $this->cleanupMadelineWorkers($sessionPath);
                } else {
                    Log::channel('logout')->warning('telegram_logout_madeline_failed', [
                        'user_phone_id' => $userPhoneId,
                        'reason' => $reason,
                        'error' => $message,
                        'session_path' => $sessionPath,
                    ]);
                }

                $failureText = $this->buildFailureText($user, $userPhone, $reason, $message);
            }
        } else {
            Log::channel('logout')->info('telegram_session_not_found_or_already_removed', [
                'user_phone_id' => $userPhoneId,
                'reason' => $reason,
                'session_path' => $sessionPath,
            ]);

            $failureText = $this->buildFailureText(
                $user,
                $userPhone,
                $reason,
                'Session fayli topilmadi yoki allaqachon o‘chirilgan'
            );
        }

        $this->deleteSessionArtifacts($sessionPath);

        try {
            $userPhone->update([
                'session_path' => null,
                'is_active' => false,
            ]);
        } catch (Throwable $e) {
            Log::channel('logout')->warning('telegram_userphone_update_failed', [
                'user_phone_id' => $userPhoneId,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        if ($logoutSucceeded) {
            $successText = $this->buildSuccessText($user, $userPhone, $reason);

            Log::channel('logout')->info('telegram_logout_success_text', [
                'user_phone_id' => $userPhoneId,
                'user_id' => $userId,
                'reason' => $reason,
                'text' => $successText,
            ]);
        } else {
            Log::channel('logout')->info('telegram_logout_failed_text', [
                'user_phone_id' => $userPhoneId,
                'user_id' => $userId,
                'reason' => $reason,
                'operation_cancelled' => $logoutWasCancelled,
                'text' => $failureText,
            ]);
        }

        Log::channel('logout')->info('telegram_logout_command_finished', [
            'user_phone_id' => $userPhoneId,
            'user_id' => $userId,
            'reason' => $reason,
            'logout_succeeded' => $logoutSucceeded,
            'logout_was_cancelled' => $logoutWasCancelled,
        ]);
        Log::info('telegram_logout_command_finished', [
            'user_phone_id' => $userPhoneId,
            'user_id' => $userId,
            'reason' => $reason,
            'logout_succeeded' => $logoutSucceeded,
            'logout_was_cancelled' => $logoutWasCancelled,
        ]);
        Log::channel('logout')->info("\n");

        return self::SUCCESS;
    }

    protected function failAllPendingMessages(UserPhone $userPhone): void
    {
        $messageGroups = $userPhone->messageGroups()
            ->where('status', 'pending')
            ->get();

        foreach ($messageGroups as $group) {
            $messages = $group->messages()
                ->where('status', 'pending')
                ->get();

            foreach ($messages as $message) {
                $message->status = 'cancelled';
                $message->save();
            }

            $group->status = 'cancelled';
            $group->save();
        }

        Log::channel('logout')->info('marked_all_pending_messages_as_cancelled', [
            'user_phone_id' => $userPhone->id,
            'groups_count' => $messageGroups->count(),
        ]);
    }

    private function isOperationCancelled(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'operation was cancelled')
            || str_contains($message, 'the operation was cancelled')
            || str_contains($message, 'cancelled');
    }

    private function cleanupMadelineWorkers(string $sessionPath): void
    {
        $escaped = escapeshellarg($sessionPath);

        exec("pkill -TERM -f {$escaped} 2>/dev/null");
        usleep(500000);
        exec("pkill -KILL -f {$escaped} 2>/dev/null");

        Log::channel('logout')->info('madeline_worker_cleanup_attempted', [
            'session_path' => $sessionPath,
        ]);
    }

    private function deleteSessionArtifacts(?string $sessionPath): void
    {
        if (!$sessionPath || !File::exists($sessionPath)) {
            return;
        }

        try {
            if (File::isDirectory($sessionPath)) {
                File::deleteDirectory($sessionPath);
                Log::channel('logout')->info('telegram_session_directory_deleted', [
                    'session_path' => $sessionPath,
                ]);
            } else {
                File::delete($sessionPath);
                Log::channel('logout')->info('telegram_session_file_deleted', [
                    'session_path' => $sessionPath,
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('logout')->warning('telegram_session_delete_failed', [
                'session_path' => $sessionPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildSuccessText(User $user, UserPhone $userPhone, string $reason): string
    {
        return match ($reason) {
            'session_invalid' => "✅ {$user->name}, {$userPhone->phone} nomerli akkaunt sessiyasi buzilganligi sababli uzildi.",
            'force_login' => "✅ {$user->name}, {$userPhone->phone} nomerli akkaunt majburiy qayta kirish uchun uzildi.",
            'manual' => "✅ {$user->name}, {$userPhone->phone} nomerli akkaunt qo‘lda logout qilindi.",
            default => "✅ {$user->name}, {$userPhone->phone} nomerli akkaunt Telegramdan chiqildi.",
        };
    }

    private function buildFailureText(User $user, UserPhone $userPhone, string $reason, string $error): string
    {
        return match ($reason) {
            'session_invalid' => "⚠️ {$user->name}, {$userPhone->phone} nomerli akkaunt sessiyasi buzilgan. Sabab: {$error}",
            'force_login' => "⚠️ {$user->name}, {$userPhone->phone} nomerli akkauntni majburiy qayta kirishda xatolik. Sabab: {$error}",
            default => "⚠️ {$user->name}, {$userPhone->phone} nomerli akkauntdan chiqishda xatolik yuz berdi. Sabab: {$error}",
        };
    }
}