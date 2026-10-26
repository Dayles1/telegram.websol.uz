<?php

namespace App\Http\Controllers\Export;

use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends Controller
{
    public function export()
    {
        return Excel::download(
            new UsersExport(),
            'users.xlsx'
        );
    }
}