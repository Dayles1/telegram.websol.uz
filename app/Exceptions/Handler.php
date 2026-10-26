<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    public function render($request, Throwable $e)
    {
        if ($e instanceof ApiException) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $e->status());
        }

        return parent::render($request, $e);
    }
}
