<?php

namespace App\Exports;

use App\Models\MessageGroup;
use App\Models\TelegramMessage;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MessageGroupMessagesExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        protected int $groupId
    ) {}

    public function query(): Builder
    {
        return TelegramMessage::query()
            ->where('message_group_id', $this->groupId)
            ->whereNotIn('status', ['pending', 'canceled'])
            ->orderByRaw('sent_at IS NULL')   // sent_at null bo‘lganlar oxirga
            ->orderBy('sent_at', 'asc')
            ->orderBy('batch_no', 'asc')
            ->orderBy('id', 'asc');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Batch No',
            'Peer',
            'Sent At',
            'Status',
            'Error Key',
        ];
    }

    public function map($message): array
    {
        $delaySeconds = null;

        if ($message->send_at && $message->sent_at) {
            $delaySeconds = $message->send_at->diffInSeconds($message->sent_at, false);
        }

        return [
            $message->id,
            $message->batch_no,
            $message->peer,
            optional($message->sent_at)->toDateTimeString(),
            $message->status,
            $message->error_key,
        ];
    }
}