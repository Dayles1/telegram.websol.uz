<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Monitoring;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/** The tail of the log files, newest first, for when the journal is not enough. */
final class LogController extends Controller
{
    private const MAX_BYTES = 256 * 1024;

    public function __invoke(Request $request): View
    {
        $files = collect(File::files(storage_path('logs')))
            ->merge(File::isDirectory(storage_path('logs/madeline')) ? File::files(storage_path('logs/madeline')) : [])
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.log'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->mapWithKeys(fn ($f) => [str_replace(storage_path('logs').DIRECTORY_SEPARATOR, '', $f->getPathname()) => $f]);

        $selected = (string) $request->query('file', (string) $files->keys()->first());
        $file = $files->get($selected);
        $level = $request->query('level');
        $search = trim((string) $request->query('search'));

        $lines = [];
        if ($file !== null) {
            $handle = fopen($file->getPathname(), 'r');
            $size = $file->getSize();
            fseek($handle, max(0, $size - self::MAX_BYTES));
            $chunk = stream_get_contents($handle) ?: '';
            fclose($handle);

            // Laravel log entries start with "[date] env.LEVEL:"; continuation lines belong to the previous entry.
            $entries = preg_split('/\n(?=\[\d{4}-\d{2}-\d{2}[ T])/', $chunk) ?: [];
            $lines = collect($entries)
                ->filter(fn (string $e) => trim($e) !== '')
                ->when(in_array($level, ['ERROR', 'WARNING', 'INFO', 'CRITICAL', 'DEBUG'], true), fn ($c) => $c->filter(fn (string $e) => str_contains(substr($e, 0, 60), ".{$level}:")))
                ->when($search !== '', fn ($c) => $c->filter(fn (string $e) => stripos($e, $search) !== false))
                ->reverse()
                ->take(300)
                ->values()
                ->all();
        }

        return view('monitoring.logs', [
            'files' => $files->map(fn ($f) => ['size' => $f->getSize(), 'modified' => $f->getMTime()]),
            'selected' => $selected,
            'lines' => $lines,
            'level' => $level,
            'search' => $search,
        ]);
    }
}
