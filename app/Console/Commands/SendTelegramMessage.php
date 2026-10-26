<?php

namespace App\Console\Commands;

use Throwable;
use Carbon\Carbon;
use App\Models\UserPhone;
use App\Models\MessageGroup;
use danog\MadelineProto\API;
use App\Models\TelegramMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class SendTelegramMessage extends Command
{
    protected $signature = 'telegram:send-messages {groupId}';
    protected $description = 'Send telegram messages for given message group (real-time)';

    protected int $perMessageSpacingSeconds = 1;
    protected int $maxAttempts = 1;
    protected int $floodBufferSeconds = 5;

    public function handle()
    {
        $groupId = (int) $this->argument('groupId');
        $group = MessageGroup::find($groupId);

        if (!$group) {
            $this->error("MessageGroup topilmadi: id={$groupId}");
            Log::warning("MessageGroup topilmadi: id={$groupId}");
            return self::FAILURE;
        }

        $userPhone = UserPhone::find($group->user_phone_id);


        $sessionPath = $userPhone->session_path;

        $api = $this->validateAndStartMadeline($userPhone);

        if (!$api) {
            $errorKey = (!$sessionPath || !File::exists($sessionPath)) ? 'session_missing' : 'session_start_failed';

            $group->messages()
                ->where('status', 'pending')
                ->update([
                    'status' => 'failed',
                    'error_key' => $errorKey,
                ]);

            $this->error("Session ishlamayapti: user_phone_id={$userPhone->id}, sabab={$errorKey}");
            Log::warning("Session ishlamayapti: user_phone_id={$userPhone->id}, sabab={$errorKey}");
            return self::FAILURE;
        }

        $messages = $group->messages()
            ->where('status', 'pending')
            ->orderBy('id') 
            ->get();

        foreach ($messages as $msgRow) {
            /** @var TelegramMessage|null $message */
            $message = TelegramMessage::find($msgRow->id);
            if (!$message || $message->status !== 'pending') {
                continue;
            }

            try {
                // per-message spacing
                if ($this->perMessageSpacingSeconds > 0) {
                    sleep($this->perMessageSpacingSeconds);
                }

                // text: message->message_text yoki group->message_text
                $text = $message->message_text ?? $group->message_text ?? '';

                $payload = [
                    'peer' => $message->peer,
                    'message' => $text,
                    'parse_mode' => 'HTML',
                ];

                // YUBORISH (no Telegram schedule_date used)
                $api->messages->sendMessage($payload);

                // muvaffaqiyatli yuborildi
                $message->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'attempts' => 0,
                    'error_key' => null,
                ]);

                $this->info("✅ Xabar yuborildi: {$message->peer} (id={$message->id}, status=sent)");
            } catch (Throwable $e) {
                $err = $e->getMessage();

                // attempts ++ (saqlab qo'yamiz, lekin retry yo'q)
                $message->increment('attempts');
                $message->refresh();

                $errorKey = $this->mapErrorToKey($err);

                // Faqat noma'lum xato bo'lsa batafsil logga tushadi
                if ($errorKey === 'unknown_error') {
                    Log::error("❌ Xabar yuborilmadi (UNKNOWN):peer={$message->peer} phone={$userPhone->phone}, id={$userPhone->id}", ['error' => $err]);
                }

                // Darrov failed qilamiz va error_key yozamiz
                $message->update([
                    'status' => 'failed',
                    'error_key' => $errorKey,
                ]);

                // davom etamiz — keyingi xabarga o'tamiz
                continue;
            }
        }

        // Group holatini yangilash
        $group->update(['status' => 'completed']);
        $this->info("🎉 Group yakunlandi: id={$groupId}");

        return self::SUCCESS;
    }

    /**
     * Madeline session start va validatsiyasi (inline)
     *
     * @param UserPhone $userPhone
     * @return API|null
     */
    protected function validateAndStartMadeline(UserPhone $userPhone): ?API
    {
        $path = $userPhone->session_path;

        if (!$path || !File::exists($path)) {
            Log::warning("❌ Session yo‘q yoki diskda topilmadi: user_phone_id={$userPhone->id}");
            // mark qilishni handle() bajaradi
            $userPhone->update(['session_path' => null, 'is_active' => false]);
            return null;
        }

        try {
            $madeline = new API($path);
            $madeline->start();
            return $madeline;
        } catch (Throwable $e) {
            Log::error("❌ Madeline start failed: user_phone_id={$userPhone->id}, error={$e->getMessage()}");

            $msg = $e->getMessage();
            $shouldReset =
                str_contains($msg, 'AUTH_KEY_UNREGISTERED') ||
                str_contains($msg, 'SESSION_REVOKED') ||
                str_contains($msg, 'AUTH_KEY_INVALID');

            if ($shouldReset) {
                Log::warning("🔄 Session RESET qilinmoqda: user_phone_id={$userPhone->id}");
                if (File::exists($path)) {
                    File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
                }
                $userPhone->update(['session_path' => null, 'is_active' => false]);
            }

            // Start bo'lmadi — handle() error_key='session_start_failed' bilan barcha xabarlarni failed qiladi
            return null;
        }
    }

    /**
     * Map raw exception message to a short error key to store in telegram_messages.error_key
     *
     * @param string $err
     * @return string
     */
    private function mapErrorToKey(string $err): string
    {
        $e = strtolower($err);

        if (preg_match('/flood[_ ]?wait[_ ]?(\d+)/i', $err, $matches)) {
            $seconds = $matches[1] ?? '';
            return $seconds ? "flood_wait_{$seconds}" : 'flood_wait';
        }

        if (preg_match('/slowmode[_ ]?wait[_ ]?(\d+)/i', $err, $matches)) {
            $seconds = $matches[1] ?? '';
            return $seconds ? "slowmode_wait_{$seconds}" : 'slowmode_wait';
        }

        // schedule too much
        if (str_contains($e, 'schedule_too_much') || str_contains($e, 'schedule too much') || str_contains($e, 'S
        CHEDULE_TOO_MUCH')) {
            return 'SCHEDULE_TOO_MUCH';
        }

        // chat write forbidden / permissions
        if (str_contains($e, 'chat_write_forbidden') || str_contains($e, 'chat write forbidden') || str_contains($e, 'chat admin required')) {
            return 'chat_write_forbidden';
        }

        if (str_contains($e, 'user_banned_in_channel') || str_contains($e, 'user is banned') || str_contains($e, 'user is blocked') || str_contains($e, 'bot was blocked')) {
            return 'user_banned';
        }

        if (str_contains($e, 'phone_code_expired') || str_contains($e, 'phone code expired')) {
            return 'phone_code_expired';
        }
        if (str_contains($e, 'auth_key_unregistered') || str_contains($e, 'session_revoked') || str_contains($e, 'auth_key_invalid')) {
            return 'auth_key_invalid';
        }

        if (preg_match('/timeout|timed out|connection.*reset|broken pipe|could not connect/i', $e)) {
            return 'network_error';
        }

        if (str_contains($e, 'peer is not present in the internal peer database') || str_contains($e, 'peer not found')) {
            return 'peer_not_found';
        }

        if (str_contains($e, 'chat_guest_send_forbidden')) {
            return 'chat_guest_send_forbidden';
        }

        // Default
        return 'unknown_error';
    }
}
