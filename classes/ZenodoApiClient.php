<?php

/**
 * @file plugins/generic/kisiZenodo/classes/ZenodoApiClient.php
 *
 * InvenioRDM v1 REST API client for Zenodo & Zenodo Sandbox.
 */

namespace APP\plugins\generic\kisiZenodo\classes;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ZenodoApiClient
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    private string $baseUrl;
    private string $webBaseUrl;
    private string $token;
    private string $environment;

    public function __construct(string $environment, string $token)
    {
        $this->environment = ($environment === self::ENV_PRODUCTION) ? self::ENV_PRODUCTION : self::ENV_SANDBOX;
        $this->webBaseUrl = ($this->environment === self::ENV_PRODUCTION)
            ? 'https://zenodo.org'
            : 'https://sandbox.zenodo.org';
        $this->baseUrl = $this->webBaseUrl . '/api';
        $this->token = self::sanitizeToken($token);
    }

    public static function sanitizeToken(string $rawToken): string
    {
        $clean = trim($rawToken, " \t\n\r\0\x0B\"'");
        $clean = (string) preg_replace('/^Bearer\s+/i', '', $clean);
        return preg_replace('/\s+/', '', $clean) ?? '';
    }

    public function getWebBaseUrl(): string
    {
        return $this->webBaseUrl;
    }

    public function getRecordHtmlUrl(string $recordId, bool $isDraft = false): string
    {
        $cleanId = rawurlencode(trim($recordId));
        return $isDraft
            ? ($this->webBaseUrl . '/uploads/' . $cleanId)
            : ($this->webBaseUrl . '/records/' . $cleanId);
    }

    /**
     * Validate Personal Access Token and optionally verify Community slug resolution.
     */
    public function testConnection(?string $communitySlug = null): array
    {
        if ($this->token === '') {
            throw new RuntimeException('Personal Access Token kosong.');
        }

        try {
            $this->requestJson('GET', '/user/records?size=1');
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 403') || str_contains($e->getMessage(), 'HTTP 401')) {
                $otherEnv = ($this->environment === self::ENV_PRODUCTION)
                    ? self::ENV_SANDBOX
                    : self::ENV_PRODUCTION;
                $otherClient = new self($otherEnv, $this->token);
                try {
                    $otherClient->requestJson('GET', '/user/records?size=1');
                    $currentLabel = strtoupper($this->environment) . " ({$this->webBaseUrl})";
                    $otherLabel = strtoupper($otherEnv) . " ({$otherClient->getWebBaseUrl()})";
                    throw new RuntimeException(
                        "Token terdeteksi milik {$otherLabel}, tetapi mode aktif plugin saat ini adalah {$currentLabel}. "
                        . "Buka Settings plugin untuk menyesuaikan Mode Environment atau buat token baru di {$this->webBaseUrl}."
                    );
                } catch (RuntimeException $probeErr) {
                    if (str_contains($probeErr->getMessage(), 'Token terdeteksi milik')) {
                        throw $probeErr;
                    }
                }

                throw new RuntimeException(
                    $e->getMessage()
                    . " — Penyebab umum di Zenodo: (1) Token dibuat di zenodo.org tetapi dipakai di mode Sandbox (atau sebaliknya), "
                    . "(2) Email akun Zenodo/Sandbox belum diverifikasi (cek kotak masuk email Anda), atau "
                    . "(3) Token tidak tersalin utuh / sudah dicabut. Buat ulang Personal Access Token di {$this->webBaseUrl} dengan scope deposit:write & deposit:actions."
                );
            }
            throw $e;
        }

        $communityInfo = null;
        $slug = trim((string) $communitySlug);
        if ($slug !== '') {
            $comm = $this->requestJson('GET', '/communities/' . rawurlencode($slug), null, false);
            $communityInfo = [
                'id' => (string) ($comm['id'] ?? ''),
                'slug' => (string) ($comm['slug'] ?? $slug),
                'title' => (string) ($comm['metadata']['title'] ?? $slug),
            ];
        }

        return [
            'ok' => true,
            'environment' => $this->environment,
            'community' => $communityInfo,
        ];
    }

    /**
     * Resolve Zenodo Community slug (e.g. "kisibsmk") into its InvenioRDM UUID.
     */
    public function resolveCommunityUuid(string $slugOrUuid): ?string
    {
        $clean = trim($slugOrUuid);
        if ($clean === '') {
            return null;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clean)) {
            return strtolower($clean);
        }

        $cacheKey = 'kisi_zenodo_comm_uuid_' . $this->environment . '_' . md5(strtolower($clean));
        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable $e) {
            // Fall through to live lookup
        }

        $resp = $this->requestJson('GET', '/communities/' . rawurlencode($clean), null, false);
        $uuid = trim((string) ($resp['id'] ?? ''));
        if ($uuid === '') {
            throw new RuntimeException("Community Zenodo '{$clean}' tidak ditemukan pada {$this->webBaseUrl}.");
        }

        try {
            Cache::put($cacheKey, $uuid, 86400);
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return $uuid;
    }

    /**
     * Create a new Zenodo InvenioRDM draft record, automatically retrying without ROR IDs if Zenodo's ROR vocab lags.
     */
    public function createDraft(array $payload): array
    {
        try {
            return $this->requestJson('POST', '/records', $payload);
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'affiliations') !== false) {
                return $this->requestJson('POST', '/records', $this->stripAffiliationIds($payload));
            }
            throw $e;
        }
    }

    /**
     * Update metadata of an existing Zenodo draft record.
     */
    public function updateDraft(string $recordId, array $payload): array
    {
        $encId = rawurlencode($recordId);
        try {
            return $this->requestJson('PUT', "/records/{$encId}/draft", $payload);
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'affiliations') !== false) {
                return $this->requestJson('PUT', "/records/{$encId}/draft", $this->stripAffiliationIds($payload));
            }
            throw $e;
        }
    }

    public function getDraft(string $recordId): array
    {
        return $this->requestJson('GET', '/records/' . rawurlencode($recordId) . '/draft');
    }

    /**
     * Pre-reserve an official Zenodo DOI for an existing draft record.
     */
    public function reserveDoi(string $recordId): array
    {
        $encId = rawurlencode($recordId);
        $draft = $this->getDraft($recordId);
        $existingDoi = trim((string) ($draft['pids']['doi']['identifier'] ?? ''));
        if ($existingDoi === '') {
            $draft = $this->requestJson('POST', "/records/{$encId}/draft/pids/doi", new \stdClass());
            $existingDoi = trim((string) ($draft['pids']['doi']['identifier'] ?? ''));
        }

        if ($existingDoi === '') {
            throw new RuntimeException('Zenodo tidak mengembalikan identifier DOI pada draft #' . $recordId);
        }

        return [
            'doi' => $existingDoi,
            'conceptDoi' => trim((string) ($draft['parent']['pids']['doi']['identifier'] ?? '')),
            'draft' => $draft,
        ];
    }

    /**
     * List all files currently attached to a draft record.
     */
    public function listDraftFiles(string $recordId): array
    {
        $encId = rawurlencode($recordId);
        $resp = $this->requestJson('GET', "/records/{$encId}/draft/files");
        $entries = $resp['entries'] ?? [];
        return is_array($entries) ? $entries : [];
    }

    /**
     * Delete a file from a draft record (e.g., removing temporary Production DOCX before uploading final PDF).
     */
    public function deleteDraftFile(string $recordId, string $fileKey): void
    {
        $encId = rawurlencode($recordId);
        $encKey = rawurlencode($fileKey);
        $this->requestRaw('DELETE', "/records/{$encId}/draft/files/{$encKey}", null, [
            'Accept: application/vnd.inveniordm.v1+json',
        ]);
    }

    /**
     * Upload a binary file into an InvenioRDM draft record using the 3-step files lifecycle.
     *
     * @param resource|string $streamOrBinaryFilePath
     */
    public function uploadDraftFile(string $recordId, string $fileName, $streamOrBinaryFilePath, ?int $sizeBytes = null): array
    {
        $encId = rawurlencode($recordId);
        $cleanKey = preg_replace('/[^\w\s.\-()]/u', '_', basename($fileName)) ?: ('article_' . $recordId . '.bin');
        $encKey = rawurlencode($cleanKey);

        // 1. Initialize file slot in draft
        $this->requestJson('POST', "/records/{$encId}/draft/files", [
            ['key' => $cleanKey],
        ]);

        // 2. Stream binary content
        $openedLocally = false;
        if (is_resource($streamOrBinaryFilePath)) {
            $stream = $streamOrBinaryFilePath;
        } elseif (is_string($streamOrBinaryFilePath) && is_file($streamOrBinaryFilePath)) {
            $sizeBytes = $sizeBytes ?? (int) filesize($streamOrBinaryFilePath);
            $stream = fopen($streamOrBinaryFilePath, 'rb');
            $openedLocally = true;
        } else {
            throw new RuntimeException('File sumber tidak dapat dibaca untuk diunggah ke Zenodo: ' . $cleanKey);
        }

        if (!is_resource($stream)) {
            throw new RuntimeException('Gagal membuka stream file untuk diunggah ke Zenodo: ' . $cleanKey);
        }

        try {
            $this->streamPutBinary("/records/{$encId}/draft/files/{$encKey}/content", $stream, $sizeBytes);
        } finally {
            if ($openedLocally && is_resource($stream)) {
                fclose($stream);
            }
        }

        // 3. Commit uploaded file
        return $this->requestJson('POST', "/records/{$encId}/draft/files/{$encKey}/commit", new \stdClass());
    }

    /**
     * Publish a draft record, automatically routing & accepting into the configured Zenodo Community when set.
     */
    public function publishDraft(string $recordId, ?string $communityUuid = null): array
    {
        $encId = rawurlencode($recordId);

        if ($communityUuid !== null && $communityUuid !== '') {
            $reviewResp = $this->requestJson('PUT', "/records/{$encId}/draft/review", [
                'receiver' => ['community' => $communityUuid],
                'type' => 'community-submission',
            ]);
            $requestId = trim((string) ($reviewResp['id'] ?? ''));
            if ($requestId === '') {
                throw new RuntimeException('Gagal membuat permintaan review community Zenodo untuk draft #' . $recordId);
            }

            $this->requestJson('POST', "/records/{$encId}/draft/actions/submit-review", [
                'payload' => [
                    'content' => 'Submitted automatically by OJS KisiZenodo Plugin.',
                    'format' => 'html',
                ],
            ]);

            $encReqId = rawurlencode($requestId);
            $this->requestJson('POST', "/requests/{$encReqId}/actions/accept", [
                'payload' => [
                    'content' => 'Accepted and published automatically by OJS KisiZenodo Plugin.',
                    'format' => 'html',
                ],
            ]);

            $published = $this->requestJson('GET', "/records/{$encId}");
            $published['_request_id'] = $requestId;
            return $published;
        }

        return $this->requestJson('POST', "/records/{$encId}/draft/actions/publish", new \stdClass());
    }

    private function stripAffiliationIds(array $payload): array
    {
        if (!empty($payload['metadata']['creators']) && is_array($payload['metadata']['creators'])) {
            foreach ($payload['metadata']['creators'] as &$creator) {
                if (!empty($creator['affiliations']) && is_array($creator['affiliations'])) {
                    foreach ($creator['affiliations'] as &$aff) {
                        if (isset($aff['name']) && trim((string) $aff['name']) !== '') {
                            unset($aff['id']);
                        }
                    }
                    unset($aff);
                }
            }
            unset($creator);
        }
        return $payload;
    }

    /**
     * @param array|\stdClass|null $body
     */
    private function requestJson(string $method, string $endpoint, $body = null, bool $requireAuth = true): array
    {
        $headers = [
            'Accept: application/vnd.inveniordm.v1+json',
        ];
        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $raw = $this->requestRaw($method, $endpoint, $encodedBody, $headers, $requireAuth);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Respons JSON Zenodo tidak valid pada {$method} {$endpoint}");
        }

        return $decoded;
    }

    private function requestRaw(string $method, string $endpoint, ?string $body = null, array $headers = [], bool $requireAuth = true): string
    {
        if ($requireAuth) {
            if ($this->token === '') {
                throw new RuntimeException('Personal Access Token Zenodo belum dikonfigurasi pada pengaturan plugin.');
            }
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Gagal menginisialisasi koneksi cURL ke Zenodo.');
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'OJS-KisiZenodoPlugin/1.0',
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);
        $responseBody = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException("Koneksi ke Zenodo gagal ({$method} {$endpoint}): {$curlErr}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException($this->formatApiError($method, $endpoint, $httpCode, (string) $responseBody));
        }

        return (string) $responseBody;
    }

    /**
     * @param resource $stream
     */
    private function streamPutBinary(string $endpoint, $stream, ?int $sizeBytes = null): void
    {
        if ($this->token === '') {
            throw new RuntimeException('Personal Access Token Zenodo belum dikonfigurasi.');
        }

        $meta = stream_get_meta_data($stream);
        if (!empty($meta['seekable'])) {
            rewind($stream);
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.inveniordm.v1+json',
            'Content-Type: application/octet-stream',
        ];

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Gagal menginisialisasi cURL untuk upload file ke Zenodo.');
        }

        // If stream is not seekable or size unknown, buffer safely; otherwise stream directly via CURLOPT_UPLOAD
        if ($sizeBytes !== null && $sizeBytes >= 0 && !empty($meta['seekable'])) {
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_INFILE => $stream,
                CURLOPT_INFILESIZE => $sizeBytes,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'OJS-KisiZenodoPlugin/1.0',
            ]);
        } else {
            $binary = stream_get_contents($stream);
            if ($binary === false) {
                curl_close($ch);
                throw new RuntimeException('Gagal membaca isi file artikel dari penyimpanan OJS.');
            }
            $headers[] = 'Content-Length: ' . strlen($binary);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $binary,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'OJS-KisiZenodoPlugin/1.0',
            ]);
        }

        $responseBody = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException("Upload file ke Zenodo gagal: {$curlErr}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException($this->formatApiError('PUT', $endpoint, $httpCode, (string) $responseBody));
        }
    }

    private function formatApiError(string $method, string $endpoint, int $httpCode, string $rawBody): string
    {
        $detail = '';
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $msg = (string) ($decoded['message'] ?? '');
            $errors = [];
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                foreach ($decoded['errors'] as $errItem) {
                    $field = (string) ($errItem['field'] ?? '');
                    $msgs = isset($errItem['messages']) && is_array($errItem['messages'])
                        ? implode('; ', $errItem['messages'])
                        : (string) ($errItem['message'] ?? '');
                    $errors[] = ($field !== '' ? "{$field}: " : '') . $msgs;
                }
            }
            $detail = trim($msg . (!empty($errors) ? ' [' . implode(' | ', $errors) . ']' : ''));
        }
        if ($detail === '') {
            $detail = substr(trim(strip_tags($rawBody)), 0, 280);
        }

        return "Zenodo API HTTP {$httpCode} ({$method} {$endpoint}): " . ($detail ?: 'Unknown error');
    }
}
