<?php

namespace APP\plugins\generic\kisiZenodo\classes\form;

use APP\core\Application;
use APP\plugins\generic\kisiZenodo\classes\ZenodoApiClient;
use APP\plugins\generic\kisiZenodo\classes\ZenodoLicenseGuard;
use APP\plugins\generic\kisiZenodo\KisiZenodoPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class KisiZenodoSettingsForm extends Form
{
    private KisiZenodoPlugin $plugin;
    private int $contextId;

    public function __construct(KisiZenodoPlugin $plugin, int $contextId)
    {
        $this->plugin = $plugin;
        $this->contextId = $contextId;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'activationToken',
            FormValidatorCustom::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.kisiZenodo.settings.licenseInvalid',
            static function ($value): bool {
                $clean = ZenodoLicenseGuard::sanitizeToken((string) $value);
                if ($clean === '') {
                    return true;
                }
                $check = ZenodoLicenseGuard::verify($clean);
                return (bool) $check['valid'];
            }
        ));
    }

    public function initData(): void
    {
        $eff = $this->plugin->getEffectiveSettings($this->contextId);

        $this->_data = [
            'activationToken' => '',
            'hasActivationToken' => ($eff['activationToken'] !== ''),
            'isLicensed' => $eff['isLicensed'],
            'licenseInfo' => $eff['licenseInfo'],
            'activationTokenHint' => $this->maskTokenSuffix($eff['activationToken']),
            'environment' => $eff['environment'],
            'sandboxToken' => '',
            'hasSandboxToken' => ($eff['sandboxToken'] !== ''),
            'sandboxTokenHint' => $this->maskTokenSuffix($eff['sandboxToken']),
            'sandboxCommunity' => $eff['sandboxCommunity'],
            'productionToken' => '',
            'hasProductionToken' => ($eff['productionToken'] !== ''),
            'productionTokenHint' => $this->maskTokenSuffix($eff['productionToken']),
            'productionCommunity' => $eff['productionCommunity'],
            'defaultIssn' => $eff['defaultIssn'],
            'defaultLicense' => $eff['defaultLicense'],
            'autoPreReserve' => $eff['autoPreReserve'],
            'autoPublish' => $eff['autoPublish'],
            'replaceDocxOnPublish' => $eff['replaceDocxOnPublish'],
        ];
    }

    public function readInputData(): void
    {
        $this->readUserVars([
            'activationToken',
            'clearActivationToken',
            'environment',
            'sandboxToken',
            'clearSandboxToken',
            'sandboxCommunity',
            'productionToken',
            'clearProductionToken',
            'productionCommunity',
            'defaultIssn',
            'defaultLicense',
            'autoPreReserve',
            'autoPublish',
            'replaceDocxOnPublish',
        ]);
    }

    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = TemplateManager::getManager($request);
        $context = $request->getContext();
        $dispatcher = Application::get()->getDispatcher();

        $managerUrl = '';
        if ($context && $dispatcher) {
            $managerUrl = $dispatcher->url(
                $request,
                PKPApplication::ROUTE_PAGE,
                $context->getPath(),
                'kisiZenodo',
                'index'
            );
        }

        $eff = $this->plugin->getEffectiveSettings($this->contextId);

        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'managerUrl' => $managerUrl,
            'hasActivationToken' => ($eff['activationToken'] !== ''),
            'isLicensed' => $eff['isLicensed'],
            'licenseInfo' => $eff['licenseInfo'],
            'currentHost' => ZenodoLicenseGuard::resolveCurrentHost($request),
            'activationTokenHint' => $this->maskTokenSuffix($eff['activationToken']),
            'environment' => $this->getData('environment') ?: $eff['environment'],
            'sandboxCommunity' => $this->getData('sandboxCommunity') ?? $eff['sandboxCommunity'],
            'productionCommunity' => $this->getData('productionCommunity') ?? $eff['productionCommunity'],
            'defaultIssn' => $this->getData('defaultIssn') ?? $eff['defaultIssn'],
            'defaultLicense' => $this->getData('defaultLicense') ?? $eff['defaultLicense'],
            'autoPreReserve' => (bool) ($this->getData('autoPreReserve') ?? false),
            'autoPublish' => (bool) ($this->getData('autoPublish') ?? false),
            'replaceDocxOnPublish' => (bool) ($this->getData('replaceDocxOnPublish') ?? false),
            'hasSandboxToken' => ($eff['sandboxToken'] !== ''),
            'sandboxTokenHint' => $this->maskTokenSuffix($eff['sandboxToken']),
            'hasProductionToken' => ($eff['productionToken'] !== ''),
            'productionTokenHint' => $this->maskTokenSuffix($eff['productionToken']),
            'licenseOptions' => [
                'cc-by-sa-4.0' => 'Creative Commons Attribution Share Alike 4.0 (cc-by-sa-4.0)',
                'cc-by-4.0' => 'Creative Commons Attribution 4.0 (cc-by-4.0)',
                'cc-by-nc-4.0' => 'Creative Commons Attribution Non Commercial 4.0 (cc-by-nc-4.0)',
                'cc-by-nc-sa-4.0' => 'Creative Commons Attribution Non Commercial Share Alike 4.0 (cc-by-nc-sa-4.0)',
                'cc-by-nd-4.0' => 'Creative Commons Attribution No Derivatives 4.0 (cc-by-nd-4.0)',
                'cc-by-nc-nd-4.0' => 'Creative Commons Attribution Non Commercial No Derivatives 4.0 (cc-by-nc-nd-4.0)',
                'cc0-1.0' => 'CC0 1.0 Universal Public Domain Dedication (cc0-1.0)',
            ],
        ]);

        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $newActivationToken = ZenodoLicenseGuard::sanitizeToken((string) $this->getData('activationToken'));
        if (!empty($this->getData('clearActivationToken'))) {
            $this->plugin->updateSetting($this->contextId, 'activationToken', '', 'string');
        } elseif ($newActivationToken !== '') {
            $this->plugin->updateSetting($this->contextId, 'activationToken', $newActivationToken, 'string');
        }

        $env = ($this->getData('environment') === ZenodoApiClient::ENV_PRODUCTION)
            ? ZenodoApiClient::ENV_PRODUCTION
            : ZenodoApiClient::ENV_SANDBOX;

        $this->plugin->updateSetting($this->contextId, 'environment', $env, 'string');

        $newSandboxToken = ZenodoApiClient::sanitizeToken((string) $this->getData('sandboxToken'));
        if (!empty($this->getData('clearSandboxToken'))) {
            $this->plugin->updateSetting($this->contextId, 'sandboxToken', '', 'string');
        } elseif ($newSandboxToken !== '') {
            $this->plugin->updateSetting($this->contextId, 'sandboxToken', $newSandboxToken, 'string');
        }

        $newProdToken = ZenodoApiClient::sanitizeToken((string) $this->getData('productionToken'));
        if (!empty($this->getData('clearProductionToken'))) {
            $this->plugin->updateSetting($this->contextId, 'productionToken', '', 'string');
        } elseif ($newProdToken !== '') {
            $this->plugin->updateSetting($this->contextId, 'productionToken', $newProdToken, 'string');
        }

        $sandboxComm = $this->normalizeCommunitySlug((string) $this->getData('sandboxCommunity'));
        $prodComm = $this->normalizeCommunitySlug((string) $this->getData('productionCommunity'));

        $this->plugin->updateSetting($this->contextId, 'sandboxCommunity', $sandboxComm, 'string');
        $this->plugin->updateSetting($this->contextId, 'productionCommunity', $prodComm, 'string');

        $issn = trim((string) $this->getData('defaultIssn')) ?: '3062-9063';
        $license = strtolower(trim((string) $this->getData('defaultLicense'))) ?: 'cc-by-sa-4.0';

        $this->plugin->updateSetting($this->contextId, 'defaultIssn', $issn, 'string');
        $this->plugin->updateSetting($this->contextId, 'defaultLicense', $license, 'string');

        $this->plugin->updateSetting($this->contextId, 'autoPreReserve', (bool) $this->getData('autoPreReserve'), 'bool');
        $this->plugin->updateSetting($this->contextId, 'autoPublish', (bool) $this->getData('autoPublish'), 'bool');
        $this->plugin->updateSetting($this->contextId, 'replaceDocxOnPublish', (bool) $this->getData('replaceDocxOnPublish'), 'bool');

        return parent::execute(...$functionArgs);
    }

    private function normalizeCommunitySlug(string $raw): string
    {
        $val = trim($raw);
        if ($val === '') {
            return '';
        }
        if (preg_match('~communities/([^/?#]+)~i', $val, $m)) {
            return trim($m[1]);
        }
        return trim($val, "/ \t\n\r\0\x0B");
    }

    private function maskTokenSuffix(string $token): string
    {
        $clean = trim($token);
        if ($clean === '') {
            return '';
        }
        $tail = strlen($clean) >= 4 ? substr($clean, -4) : '****';
        return '••••' . $tail;
    }
}
