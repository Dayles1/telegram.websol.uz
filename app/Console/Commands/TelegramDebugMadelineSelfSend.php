<?php

namespace App\Console\Commands;

use App\Models\UserPhone;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramMadelineSessionGuard extends Command
{
    protected $signature = 'telegram:guard {userPhoneId}';
    protected $description = 'Madeline session validator + revoke detector + safe sender';

    public function handle(): int
    {
        $runId = (string) \Str::uuid();
        $userPhoneId = (int) $this->argument('userPhoneId');

        Log::info('madeline_guard_start', [
            'run_id' => $runId,
            'user_phone_id' => $userPhoneId,
        ]);

        $userPhone = UserPhone::find($userPhoneId);

        if (!$userPhone) {
            Log::error('user_phone_not_found', compact('runId', 'userPhoneId'));
            return self::FAILURE;
        }

        $sessionPath = $userPhone->session_path;

        if (!File::exists($sessionPath)) {
            Log::warning('session_missing', [
                'run_id' => $runId,
                'session' => $sessionPath,
            ]);
            return self::FAILURE;
        }

        $madeline = $this->startMadeline($sessionPath, $runId);

        if (!$madeline) {
            Log::error('madeline_start_failed', compact('runId'));
            return self::FAILURE;
        }

        /**
         * STEP 1: LOCAL AUTH CHECK
         */
        $authState = null;

        try {
            $authState = $madeline->getAuthorization();

            Log::info('local_auth_state', [
                'run_id' => $runId,
                'state' => $authState,
            ]);
        } catch (Throwable $e) {
            Log::error('auth_check_failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }

        /**
         * STEP 2: SERVER AUTHORIZATIONS CHECK
         */
        $serverAuths = null;

        try {
            $serverAuths = $madeline->account->getAuthorizations();

            Log::info('server_authorizations', [
                'run_id' => $runId,
                'data' => json_encode($serverAuths, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            Log::error('server_auth_failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }

        /**
         * STEP 3: REVOKE DETECTION
         */
        $isValid = $this->checkSessionValid($serverAuths);

        if (!$isValid) {
            Log::warning('SESSION_REVOKED', [
                'run_id' => $runId,
                'user_phone_id' => $userPhoneId,
            ]);

            // local cleanup
            File::delete($sessionPath);

            return self::FAILURE;
        }

        /**
         * STEP 4: GET SELF
         */
        try {
            $self = $madeline->getSelf();

            Log::info('self_ok', [
                'run_id' => $runId,
                'self' => json_encode($self, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            Log::error('self_failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }

        /**
         * STEP 5: SAFE SEND MESSAGE
         */
        try {
            $response = $madeline->messages->sendMessage([
                'peer' => 'me',
                'message' => "🔥 TEST MESSAGE\nrun_id: {$runId}",
            ]);

            Log::info('send_success', [
                'run_id' => $runId,
                'response' => json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ]);

        } catch (Throwable $e) {
            Log::error('send_failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
        }

        Log::info('madeline_guard_done', [
            'run_id' => $runId,
        ]);

        return self::SUCCESS;
    }

    private function startMadeline(string $sessionPath, string $runId): ?API
    {
        $settings = new \danog\MadelineProto\Settings();

        $appInfo = new AppInfo();
        $appInfo->setApiId((int) env('TELEGRAM_API_ID'));
        $appInfo->setApiHash(env('TELEGRAM_API_HASH'));

        $settings->setAppInfo($appInfo);

        try {
            Log::info('madeline_starting', [
                'run_id' => $runId,
                'session' => $sessionPath,
            ]);

            $madeline = new API($sessionPath, $settings);
            $madeline->start();

            Log::info('madeline_started', [
                'run_id' => $runId,
            ]);

            return $madeline;

        } catch (Throwable $e) {
            Log::error('madeline_start_error', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function checkSessionValid($serverAuths): bool
    {
        if (!$serverAuths) return false;

        $list = $serverAuths['authorizations'] ?? [];

        foreach ($list as $auth) {
            if (!empty($auth['current'])) {
                return true;
            }
        }

        return false;
    }
}