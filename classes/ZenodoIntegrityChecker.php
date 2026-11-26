<?php

/**
 * @file plugins/generic/kisiZenodo/classes/ZenodoIntegrityChecker.php
 *
 * Verifies live server file integrity against SHA256SUMS and detects unauthorized/injected files
 * inside `plugins/generic/kisiZenodo` (and `plugins/themes/smkTheme` when present on the server).
 */

namespace APP\plugins\generic\kisiZenodo\classes;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ZenodoIntegrityChecker
{
    private const IGNORED_DEV_NAMES = [
        'SHA256SUMS',
        'desktop.ini',
        'Thumbs.db',
        '.DS_Store',
        '.env',
        '.env.local',
        '.cpanel.yml',
        '.gitignore',
        '.gitattributes',
    ];

    /**
     * Run SHA-256 verification and foreign-file detection on kisiZenodo and (if installed) smkTheme.
     */
    public function verifyAll(): array
    {
        $zenodoDir = dirname(__DIR__);
        $pluginsRoot = dirname($zenodoDir, 2);
        $smkThemeDir = $pluginsRoot . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'smkTheme';

        $modules = [
            'kisiZenodo' => $this->verifyDirectory('kisiZenodo', $zenodoDir),
        ];

        if (is_dir($smkThemeDir) && is_file($smkThemeDir . DIRECTORY_SEPARATOR . 'SHA256SUMS')) {
            $modules['smkTheme'] = $this->verifyDirectory('smkTheme', $smkThemeDir);
        }

        $allOk = true;
        $totalVerifiedFiles = 0;
        $issues = [];
        $verifiedNames = [];

        foreach ($modules as $name => $res) {
            $totalVerifiedFiles += $res['verifiedCount'];
            if ($res['ok']) {
                $verifiedNames[] = $name . ' (' . $res['verifiedCount'] . ' file)';
            } else {
                $allOk = false;
                foreach ($res['issues'] as $issue) {
                    $issues[] = "[{$name}] {$issue}";
                }
            }
        }

        return [
            'ok' => $allOk,
            'totalVerifiedFiles' => $totalVerifiedFiles,
            'summaryLabel' => implode(' + ', $verifiedNames),
            'issues' => $issues,
        ];
    }

    /**
     * Verify a single plugin directory against its `SHA256SUMS` manifest.
     */
    public function verifyDirectory(string $label, string $baseDir): array
    {
        $manifestPath = $baseDir . DIRECTORY_SEPARATOR . 'SHA256SUMS';
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return [
                'ok' => false,
                'verifiedCount' => 0,
                'issues' => ['File manifest SHA256SUMS tidak ditemukan di server.'],
            ];
        }

        $lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            return [
                'ok' => false,
                'verifiedCount' => 0,
                'issues' => ['Isi manifest SHA256SUMS kosong atau tidak terbaca.'],
            ];
        }

        $expectedHashes = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', $trimmed, $m)) {
                $relPath = str_replace('\\', '/', ltrim(trim($m[2]), './'));
                $expectedHashes[$relPath] = strtolower($m[1]);
            }
        }

        if (empty($expectedHashes)) {
            return [
                'ok' => false,
                'verifiedCount' => 0,
                'issues' => ['Format SHA256SUMS tidak valid.'],
            ];
        }

        $issues = [];
        $verifiedCount = 0;

        foreach ($expectedHashes as $relPath => $expectedSha) {
            $absPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            if (!is_file($absPath) || !is_readable($absPath)) {
                $issues[] = "File resmi hilang: {$relPath}";
                continue;
            }
            $actualSha = strtolower((string) hash_file('sha256', $absPath));
            if (!hash_equals($expectedSha, $actualSha)) {
                $issues[] = "Isi file berubah dari hash resmi: {$relPath}";
                continue;
            }
            $verifiedCount++;
        }

        // Scan directory recursively to catch any unlisted / injected file (e.g. webshells)
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            $prefixLen = strlen(rtrim($baseDir, '/\\')) + 1;
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($fileInfo->getPathname(), $prefixLen));
                if (
                    str_starts_with($rel, '.git/')
                    || str_starts_with($rel, '.github/')
                    || in_array($rel, self::IGNORED_DEV_NAMES, true)
                    || in_array(basename($rel), ['desktop.ini', 'Thumbs.db', '.DS_Store'], true)
                ) {
                    continue;
                }
                if (!isset($expectedHashes[$rel])) {
                    $issues[] = "Terdeteksi file asing di luar daftar resmi: {$rel}";
                }
            }
        } catch (\Throwable $e) {
            $issues[] = 'Gagal memindai direktori plugin: ' . $e->getMessage();
        }

        return [
            'ok' => empty($issues),
            'verifiedCount' => $verifiedCount,
            'issues' => $issues,
        ];
    }
}
