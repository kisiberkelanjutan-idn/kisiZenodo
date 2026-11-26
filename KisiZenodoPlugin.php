<?php

/**
 * @file plugins/generic/kisiZenodo/KisiZenodoPlugin.php
 *
 * Standalone OJS 3.5 Generic Plugin automating 2-stage Zenodo DOI reservation (Production DOCX draft)
 * and final PDF Galley deposit into Zenodo Community `kisibsmk` via InvenioRDM v1 REST API.
 */

namespace APP\plugins\generic\kisiZenodo;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\plugins\generic\kisiZenodo\classes\form\KisiZenodoSettingsForm;
use APP\plugins\generic\kisiZenodo\classes\migration\KisiZenodoMigration;
use APP\plugins\generic\kisiZenodo\classes\ZenodoApiClient;
use APP\plugins\generic\kisiZenodo\classes\ZenodoDepositService;
use APP\plugins\generic\kisiZenodo\pages\KisiZenodoHandler;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Database\Migrations\Migration;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\notification\Notification;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class KisiZenodoPlugin extends GenericPlugin
{
    private bool $schemaChecked = false;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }

        if ($this->getEnabled($mainContextId)) {
            $this->ensureDatabaseSchema();
            Hook::add('Decision::add', [$this, 'onDecisionAdded']);
            Hook::add('Publication::publish', [$this, 'onPublicationPublished']);
            Hook::add('LoadHandler', [$this, 'loadPageHandler']);
            Hook::add('TemplateManager::setupBackendPage', [$this, 'setupBackendMenu']);
        }

        return true;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.kisiZenodo.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.kisiZenodo.description');
    }

    public function getInstallMigration(): Migration
    {
        return new KisiZenodoMigration();
    }

    /**
     * Ensure tracking table exists even when plugin files were deployed directly via cPanel Git without CLI upgrade.
     */
    public function ensureDatabaseSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        try {
            (new KisiZenodoMigration())->up();
        } catch (\Throwable $e) {
            error_log('[kisiZenodo] Failed ensuring database schema: ' . $e->getMessage());
        }
    }

    /**
     * Return normalized plugin settings for a given journal context.
     */
    public function getEffectiveSettings(int $contextId): array
    {
        $rawEnv = (string) ($this->getSetting($contextId, 'environment') ?? ZenodoApiClient::ENV_SANDBOX);
        $env = ($rawEnv === ZenodoApiClient::ENV_PRODUCTION)
            ? ZenodoApiClient::ENV_PRODUCTION
            : ZenodoApiClient::ENV_SANDBOX;

        $sandboxToken = trim((string) ($this->getSetting($contextId, 'sandboxToken') ?? ''));
        $productionToken = trim((string) ($this->getSetting($contextId, 'productionToken') ?? ''));

        $rawSandboxComm = $this->getSetting($contextId, 'sandboxCommunity');
        $sandboxCommunity = $rawSandboxComm === null ? '' : trim((string) $rawSandboxComm);

        $rawProdComm = $this->getSetting($contextId, 'productionCommunity');
        $productionCommunity = $rawProdComm === null ? 'kisibsmk' : trim((string) $rawProdComm);

        $rawIssn = $this->getSetting($contextId, 'defaultIssn');
        $defaultIssn = ($rawIssn === null || trim((string) $rawIssn) === '')
            ? '3062-9063'
            : trim((string) $rawIssn);

        $rawLicense = $this->getSetting($contextId, 'defaultLicense');
        $defaultLicense = ($rawLicense === null || trim((string) $rawLicense) === '')
            ? 'cc-by-sa-4.0'
            : trim((string) $rawLicense);

        $rawAutoPre = $this->getSetting($contextId, 'autoPreReserve');
        $autoPreReserve = ($rawAutoPre === null) ? true : (bool) $rawAutoPre;

        $rawAutoPub = $this->getSetting($contextId, 'autoPublish');
        $autoPublish = ($rawAutoPub === null) ? true : (bool) $rawAutoPub;

        $rawReplaceDocx = $this->getSetting($contextId, 'replaceDocxOnPublish');
        $replaceDocxOnPublish = ($rawReplaceDocx === null) ? true : (bool) $rawReplaceDocx;

        return [
            'environment' => $env,
            'token' => ($env === ZenodoApiClient::ENV_PRODUCTION) ? $productionToken : $sandboxToken,
            'communitySlug' => ($env === ZenodoApiClient::ENV_PRODUCTION) ? $productionCommunity : $sandboxCommunity,
            'sandboxToken' => $sandboxToken,
            'productionToken' => $productionToken,
            'sandboxCommunity' => $sandboxCommunity,
            'productionCommunity' => $productionCommunity,
            'defaultIssn' => $defaultIssn,
            'defaultLicense' => $defaultLicense,
            'autoPreReserve' => $autoPreReserve,
            'autoPublish' => $autoPublish,
            'replaceDocxOnPublish' => $replaceDocxOnPublish,
        ];
    }

    /**
     * Hook `Decision::add`: Automatically run Stage 1 (Pre-Reserve DOI + upload Production DOCX)
     * when an Editor sends a manuscript from Copyediting to Production.
     */
    public function onDecisionAdded(string $hookName, array $args)
    {
        $submissionId = 0;
        try {
            $decision = $args[0] ?? null;
            if (!is_object($decision) || !method_exists($decision, 'getData')) {
                return Hook::CONTINUE;
            }

            if ((int) $decision->getData('decision') !== Decision::SEND_TO_PRODUCTION) {
                return Hook::CONTINUE;
            }

            $submissionId = (int) $decision->getData('submissionId');
            if ($submissionId <= 0) {
                return Hook::CONTINUE;
            }

            $submission = Repo::submission()->get($submissionId);
            if (!$submission) {
                return Hook::CONTINUE;
            }

            $contextId = (int) $submission->getData('contextId');
            if (!$this->getEnabled($contextId)) {
                return Hook::CONTINUE;
            }

            $settings = $this->getEffectiveSettings($contextId);
            if (empty($settings['autoPreReserve']) || $settings['token'] === '') {
                return Hook::CONTINUE;
            }

            $context = Application::getContextDAO()->getById($contextId);
            if (!$context) {
                return Hook::CONTINUE;
            }

            $service = new ZenodoDepositService($this);
            $service->preReserveForProduction($submission, $context, false);
        } catch (\Throwable $e) {
            error_log('[kisiZenodo] Auto pre-reserve error on submission #' . $submissionId . ': ' . $e->getMessage());
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook `Publication::publish`: Automatically run Stage 2 (update metadata, swap DOCX -> PDF Galley,
     * and publish into Zenodo Community) when an article is published in OJS.
     */
    public function onPublicationPublished(string $hookName, array $args)
    {
        $submissionId = 0;
        try {
            $newPublication = $args[0] ?? null;
            $submission = $args[2] ?? null;

            if (!is_object($newPublication) || !method_exists($newPublication, 'getData')) {
                return Hook::CONTINUE;
            }

            if ((int) $newPublication->getData('status') !== Submission::STATUS_PUBLISHED) {
                return Hook::CONTINUE;
            }

            if (!is_object($submission)) {
                $subId = (int) $newPublication->getData('submissionId');
                if ($subId > 0) {
                    $submission = Repo::submission()->get($subId);
                }
            }
            if (!is_object($submission)) {
                return Hook::CONTINUE;
            }

            $submissionId = (int) $submission->getId();
            $contextId = (int) $submission->getData('contextId');
            if (!$this->getEnabled($contextId)) {
                return Hook::CONTINUE;
            }

            $settings = $this->getEffectiveSettings($contextId);
            if (empty($settings['autoPublish']) || $settings['token'] === '') {
                return Hook::CONTINUE;
            }

            $context = Application::getContextDAO()->getById($contextId);
            if (!$context) {
                return Hook::CONTINUE;
            }

            $service = new ZenodoDepositService($this);
            $service->finalizeAndPublish($submission, $newPublication, $context);
        } catch (\Throwable $e) {
            error_log('[kisiZenodo] Auto publish error on submission #' . $submissionId . ': ' . $e->getMessage());
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook `LoadHandler`: Route `/index.php/{journal}/kisiZenodo/*` to `KisiZenodoHandler`.
     */
    public function loadPageHandler(string $hookName, array $args)
    {
        $page = (string) ($args[0] ?? '');
        if ($page !== 'kisiZenodo') {
            return Hook::CONTINUE;
        }

        $args[3] = new KisiZenodoHandler($this);
        return Hook::ABORT;
    }

    /**
     * Hook `TemplateManager::setupBackendPage`: Inject "Zenodo DOI" into OJS 3.5's left sidebar navigation.
     */
    public function setupBackendMenu(string $hookName, array $args)
    {
        try {
            $request = Application::get()->getRequest();
            $context = $request ? $request->getContext() : null;
            $user = $request ? $request->getUser() : null;
            if (!$request || !$context || !$user) {
                return Hook::CONTINUE;
            }

            $contextId = (int) $context->getId();
            if (!$this->getEnabled($contextId)) {
                return Hook::CONTINUE;
            }

            $router = $request->getRouter();
            $handler = ($router && method_exists($router, 'getHandler')) ? $router->getHandler() : null;
            $userRoles = $handler
                ? (array) $handler->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES)
                : [];

            if (empty($userRoles) && method_exists($user, 'getRoles')) {
                foreach ((array) $user->getRoles($contextId) as $roleObj) {
                    if (is_object($roleObj) && method_exists($roleObj, 'getId')) {
                        $userRoles[] = (int) $roleObj->getId();
                    }
                }
            }

            $allowedRoles = [
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SUB_EDITOR,
            ];
            if (!array_intersect($allowedRoles, $userRoles)) {
                return Hook::CONTINUE;
            }

            $dispatcher = $request->getDispatcher();
            if (!$dispatcher) {
                return Hook::CONTINUE;
            }

            $templateMgr = TemplateManager::getManager($request);
            $menu = (array) $templateMgr->getState('menu');

            $zenodoItem = [
                'name' => __('plugins.generic.kisiZenodo.sidebarMenu'),
                'url' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'kisiZenodo', 'index'),
                'isCurrent' => $request->getRequestedPage() === 'kisiZenodo',
                'icon' => 'NavDoi',
            ];

            $keys = array_keys($menu);
            $doisIdx = array_search('dois', $keys, true);
            $settingsIdx = array_search('settings', $keys, true);
            $statsIdx = array_search('statistics', $keys, true);

            if ($doisIdx !== false) {
                $menu = array_slice($menu, 0, $doisIdx + 1, true)
                    + ['kisiZenodo' => $zenodoItem]
                    + array_slice($menu, $doisIdx + 1, null, true);
            } elseif ($settingsIdx !== false) {
                $menu = array_slice($menu, 0, $settingsIdx, true)
                    + ['kisiZenodo' => $zenodoItem]
                    + array_slice($menu, $settingsIdx, null, true);
            } elseif ($statsIdx !== false) {
                $menu = array_slice($menu, 0, $statsIdx, true)
                    + ['kisiZenodo' => $zenodoItem]
                    + array_slice($menu, $statsIdx, null, true);
            } else {
                $menu['kisiZenodo'] = $zenodoItem;
            }

            $templateMgr->setState(['menu' => $menu]);
        } catch (\Throwable $e) {
            error_log('[kisiZenodo] Failed injecting backend sidebar menu: ' . $e->getMessage());
        }

        return Hook::CONTINUE;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $dispatcher = $request->getDispatcher();
        $context = $request->getContext();
        $contextPath = $context ? $context->getPath() : null;

        $pluginActions = [
            new LinkAction(
                'settings',
                new AjaxModal(
                    $router->url(
                        $request,
                        null,
                        null,
                        'manage',
                        null,
                        [
                            'verb' => 'settings',
                            'plugin' => $this->getName(),
                            'category' => 'generic',
                        ]
                    ),
                    $this->getDisplayName()
                ),
                __('manager.plugins.settings'),
                null
            ),
        ];

        if ($contextPath && $dispatcher) {
            $pluginActions[] = new LinkAction(
                'zenodoManager',
                new RedirectAction(
                    $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $contextPath, 'kisiZenodo', 'index')
                ),
                __('plugins.generic.kisiZenodo.managerAction'),
                null
            );
        }

        return array_merge($pluginActions, $actions);
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext();
                $contextId = $context ? (int) $context->getId() : PKPApplication::SITE_CONTEXT_ID;
                $form = new KisiZenodoSettingsForm($this, $contextId);

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        if (class_exists(\APP\notification\NotificationManager::class) && $request->getUser()) {
                            $notificationMgr = new \APP\notification\NotificationManager();
                            $notificationMgr->createTrivialNotification(
                                $request->getUser()->getId(),
                                Notification::NOTIFICATION_TYPE_SUCCESS,
                                ['contents' => __('plugins.generic.kisiZenodo.settings.saved')]
                            );
                        }
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }

                return new JSONMessage(true, $form->fetch($request));
        }

        return parent::manage($args, $request);
    }
}
