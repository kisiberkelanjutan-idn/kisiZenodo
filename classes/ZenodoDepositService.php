<?php

/**
 * @file plugins/generic/kisiZenodo/classes/ZenodoDepositService.php
 *
 * Orchestrates 2-stage Zenodo DOI reservation (Production DOCX draft) and final PDF Galley publication.
 */

namespace APP\plugins\generic\kisiZenodo\classes;

use APP\core\Services;
use APP\facades\Repo;
use APP\plugins\generic\kisiZenodo\classes\migration\KisiZenodoMigration;
use APP\plugins\generic\kisiZenodo\KisiZenodoPlugin;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\context\Context;
use PKP\doi\Doi;
use PKP\submissionFile\SubmissionFile;
use RuntimeException;

class ZenodoDepositService
{
    private KisiZenodoPlugin $plugin;

    public function __construct(KisiZenodoPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Stage 1: Create/reuse Zenodo draft, upload Production `.docx` file, pre-reserve DOI, and assign DOI to OJS Publication.
     */
    public function preReserveForProduction(Submission $submission, Context $context, bool $forceRetry = false): array
    {
        $contextId = (int) $context->getId();
        $submissionId = (int) $submission->getId();
        $settings = $this->plugin->getEffectiveSettings($contextId);
        $env = $settings['environment'];

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            throw new RuntimeException("Submission #{$submissionId} tidak memiliki Current Publication.");
        }
        $pubId = (int) $publication->getId();

        $existing = $this->getDepositRecord($contextId, $submissionId, $env);
        if (
            !$forceRetry
            && $existing
            && in_array($existing->status, ['draft_reserved', 'published'], true)
            && !empty($existing->reserved_doi)
        ) {
            $this->assignDoiToPublication($publication, $contextId, (string) $existing->reserved_doi, (
                $existing->status === 'published' ? Doi::STATUS_REGISTERED : Doi::STATUS_UNREGISTERED
            ));
            return [
                'status' => (string) $existing->status,
                'doi' => (string) $existing->reserved_doi,
                'recordId' => (string) $existing->zenodo_record_id,
                'reused' => true,
            ];
        }

        try {
            $prodFile = $this->findProductionReadyFile($submissionId);
            if (!$prodFile) {
                throw new RuntimeException(
                    "File dokumen Production (.docx) belum ditemukan pada Submission #{$submissionId}. "
                    . 'Pastikan file dari tahap Copyediting sudah dikirim ke tahap Production.'
                );
            }

            $client = $this->buildApiClient($settings);
            $builder = new ZenodoMetadataBuilder();
            $payload = $builder->buildRecordPayload($submission, $publication, $context, $settings);

            $recordId = ($existing && !empty($existing->zenodo_record_id) && $existing->status !== 'published')
                ? (string) $existing->zenodo_record_id
                : '';

            if ($recordId !== '') {
                try {
                    $client->updateDraft($recordId, $payload);
                } catch (\Throwable $updateErr) {
                    $recordId = '';
                }
            }

            if ($recordId === '') {
                $draft = $client->createDraft($payload);
                $recordId = trim((string) ($draft['id'] ?? ''));
                if ($recordId === '') {
                    throw new RuntimeException('Gagal memperoleh ID draft baru dari Zenodo.');
                }
            }

            // Clean any previous draft files on retry so upload doesn't collide with an existing key
            foreach ($client->listDraftFiles($recordId) as $entry) {
                $oldKey = (string) ($entry['key'] ?? '');
                if ($oldKey !== '') {
                    $client->deleteDraftFile($recordId, $oldKey);
                }
            }

            $uploadFileName = $this->resolveSubmissionFileName($prodFile, 'production_manuscript_' . $submissionId . '.docx');
            $this->uploadSubmissionFileToDraft($client, $recordId, $prodFile, $uploadFileName);

            $reserved = $client->reserveDoi($recordId);
            $doiString = $reserved['doi'];
            $conceptDoi = $reserved['conceptDoi'];

            $this->assignDoiToPublication($publication, $contextId, $doiString, Doi::STATUS_UNREGISTERED);

            $this->upsertDepositRecord($contextId, $submissionId, $env, [
                'publication_id' => $pubId,
                'zenodo_record_id' => $recordId,
                'reserved_doi' => $doiString,
                'concept_doi' => $conceptDoi ?: null,
                'uploaded_filename' => $uploadFileName,
                'status' => 'draft_reserved',
                'last_error' => null,
                'synced_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'status' => 'draft_reserved',
                'doi' => $doiString,
                'recordId' => $recordId,
                'uploadedFile' => $uploadFileName,
                'reused' => false,
            ];
        } catch (\Throwable $e) {
            $this->recordError($contextId, $submissionId, $pubId, $env, $e->getMessage(), false);
            throw $e;
        }
    }

    /**
     * Stage 2: Sync final OJS 3.5 metadata, replace temporary `.docx` with final `.pdf` Galley,
     * and publish into the configured Zenodo Community (e.g., `kisibsmk`).
     */
    public function finalizeAndPublish(Submission $submission, $publication, Context $context): array
    {
        $contextId = (int) $context->getId();
        $submissionId = (int) $submission->getId();
        $pubId = (int) $publication->getId();
        $settings = $this->plugin->getEffectiveSettings($contextId);
        $env = $settings['environment'];

        $existing = $this->getDepositRecord($contextId, $submissionId, $env);
        if ($existing && $existing->status === 'published' && !empty($existing->reserved_doi)) {
            $this->assignDoiToPublication($publication, $contextId, (string) $existing->reserved_doi, Doi::STATUS_REGISTERED);
            return [
                'status' => 'published',
                'doi' => (string) $existing->reserved_doi,
                'recordId' => (string) $existing->zenodo_record_id,
                'reused' => true,
            ];
        }

        try {
            $pdfFile = $this->findPdfGalleyFile($publication, $submissionId);
            if (!$pdfFile) {
                throw new RuntimeException(
                    "File PDF Galley belum ditemukan pada Submission #{$submissionId}. "
                    . 'Unggah file PDF pada tab Galleys sebelum menerbitkan ke Zenodo.'
                );
            }

            $client = $this->buildApiClient($settings);
            $builder = new ZenodoMetadataBuilder();
            $payload = $builder->buildRecordPayload($submission, $publication, $context, $settings);

            $recordId = ($existing && !empty($existing->zenodo_record_id))
                ? (string) $existing->zenodo_record_id
                : '';

            if ($recordId !== '') {
                try {
                    $client->updateDraft($recordId, $payload);
                } catch (\Throwable $e) {
                    $recordId = '';
                }
            }

            if ($recordId === '') {
                $draft = $client->createDraft($payload);
                $recordId = trim((string) ($draft['id'] ?? ''));
                if ($recordId === '') {
                    throw new RuntimeException('Gagal membuat draft Zenodo untuk publikasi PDF.');
                }
            }

            $pdfName = $this->resolveSubmissionFileName($pdfFile, 'KisiBSMK_Article_' . $submissionId . '.pdf');

            // Remove temporary Production DOCX (or any stale draft files) when replaceDocxOnPublish is enabled
            $existingDraftFiles = $client->listDraftFiles($recordId);
            foreach ($existingDraftFiles as $entry) {
                $oldKey = (string) ($entry['key'] ?? '');
                if ($oldKey === '') {
                    continue;
                }
                if (!empty($settings['replaceDocxOnPublish']) || strcasecmp($oldKey, $pdfName) === 0) {
                    $client->deleteDraftFile($recordId, $oldKey);
                }
            }

            $this->uploadSubmissionFileToDraft($client, $recordId, $pdfFile, $pdfName);

            // Ensure DOI is reserved before publishing
            $reserved = $client->reserveDoi($recordId);
            $doiString = $reserved['doi'];

            $communityUuid = null;
            if ($settings['communitySlug'] !== '') {
                $communityUuid = $client->resolveCommunityUuid($settings['communitySlug']);
            }

            $publishedResp = $client->publishDraft($recordId, $communityUuid);
            $finalDoi = trim((string) ($publishedResp['pids']['doi']['identifier'] ?? $doiString));
            $conceptDoi = trim((string) ($publishedResp['parent']['pids']['doi']['identifier'] ?? $reserved['conceptDoi']));
            $requestId = trim((string) ($publishedResp['_request_id'] ?? ''));

            $this->assignDoiToPublication($publication, $contextId, $finalDoi, Doi::STATUS_REGISTERED);

            $this->upsertDepositRecord($contextId, $submissionId, $env, [
                'publication_id' => $pubId,
                'zenodo_record_id' => $recordId,
                'zenodo_request_id' => $requestId ?: null,
                'reserved_doi' => $finalDoi,
                'concept_doi' => $conceptDoi ?: null,
                'uploaded_filename' => $pdfName,
                'status' => 'published',
                'last_error' => null,
                'synced_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'status' => 'published',
                'doi' => $finalDoi,
                'recordId' => $recordId,
                'uploadedFile' => $pdfName,
                'reused' => false,
            ];
        } catch (\Throwable $e) {
            $this->recordError($contextId, $submissionId, $pubId, $env, $e->getMessage(), true);
            throw $e;
        }
    }

    /**
     * Find the latest `.docx` (or manuscript document) in Production Ready stage (with fallback to Copyedited stage).
     */
    public function findProductionReadyFile(int $submissionId): ?SubmissionFile
    {
        $stages = [
            SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
            SubmissionFile::SUBMISSION_FILE_COPYEDIT,
        ];

        foreach ($stages as $stage) {
            try {
                $files = iterator_to_array(
                    Repo::submissionFile()
                        ->getCollector()
                        ->filterBySubmissionIds([$submissionId])
                        ->filterByFileStages([$stage])
                        ->getMany()
                );
            } catch (\Throwable $e) {
                $files = [];
            }

            if (empty($files)) {
                continue;
            }

            // Sort newest first by ID
            usort($files, static fn ($a, $b): int => ((int) $b->getId()) <=> ((int) $a->getId()));

            // Prefer .docx / Word documents first
            foreach ($files as $file) {
                $path = strtolower((string) $file->getData('path'));
                $mime = strtolower((string) $file->getData('mimetype'));
                $name = strtolower((string) $file->getLocalizedData('name'));
                if (
                    str_ends_with($path, '.docx')
                    || str_ends_with($name, '.docx')
                    || str_contains($mime, 'wordprocessingml')
                    || str_ends_with($path, '.doc')
                    || str_ends_with($name, '.doc')
                ) {
                    return $file;
                }
            }

            return $files[0];
        }

        return null;
    }

    /**
     * Find the primary PDF Galley SubmissionFile attached to a Publication.
     */
    public function findPdfGalleyFile($publication, int $submissionId): ?SubmissionFile
    {
        $galleys = $publication->getData('galleys') ?? [];
        foreach ($galleys as $galley) {
            $fileId = (int) ($galley->getData('submissionFileId') ?: $galley->getData('fileId'));
            if ($fileId <= 0) {
                continue;
            }
            $label = strtolower((string) $galley->getGalleyLabel());
            try {
                $subFile = Repo::submissionFile()->get($fileId);
                if (!$subFile) {
                    continue;
                }
                $mime = strtolower((string) $subFile->getData('mimetype'));
                $path = strtolower((string) $subFile->getData('path'));
                if ($mime === 'application/pdf' || str_ends_with($path, '.pdf') || str_contains($label, 'pdf')) {
                    return $subFile;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Fallback: check PROOF stage PDF files on submission
        try {
            $proofFiles = Repo::submissionFile()
                ->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])
                ->getMany();
            foreach ($proofFiles as $pf) {
                $mime = strtolower((string) $pf->getData('mimetype'));
                $path = strtolower((string) $pf->getData('path'));
                if ($mime === 'application/pdf' || str_ends_with($path, '.pdf')) {
                    return $pf;
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return null;
    }

    public function getDepositRecord(int $contextId, int $submissionId, string $environment): ?object
    {
        $this->plugin->ensureDatabaseSchema();
        return DB::table(KisiZenodoMigration::TABLE_NAME)
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->where('environment', $environment)
            ->first();
    }

    private function buildApiClient(array $settings): ZenodoApiClient
    {
        return new ZenodoApiClient($settings['environment'], $settings['token']);
    }

    private function resolveSubmissionFileName(SubmissionFile $file, string $fallback): string
    {
        $rawName = trim((string) $file->getLocalizedData('name'));
        $relPath = (string) $file->getData('path');
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));

        if ($rawName === '') {
            return $ext !== '' && !str_ends_with(strtolower($fallback), '.' . $ext)
                ? ($fallback . '.' . $ext)
                : $fallback;
        }

        if ($ext !== '' && strtolower(pathinfo($rawName, PATHINFO_EXTENSION)) !== $ext) {
            $rawName .= '.' . $ext;
        }

        return $rawName;
    }

    private function uploadSubmissionFileToDraft(ZenodoApiClient $client, string $recordId, SubmissionFile $file, string $uploadName): void
    {
        $relPath = ltrim((string) $file->getData('path'), '/\\');
        if ($relPath === '') {
            throw new RuntimeException('Path file kosong pada penyimpanan OJS.');
        }

        $filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/\\');
        $absPath = $filesDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath);

        if ($filesDir !== '' && is_file($absPath) && is_readable($absPath)) {
            $client->uploadDraftFile($recordId, $uploadName, $absPath, (int) filesize($absPath));
            return;
        }

        // Fallback to OJS Flysystem service stream
        $fs = Services::get('file')->fs;
        $stream = $fs->readStream($relPath);
        if (!is_resource($stream)) {
            throw new RuntimeException('Tidak dapat membaca stream file dari files_dir OJS: ' . $relPath);
        }
        try {
            $size = null;
            try {
                $size = (int) $fs->fileSize($relPath);
            } catch (\Throwable $e) {
                $size = null;
            }
            $client->uploadDraftFile($recordId, $uploadName, $stream, $size);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Store or update the DOI on OJS 3.5's `dois` and `publications` tables (supports both 10.5281 and 10.5072 Sandbox prefixes).
     */
    private function assignDoiToPublication($publication, int $contextId, string $doiString, int $doiStatus): void
    {
        $cleanDoi = trim($doiString);
        if ($cleanDoi === '') {
            return;
        }

        $pubId = (int) $publication->getId();
        $currentDoiId = (int) ($publication->getData('doiId') ?? 0);
        $doiId = 0;

        try {
            if ($currentDoiId > 0) {
                $doiObj = Repo::doi()->get($currentDoiId, $contextId);
                if ($doiObj) {
                    Repo::doi()->edit($doiObj, [
                        'doi' => $cleanDoi,
                        'status' => $doiStatus,
                        'registrationAgency' => null,
                    ]);
                    $doiId = $currentDoiId;
                }
            }

            if ($doiId <= 0) {
                $existingRow = DB::table('dois')
                    ->where('context_id', $contextId)
                    ->where('doi', $cleanDoi)
                    ->first();

                if ($existingRow) {
                    $doiId = (int) $existingRow->doi_id;
                    $existingObj = Repo::doi()->get($doiId, $contextId);
                    if ($existingObj) {
                        Repo::doi()->edit($existingObj, [
                            'status' => $doiStatus,
                            'registrationAgency' => null,
                        ]);
                    } else {
                        DB::table('dois')->where('doi_id', $doiId)->update(['status' => $doiStatus]);
                    }
                } else {
                    $newDoi = Repo::doi()->newDataObject([
                        'contextId' => $contextId,
                        'doi' => $cleanDoi,
                        'status' => $doiStatus,
                        'registrationAgency' => null,
                    ]);
                    $doiId = (int) Repo::doi()->add($newDoi);
                }

                DB::table('publications')
                    ->where('publication_id', $pubId)
                    ->update(['doi_id' => $doiId]);
            }
        } catch (\Throwable $e) {
            // Direct database fallback in case OJS core prefix validation differs from Sandbox 10.5072
            $row = DB::table('dois')
                ->where('context_id', $contextId)
                ->where('doi', $cleanDoi)
                ->first();

            if ($row) {
                $doiId = (int) $row->doi_id;
                DB::table('dois')->where('doi_id', $doiId)->update(['status' => $doiStatus]);
            } else {
                $doiId = (int) DB::table('dois')->insertGetId([
                    'context_id' => $contextId,
                    'doi' => $cleanDoi,
                    'status' => $doiStatus,
                ]);
            }

            DB::table('publications')
                ->where('publication_id', $pubId)
                ->update(['doi_id' => $doiId]);
        }

        if ($doiId > 0) {
            $publication->setData('doiId', $doiId);
            try {
                $freshDoi = Repo::doi()->get($doiId, $contextId);
                if ($freshDoi) {
                    $publication->setData('doiObject', $freshDoi);
                }
            } catch (\Throwable $ignored) {
                // Ignore in-memory refresh error
            }
        }
    }

    private function upsertDepositRecord(int $contextId, int $submissionId, string $environment, array $values): void
    {
        $this->plugin->ensureDatabaseSchema();
        $now = date('Y-m-d H:i:s');
        $existing = DB::table(KisiZenodoMigration::TABLE_NAME)
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->where('environment', $environment)
            ->first();

        if ($existing) {
            DB::table(KisiZenodoMigration::TABLE_NAME)
                ->where('deposit_id', $existing->deposit_id)
                ->update(array_merge($values, ['updated_at' => $now]));
        } else {
            DB::table(KisiZenodoMigration::TABLE_NAME)->insert(array_merge([
                'context_id' => $contextId,
                'submission_id' => $submissionId,
                'environment' => $environment,
                'created_at' => $now,
                'updated_at' => $now,
            ], $values));
        }
    }

    private function recordError(
        int $contextId,
        int $submissionId,
        int $pubId,
        string $environment,
        string $errorMsg,
        bool $markOjsDoiError = false
    ): void {
        try {
            $existing = $this->getDepositRecord($contextId, $submissionId, $environment);
            $keepStatus = ($existing && in_array($existing->status, ['draft_reserved', 'published'], true))
                ? (string) $existing->status
                : 'error';

            $this->upsertDepositRecord($contextId, $submissionId, $environment, [
                'publication_id' => $pubId,
                'status' => $keepStatus,
                'last_error' => substr($errorMsg, 0, 2000),
            ]);

            if ($markOjsDoiError && (!$existing || $existing->status !== 'published')) {
                $doiId = (int) (DB::table('publications')->where('publication_id', $pubId)->value('doi_id') ?? 0);
                if ($doiId > 0) {
                    DB::table('dois')->where('doi_id', $doiId)->where('context_id', $contextId)->update([
                        'status' => Doi::STATUS_ERROR,
                    ]);
                }
            }
        } catch (\Throwable $ignored) {
            // Never throw inside error logger
        }
    }
}
