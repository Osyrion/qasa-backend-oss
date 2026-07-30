<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Converts a PDF's pages to PNG images via poppler-utils' pdftoppm, so a
 * scanned (image-only) PDF can be OCR'd page by page like a photo. Never
 * throws — a missing binary or a failed process simply degrades to an
 * empty result, and CompositeExtractor falls back to today's "insufficient
 * text" behaviour.
 */
final class PdfRasterizer
{
    /**
     * @return list<string> absolute paths to the rasterized PNG pages, in
     *                      page order; empty when the binary is missing or
     *                      the process failed
     */
    public function rasterize(string $absolutePdfPath): array
    {
        $tempDir = sys_get_temp_dir().'/qasa-ocr-'.Str::uuid()->toString();

        if (! @mkdir($tempDir, 0700, true)) {
            return [];
        }

        $prefix = $tempDir.'/page';

        try {
            $binary = (string) config('invoicing.inbox.pdftoppm_path', 'pdftoppm');
            $maxPages = (int) config('invoicing.inbox.ocr_max_pages', 5);
            $dpi = (int) config('invoicing.inbox.ocr_dpi', 200);

            $result = Process::timeout(60)->run([
                $binary, '-png', '-r', (string) $dpi, '-l', (string) $maxPages, $absolutePdfPath, $prefix,
            ]);

            if (! $result->successful()) {
                Log::warning('PDF rasterization failed', [
                    'path' => $absolutePdfPath,
                    'exit_code' => $result->exitCode(),
                    'error' => $result->errorOutput(),
                ]);
                @rmdir($tempDir);

                return [];
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('PDF rasterization failed', ['path' => $absolutePdfPath, 'exception' => $e->getMessage()]);
            @rmdir($tempDir);

            return [];
        }

        $pages = glob($prefix.'*.png') ?: [];
        sort($pages);

        if ($pages === []) {
            @rmdir($tempDir);
        }

        return $pages;
    }

    /**
     * @param  list<string>  $pages
     */
    public function cleanup(array $pages): void
    {
        $dir = null;

        foreach ($pages as $page) {
            $dir ??= dirname($page);
            @unlink($page);
        }

        if ($dir !== null) {
            @rmdir($dir);
        }
    }
}
