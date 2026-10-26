<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case User = 'user';
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return __("enums.role.{$this->value}");
    }
}
