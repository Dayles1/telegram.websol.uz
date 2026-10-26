<?php

namespace App\Application\Handlers;

use danog\MadelineProto\API;
use Illuminate\Support\Facades\Log;
use danog\MadelineProto\EventHandler;

class DefaultEventHandler extends EventHandler
{
    /**
     * Update kelganda ishlaydi
     * Masalan: yangi xabar, reply va forward
     */
    public function onUpdateNewMessage(array $update): void
    {
        $message = $update['message'] ?? null;

        if (!$message) {
            return;
        }

        $chatId = $message['peer_id']['channel_id'] ?? $message['peer_id']['user_id'] ?? null;
        $text = $message['message'] ?? '';

        // Reply bo‘lsa, qayerdan kelganini olish
        $replyTo = $message['reply_to_msg_id'] ?? null;

        // Logga yozish yoki DB ga saqlash mumkin
        Log::info("New message in chat {$chatId} | replyTo: {$replyTo} | text: {$text}");

        // Shu yerda xohlasang avtomatik javob ham berish mumkin
        /*
        if ($replyTo) {
            $this->messages->sendMessage([
                'peer' => $chatId,
                'reply_to_msg_id' => $replyTo,
                'message' => "Thanks for your reply!"
            ]);
        }
        */
    }
}
