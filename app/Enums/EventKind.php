<?php

declare(strict_types=1);

namespace App\Enums;

enum EventKind: string
{
    case Login = 'login';
    case Session = 'session';
    case Sending = 'sending';
    case Flood = 'flood';
    case Worker = 'worker';
    case Queue = 'queue';
    case Api = 'api';
    case System = 'system';

    public function label(): string
    {
        return __("enums.event_kind.{$this->value}");
    }
}
