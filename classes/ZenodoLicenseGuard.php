<?php

namespace APP\plugins\generic\kisiZenodo\classes;

use APP\core\Application;
use PKP\config\Config;

class ZenodoLicenseGuard
{
    private const PUBLIC_KEY_PEM = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAElIsGbcwMzF270ZRkiQCTIXpM1+qR
RV33pIE77o72ySzWCM/oCdH4v23BxIRghKrcFDPytgRw681FopCu/1kz3A==
-----END PUBLIC KEY-----
PEM;

    public static function sanitizeToken(string $rawToken): string
    {
        $clean = trim($rawToken, " \t\n\r\0\x0B\"'");
        return preg_replace('/\s+/', '', $clean) ?? '';
    }

    public static function resolveCurrentHost($request = null): string
    {
        $candidates = [];

        if ($request === null) {
            try {
                $request = Application::get()->getRequest();
            } catch (\Throwable $e) {
                $request = null;
            }
        }

        if (is_object($request) && method_exists($request, 'getServerHost')) {
            try {
                $candidates[] = (string) $request->getServerHost(null, false);
            } catch (\Throwable $e) {

            }
        }

        if (!empty($_SERVER['HTTP_HOST'])) {
            $candidates[] = (string) $_SERVER['HTTP_HOST'];
        }
        if (!empty($_SERVER['SERVER_NAME'])) {
            $candidates[] = (string) $_SERVER['SERVER_NAME'];
        }

        try {
            $baseUrl = (string) Config::getVar('general', 'base_url');
            if ($baseUrl !== '') {
                $parsedHost = parse_url($baseUrl, PHP_URL_HOST);
                if (is_string($parsedHost) && $parsedHost !== '') {
                    $candidates[] = $parsedHost;
                }
            }
        } catch (\Throwable $e) {

        }

        foreach ($candidates as $cand) {
            $host = strtolower(trim($cand));
            $host = (string) preg_replace('/:\d+$/', '', $host);
            $host = rtrim($host, '.');
            if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
                return $host;
            }
        }

        foreach ($candidates as $cand) {
            $host = rtrim((string) preg_replace('/:\d+$/', '', strtolower(trim($cand))), '.');
            if ($host !== '') {
                return $host;
            }
        }

        return '';
    }

    public static function verify(string $rawToken, ?string $currentHost = null): array
    {
        $host = $currentHost !== null
            ? rtrim((string) preg_replace('/:\d+$/', '', strtolower(trim($currentHost))), '.')
            : self::resolveCurrentHost();

        $token = self::sanitizeToken($rawToken);
        if ($token === '') {
            return [
                'valid' => false,
                'holder' => '',
                'domainRule' => '',
                'currentHost' => $host,
                'reason' => 'Activation Token belum diisi.',
            ];
        }

        $parts = explode('~', $token, 4);
        if (count($parts) !== 4) {
            return [
                'valid' => false,
                'holder' => '',
                'domainRule' => '',
                'currentHost' => $host,
                'reason' => 'Format Activation Token tidak dikenali.',
            ];
        }

        [$version, $holder, $domainRule, $hexSig] = $parts;
        $holder = strtolower(trim($holder));
        $domainRule = rtrim(strtolower(trim($domainRule)), '.');
        $hexSig = strtolower(trim($hexSig));

        if (
            $version !== 'KISI1'
            || !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $holder)
            || ($domainRule !== 'all' && !preg_match('/^[a-z0-9][a-z0-9.-]{1,252}$/', $domainRule))
            || strlen($hexSig) < 120
            || strlen($hexSig) > 200
            || (strlen($hexSig) % 2) !== 0
            || !ctype_xdigit($hexSig)
        ) {
            return [
                'valid' => false,
                'holder' => '',
                'domainRule' => '',
                'currentHost' => $host,
                'reason' => 'Struktur Activation Token tidak valid.',
            ];
        }

        if (!function_exists('openssl_verify')) {
            return [
                'valid' => false,
                'holder' => '',
                'domainRule' => '',
                'currentHost' => $host,
                'reason' => 'Ekstensi PHP OpenSSL tidak tersedia di server.',
            ];
        }

        $sigBytes = hex2bin($hexSig);
        if ($sigBytes === false) {
            return [
                'valid' => false,
                'holder' => '',
                'domainRule' => '',
                'currentHost' => $host,
                'reason' => 'Tanda tangan digital token rusak.',
            ];
        }

        $canonicalMessage = "KISI1|kisiZenodo|{$holder}|{$domainRule}";
        $ok = @openssl_verify($canonicalMessage, $sigBytes, self::PUBLIC_KEY_PEM, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return [
                'valid' => false,
                'holder' => '',
                'domainRule' => '',
                'currentHost' => $host,
                'reason' => 'Tanda tangan kriptografi Activation Token tidak sah.',
            ];
        }

        if ($domainRule !== 'all') {
            $domainMatched = ($host !== '') && (
                $host === $domainRule || str_ends_with($host, '.' . $domainRule)
            );
            if (!$domainMatched) {
                $dispHost = $host !== '' ? $host : '(unknown)';
                return [
                    'valid' => false,
                    'holder' => $holder,
                    'domainRule' => $domainRule,
                    'currentHost' => $host,
                    'reason' => "Token ini dikunci khusus untuk domain '{$domainRule}', tidak dapat dipakai di '{$dispHost}'.",
                ];
            }
        }

        return [
            'valid' => true,
            'holder' => $holder,
            'domainRule' => $domainRule,
            'currentHost' => $host,
            'reason' => '',
        ];
    }
}
