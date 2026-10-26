<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Enums\EventLevel;
use App\Models\SystemEvent;
use App\Settings\Settings;
use App\Telegram\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends important events to a Telegram chat through a bot (Bot API sendMessage).
 *
 * Setup: create a bot with @BotFather -> TELEGRAM_ALERT_BOT_TOKEN; write to the bot
 * (or add it to a group) and take the chat id from
 * https://api.telegram.org/bot<token>/getUpdates -> TELEGRAM_ALERT_CHAT_ID.
 *
 * The same open event is sent again only after alert_repeat_minutes.
 */
final class AlertNotifier
{
    public function __construct(private readonly Settings $settings) {}

    public function isConfigured(): bool
    {
        return filled(config('telegram.alerts.bot_token')) && filled(config('telegram.alerts.chat_id'));
    }

    public function maybeNotify(SystemEvent $event): void
    {
        if (! $this->isConfigured() || ! $this->settings->bool('alerts_enabled')) {
            return;
        }

        $minLevel = EventLevel::tryFrom((string) $this->settings->get('alert_min_level')) ?? EventLevel::Error;
        if (! $event->level->atLeast($minLevel)) {
            return;
        }

        $repeatAfter = $this->settings->int('alert_repeat_minutes');
        if ($event->notified_at !== null && $event->notified_at->gt(now()->subMinutes($repeatAfter))) {
            return;
        }

        if ($this->send($this->format($event))) {
            $event->forceFill(['notified_at' => now()])->saveQuietly();
        }
    }

    public function send(string $html): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(5)->asJson()->post(
                'https://api.telegram.org/bot'.config('telegram.alerts.bot_token').'/sendMessage',
                [
                    'chat_id' => config('telegram.alerts.chat_id'),
                    'text' => mb_substr($html, 0, 4000),
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ],
            );

            if ($response->failed()) {
                Log::warning('Alert bot refused the message', ['status' => $response->status(), 'body' => $response->json('description')]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            // The HTTP client puts the URL, bot token included, into the message.
            $message = str_replace((string) config('telegram.alerts.bot_token'), '***', $e->getMessage());
            Log::warning('Alert bot unreachable', ['exception' => $message]);

            return false;
        }
    }

    private function format(SystemEvent $event): string
    {
        $icon = match ($event->level) {
            EventLevel::Critical => '🔴',
            EventLevel::Error => '🟠',
            EventLevel::Warning => '🟡',
            EventLevel::Info => '🔵',
        };

        $lines = [
            "{$icon} <b>".e(strtoupper($event->level->value)).'</b> · '.e($event->kind->value),
            e($event->message),
        ];
        if ($event->error_key) {
            $lines[] = 'Key: <code>'.e($event->error_key).'</code>';
        }
        if ($event->phone) {
            $lines[] = 'Account: '.e(Phone::mask($event->phone->phone)).' (#'.$event->user_phone_id.')';
        }
        if ($event->message_group_id) {
            $lines[] = 'Send: #'.$event->message_group_id;
        }
        if ($event->occurrences > 1) {
            $lines[] = 'Repeated: '.$event->occurrences.'×';
        }
        $lines[] = '<a href="'.e(route('monitoring.events.show', $event)).'">'.e(config('app.name')).'</a>';

        return implode("\n", $lines);
    }
}
