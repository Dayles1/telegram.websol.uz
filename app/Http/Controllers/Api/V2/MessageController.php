<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleMessageRequest;
use App\Services\V2\SendMessageViaTelegram;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(protected SendMessageViaTelegram $viaTelegram)
    {

    }
    public function sendScheduledMessage(ScheduleMessageRequest $request)
    {   
        $result=$this->viaTelegram->sendSheduleMessage($request);
        return response()->json($result, $resutt['status']);
    }

}
