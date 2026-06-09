<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    /**
     * List the available log files in storage/logs.
     */
    public function index(): Response
    {
        $files = collect($this->logFiles())
            ->map(fn (\SplFileInfo $file): array => [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'modified_at' => date('Y-m-d H:i:s', $file->getMTime()),
            ])
            ->sortByDesc('modified_at')
            ->values()
            ->all();

        return Inertia::render('Logs/Index', [
            'files' => $files,
        ]);
    }

    /**
     * Return the contents of a single log file.
     *
     * The requested name is matched against the real list of log files
     * (allow-list), which makes path traversal impossible: a crafted name
     * such as "../../.env" can never appear in that list.
     */
    public function show(string $file): JsonResponse
    {
        $target = collect($this->logFiles())
            ->first(fn (\SplFileInfo $info): bool => $info->getFilename() === $file);

        abort_if($target === null, 404);

        return response()->json([
            'name' => $target->getFilename(),
            'content' => File::get($target->getPathname()),
        ]);
    }

    /**
     * The .log files currently present in storage/logs.
     *
     * @return array<int, \SplFileInfo>
     */
    private function logFiles(): array
    {
        $path = storage_path('logs');

        if (! File::isDirectory($path)) {
            return [];
        }

        return collect(File::files($path))
            ->filter(fn (\SplFileInfo $file): bool => $file->getExtension() === 'log')
            ->all();
    }
}
