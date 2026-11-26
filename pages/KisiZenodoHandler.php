<?php

/**
 * @file plugins/generic/kisiZenodo/pages/KisiZenodoHandler.php
 *
 * Editorial backend dashboard handler (`/index.php/{journal}/kisiZenodo`) integrated into OJS 3.5's
 * native backend sidebar layout for testing Zenodo API connectivity, viewing Production & Published
 * manuscripts, and triggering 1-click DOI pre-reservation or publication.
 */

namespace APP\plugins\generic\kisiZenodo\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\kisiZenodo\classes\migration\KisiZenodoMigration;
use APP\plugins\generic\kisiZenodo\classes\ZenodoApiClient;
use APP\plugins\generic\kisiZenodo\classes\ZenodoDepositService;
use APP\plugins\generic\kisiZenodo\classes\ZenodoIntegrityChecker;
use APP\plugins\generic\kisiZenodo\KisiZenodoPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\core\PKPApplication;
use PKP\doi\Doi;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\RoleBasedHandlerOperationPolicy;
use PKP\security\Role;

class KisiZenodoHandler extends Handler
{
    public $_isBackendPage = true;

    private KisiZenodoPlugin $plugin;

    public function __construct(KisiZenodoPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;

        $allowedRoles = [
            Role::ROLE_ID_SITE_ADMIN,
            Role::ROLE_ID_MANAGER,
            Role::ROLE_ID_SUB_EDITOR,
        ];
        $this->addRoleAssignment($allowedRoles, [
            'index',
            'testConnection',
            'preReserve',
            'publishDoi',
        ]);
    }

