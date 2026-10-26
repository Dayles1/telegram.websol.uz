<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class UsersExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return User::select([
            'name',
            'phone',
            'telegram_id',
            'department_id',
            'oferta_read',
            'role_id',
            'state',
            'status',
            'value',
            'created_by',
            'last_seen_at',
        ])->get();
    }

    public function headings(): array
    {
        return [
            'Name',
            'Phone',
            'Telegram ID',
            'Department ID',
            'Oferta Read',
            'Role ID',
            'State',
            'Status',
            'Value',
            'Created By',
            'Last Seen At',
        ];
    }
}