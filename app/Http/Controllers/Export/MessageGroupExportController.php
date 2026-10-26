<?php

namespace App\Http\Controllers\Export;

use App\Exports\MessageGroupMessagesExport;
use App\Http\Controllers\Controller;
use App\Models\MessageGroup;
use Maatwebsite\Excel\Facades\Excel;

class MessageGroupExportController extends Controller
{
    public function export(int $groupId)
    {
        $group = MessageGroup::findOrFail($groupId);

        $fileName = 'message-group-' . $group->id . '-messages.xlsx';

        return Excel::download(
            new MessageGroupMessagesExport($group->id),
            $fileName
        );
    }
}