    public function authorize($request, &$args, $roleAssignments): bool
    {
        $allowedRoles = [
            Role::ROLE_ID_SITE_ADMIN,
            Role::ROLE_ID_MANAGER,
            Role::ROLE_ID_SUB_EDITOR,
        ];
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        $this->addPolicy(new RoleBasedHandlerOperationPolicy(
            $request,
            $allowedRoles,
            ['index', 'testConnection', 'preReserve', 'publishDoi']
        ));

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Render the Zenodo DOI Manager dashboard inside OJS 3.5's native backend layout (`layouts/backend.tpl`).
     */
    public function index($args, $request): void
    {
        $context = $request->getContext();
        if (!$context) {
            $request->redirect(null, 'index');
            return;
        }

        $this->setupTemplate($request);

        $contextId = (int) $context->getId();
        $contextPath = $context->getPath();
        $dispatcher = Application::get()->getDispatcher();
        $settings = $this->plugin->getEffectiveSettings($contextId);
        $isLicensed = !empty($settings['isLicensed']);
        $licenseInfo = $settings['licenseInfo'] ?? [];
        $env = $settings['environment'];
        $client = new ZenodoApiClient($env, $settings['token']);
        $service = new ZenodoDepositService($this->plugin);

        $this->plugin->ensureDatabaseSchema();

        $prodStageId = defined('WORKFLOW_STAGE_ID_PRODUCTION') ? WORKFLOW_STAGE_ID_PRODUCTION : 5;
        $filter = strtolower(trim((string) ($request->getUserVar('filter') ?? 'all')));
        if (!in_array($filter, ['all', 'production', 'published', 'error'], true)) {
            $filter = 'all';
        }

        $subIds = !$isLicensed ? [] : DB::table('submissions')
            ->where('context_id', $contextId)
            ->where(static function ($q) use ($prodStageId): void {
                $q->where('stage_id', $prodStageId)
                    ->orWhereIn('status', [Submission::STATUS_PUBLISHED, Submission::STATUS_SCHEDULED]);
            })
            ->orderByDesc('submission_id')
            ->limit(150)
            ->pluck('submission_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $depositsBySubId = [];
        if (!empty($subIds)) {
            $depRows = DB::table(KisiZenodoMigration::TABLE_NAME)
                ->where('context_id', $contextId)
                ->where('environment', $env)
                ->whereIn('submission_id', $subIds)
                ->get();
            foreach ($depRows as $depRow) {
                $depositsBySubId[(int) $depRow->submission_id] = $depRow;
            }
        }

        $items = [];
        $counts = [
            'all' => 0,
            'production' => 0,
            'published' => 0,
            'error' => 0,
        ];

        foreach ($subIds as $subId) {
            try {
                $submission = Repo::submission()->get($subId);
            } catch (\Throwable $e) {
                $submission = null;
            }
            if (!$submission) {
                continue;
            }

            $publication = $submission->getCurrentPublication();
            if (!$publication) {
                continue;
            }

            $subStatus = (int) $submission->getData('status');
            $pubStatus = (int) $publication->getData('status');
            $isPublished = ($subStatus === Submission::STATUS_PUBLISHED || $pubStatus === Submission::STATUS_PUBLISHED);
            $isScheduled = !$isPublished && ($subStatus === Submission::STATUS_SCHEDULED || $pubStatus === Submission::STATUS_SCHEDULED);
            $stageId = (int) $submission->getData('stageId');

            $dep = $depositsBySubId[$subId] ?? null;
            $depStatus = $dep ? (string) $dep->status : 'none';
            $hasError = $dep && !empty($dep->last_error);

            $counts['all']++;
            if ($isPublished) {
                $counts['published']++;
            } elseif ($stageId === $prodStageId || $isScheduled) {
                $counts['production']++;
            }
            if ($hasError || $depStatus === 'error') {
                $counts['error']++;
            }

            if ($filter === 'production' && ($isPublished || ($stageId !== $prodStageId && !$isScheduled))) {
                continue;
            }
            if ($filter === 'published' && !$isPublished) {
                continue;
            }
            if ($filter === 'error' && !$hasError && $depStatus !== 'error') {
                continue;
            }

            $title = trim(strip_tags((string) $publication->getLocalizedFullTitle())) ?: ('Submission #' . $subId);
            $authorsStr = method_exists($publication, 'getShortAuthorString')
                ? (string) $publication->getShortAuthorString()
                : '';

            $doiId = (int) ($publication->getData('doiId') ?? 0);
            $currentDoi = '';
            $ojsDoiStatus = 0;

            if ($doiId > 0) {
                $doiRow = DB::table('dois')->where('doi_id', $doiId)->first();
                if ($doiRow) {
                    $currentDoi = trim((string) ($doiRow->doi ?? ''));
                    $ojsDoiStatus = (int) ($doiRow->status ?? 0);
                }
            }
            if ($currentDoi === '' && method_exists($publication, 'getDoi')) {
                $currentDoi = trim((string) $publication->getDoi());
            }

            $ojsDoiStatusLabel = match ($ojsDoiStatus) {
                Doi::STATUS_REGISTERED => 'Registered (Terdaftar)',
                Doi::STATUS_UNREGISTERED => 'Unregistered (Draft)',
                Doi::STATUS_SUBMITTED => 'Submitted',
                Doi::STATUS_STALE => 'Needs Sync (Stale)',
                Doi::STATUS_ERROR => 'Error',
                default => ($currentDoi !== '' ? 'Tersimpan' : '-'),
            };

            $docxFile = $service->findProductionReadyFile($subId);
            $pdfFile = $service->findPdfGalleyFile($publication, $subId);

            $docxLabel = $docxFile ? (trim((string) $docxFile->getLocalizedData('name')) ?: basename((string) $docxFile->getData('path'))) : '';
            $pdfLabel = $pdfFile ? (trim((string) $pdfFile->getLocalizedData('name')) ?: basename((string) $pdfFile->getData('path'))) : '';

            $recordId = $dep ? trim((string) ($dep->zenodo_record_id ?? '')) : '';
            $zenodoUrl = '';
            if ($recordId !== '') {
                $zenodoUrl = $client->getRecordHtmlUrl($recordId, $depStatus !== 'published');
            }

            $items[] = [
                'id' => $subId,
                'title' => $title,
                'authors' => $authorsStr,
                'isPublished' => $isPublished,
                'isScheduled' => $isScheduled,
                'docxReady' => ($docxFile !== null),
                'docxName' => $docxLabel,
                'pdfReady' => ($pdfFile !== null),
                'pdfName' => $pdfLabel,
                'ojsDoi' => $currentDoi,
                'ojsDoiStatusLabel' => $ojsDoiStatusLabel,
                'depositStatus' => $depStatus,
                'reservedDoi' => $dep ? (string) ($dep->reserved_doi ?? '') : '',
                'recordId' => $recordId,
                'zenodoUrl' => $zenodoUrl,
                'uploadedFilename' => $dep ? (string) ($dep->uploaded_filename ?? '') : '',
                'syncedAt' => $dep ? (string) ($dep->synced_at ?? '') : '',
                'lastError' => $dep ? (string) ($dep->last_error ?? '') : '',
                'workflowUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'workflow', 'access', [$subId]),
                'articleUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'article', 'view', [$submission->getBestId()]),
            ];
        }

        $ojsDoisEnabled = (bool) $context->getData(Context::SETTING_ENABLE_DOIS)
            && !empty($context->getData(Context::SETTING_ENABLED_DOI_TYPES));

        $userRoles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        $canManageDois = (bool) array_intersect([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER], $userRoles);
        $integrity = (new ZenodoIntegrityChecker())->verifyAll();

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addStyleSheet(
            'kisiZenodoManager',
            $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/styles/manager.css',
            ['contexts' => 'backend']
        );

        $templateMgr->assign([
            'pageTitle' => 'plugins.generic.kisiZenodo.managerAction',
            'pageWidth' => TemplateManager::PAGE_WIDTH_WIDE,
            'journalName' => (string) $context->getLocalizedName(),
            'isLicensed' => $isLicensed,
            'licenseInfo' => $licenseInfo,
            'environment' => $env,
            'webBaseUrl' => $client->getWebBaseUrl(),
            'communitySlug' => $settings['communitySlug'],
            'hasToken' => ($settings['token'] !== ''),
            'ojsDoisEnabled' => $ojsDoisEnabled,
            'integrity' => $integrity,
            'autoPreReserve' => $settings['autoPreReserve'],
            'autoPublish' => $settings['autoPublish'],
            'replaceDocxOnPublish' => $settings['replaceDocxOnPublish'],
            'flash' => $this->pullFlash($request),
            'activeFilter' => $filter,
            'counts' => $counts,
            'items' => $items,
            'urls' => [
                'index' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'kisiZenodo', 'index'),
                'testConnection' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'kisiZenodo', 'testConnection'),
                'preReserve' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'kisiZenodo', 'preReserve'),
                'publishDoi' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'kisiZenodo', 'publishDoi'),
                'nativeDois' => ($ojsDoisEnabled && $canManageDois)
                    ? $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'dois')
                    : '',
                'doiDistributionSettings' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'management', 'settings', ['distribution']) . '#dois',
                'pluginsSettings' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'management', 'settings', ['website']),
            ],
        ]);

        $templateMgr->display($this->plugin->getTemplateResource('manager.tpl'));
    }

    /**
     * POST `/kisiZenodo/testConnection` — Validate active Personal Access Token and Community slug.
     */
    public function testConnection($args, $request): void
    {
        $context = $request->getContext();
        if (!$context || !$request->isPost()) {
            $this->redirectToIndex($request);
            return;
        }

        $request->checkCSRF();
        $settings = $this->plugin->getEffectiveSettings((int) $context->getId());
        if (empty($settings['isLicensed'])) {
            $this->setFlash($request, 'error', 'Plugin belum diaktifkan: masukkan Activation Token resmi terlebih dahulu.');
            $this->redirectToIndex($request);
            return;
        }

        try {
            $client = new ZenodoApiClient($settings['environment'], $settings['token']);
            $res = $client->testConnection($settings['communitySlug']);
            $envLabel = strtoupper($res['environment']);
            $commMsg = '';
            if (!empty($res['community']['id'])) {
                $commMsg = " | Community '{$res['community']['slug']}' terverifikasi (UUID: {$res['community']['id']}).";
            }
            $this->setFlash($request, 'success', "Koneksi Zenodo {$envLabel} berhasil! Token valid{$commMsg}");
        } catch (\Throwable $e) {
            $this->setFlash($request, 'error', 'Uji koneksi Zenodo gagal: ' . $e->getMessage());
        }

        $this->redirectToIndex($request);
    }

    /**
     * POST `/kisiZenodo/preReserve` — Manually run or retry Stage 1 (Draft + DOCX upload + Pre-Reserve DOI).
     */
    public function preReserve($args, $request): void
    {
        $context = $request->getContext();
        if (!$context || !$request->isPost()) {
            $this->redirectToIndex($request);
            return;
        }

        $request->checkCSRF();
        $submissionId = (int) $request->getUserVar('submissionId');

        try {
            $submission = ($submissionId > 0) ? Repo::submission()->get($submissionId) : null;
            if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
                throw new \RuntimeException("Submission #{$submissionId} tidak ditemukan pada jurnal ini.");
            }

            $service = new ZenodoDepositService($this->plugin);
            $result = $service->preReserveForProduction($submission, $context, true);
            $this->setFlash(
                $request,
                'success',
                "Pre-Reserve DOI berhasil untuk Submission #{$submissionId}: DOI {$result['doi']} (Zenodo Draft #{$result['recordId']}) telah disinkronkan ke OJS."
            );
        } catch (\Throwable $e) {
            $this->setFlash(
                $request,
                'error',
                "Gagal Pre-Reserve DOI pada Submission #{$submissionId}: " . $e->getMessage()
            );
        }

        $this->redirectToIndex($request);
    }

    /**
     * POST `/kisiZenodo/publishDoi` — Run Stage 2 (Sync final metadata, swap DOCX -> PDF Galley, & publish to Community).
     */
    public function publishDoi($args, $request): void
    {
        $context = $request->getContext();
        if (!$context || !$request->isPost()) {
            $this->redirectToIndex($request);
            return;
        }

        $request->checkCSRF();
        $submissionId = (int) $request->getUserVar('submissionId');

        try {
            $submission = ($submissionId > 0) ? Repo::submission()->get($submissionId) : null;
            if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
                throw new \RuntimeException("Submission #{$submissionId} tidak ditemukan pada jurnal ini.");
            }

            $publication = $submission->getCurrentPublication();
            if (!$publication) {
                throw new \RuntimeException("Submission #{$submissionId} tidak memiliki Current Publication.");
            }

            $service = new ZenodoDepositService($this->plugin);
            $result = $service->finalizeAndPublish($submission, $publication, $context);
            $this->setFlash(
                $request,
                'success',
                "Publikasi Zenodo berhasil untuk Submission #{$submissionId}: DOI {$result['doi']} kini aktif permanen (Record #{$result['recordId']}) & berstatus Registered di OJS."
            );
        } catch (\Throwable $e) {
            $this->setFlash(
                $request,
                'error',
                "Gagal mempublikasikan Submission #{$submissionId} ke Zenodo: " . $e->getMessage()
            );
        }

        $this->redirectToIndex($request);
    }

    private function redirectToIndex($request): void
    {
        $context = $request->getContext();
        $contextPath = $context ? $context->getPath() : null;
        $dispatcher = Application::get()->getDispatcher();
        $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'kisiZenodo', 'index');
        $request->redirectUrl($url);
    }

    private function setFlash($request, string $type, string $message): void
    {
        $payload = ['type' => $type, 'message' => $message];
        $session = $request->getSession();
        if (!$session) {
            return;
        }
        if (method_exists($session, 'put')) {
            $session->put('kisi_zenodo_flash', $payload);
        } elseif (method_exists($session, 'setSessionVar')) {
            $session->setSessionVar('kisi_zenodo_flash', $payload);
        }
    }

    private function pullFlash($request): ?array
    {
        $session = $request->getSession();
        if (!$session) {
            return null;
        }
        if (method_exists($session, 'pull')) {
            $val = $session->pull('kisi_zenodo_flash');
            return is_array($val) ? $val : null;
        }
        if (method_exists($session, 'getSessionVar')) {
            $val = $session->getSessionVar('kisi_zenodo_flash');
            if (method_exists($session, 'unsetSessionVar')) {
                $session->unsetSessionVar('kisi_zenodo_flash');
            }
            return is_array($val) ? $val : null;
        }
        return null;
    }
}
