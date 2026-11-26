{**
 * plugins/generic/kisiZenodo/templates/settingsForm.tpl
 *
 * AjaxModal configuration form for Kisi Zenodo DOI Integration Plugin.
 *}
<script>
	$(function() {ldelim}
		$('#kisiZenodoSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<style>
	.kisi-zenodo-cfg { font-size: 13.5px; line-height: 1.5; color: #1e293b; }
	.kisi-zenodo-cfg .kz-banner {
		display: flex; align-items: center; justify-content: space-between; gap: 12px;
		background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px 14px; margin-bottom: 16px;
	}
	.kisi-zenodo-cfg .kz-btn-link {
		display: inline-block; background: #0284c7; color: #fff !important; text-decoration: none;
		font-weight: 600; padding: 7px 13px; border-radius: 5px; white-space: nowrap; font-size: 12.5px;
	}
	.kisi-zenodo-cfg .kz-btn-link:hover { background: #0369a1; }
	.kisi-zenodo-cfg .kz-card {
		border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; margin-bottom: 14px; background: #ffffff;
	}
	.kisi-zenodo-cfg .kz-card h4 {
		margin: 0 0 10px 0; font-size: 14px; font-weight: 700; color: #0f172a; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;
	}
	.kisi-zenodo-cfg .kz-field { margin-bottom: 12px; }
	.kisi-zenodo-cfg .kz-field:last-child { margin-bottom: 0; }
	.kisi-zenodo-cfg label.kz-label { display: block; font-weight: 600; margin-bottom: 4px; color: #334155; }
	.kisi-zenodo-cfg input[type="text"],
	.kisi-zenodo-cfg input[type="password"],
	.kisi-zenodo-cfg select {
		width: 100%; box-sizing: border-box; padding: 7px 10px; border: 1px solid #cbd5e1;
		border-radius: 4px; font-size: 13.5px; background: #fff; color: #0f172a;
	}
	.kisi-zenodo-cfg .kz-hint { font-size: 12px; color: #64748b; margin-top: 3px; }
	.kisi-zenodo-cfg .kz-badge-ok {
		display: inline-block; background: #dcfce7; color: #166534; font-size: 11.5px;
		font-weight: 600; padding: 1px 7px; border-radius: 999px; margin-left: 6px;
	}
	.kisi-zenodo-cfg .kz-badge-empty {
		display: inline-block; background: #f1f5f9; color: #64748b; font-size: 11.5px;
		font-weight: 600; padding: 1px 7px; border-radius: 999px; margin-left: 6px;
	}
	.kisi-zenodo-cfg .kz-badge-lock {
		display: inline-block; background: #fee2e2; color: #991b1b; font-size: 11.5px;
		font-weight: 600; padding: 1px 7px; border-radius: 999px; margin-left: 6px;
	}
	.kisi-zenodo-cfg .kz-card-license {
		border: 1.5px solid #0284c7; background: #f8fafc;
	}
	.kisi-zenodo-cfg .kz-check { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px; }
	.kisi-zenodo-cfg .kz-check input[type="checkbox"] { margin-top: 3px; }
	.kisi-zenodo-cfg .kz-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
	@media (max-width: 640px) { .kisi-zenodo-cfg .kz-grid-2 { grid-template-columns: 1fr; } }
</style>

<form class="pkp_form kisi-zenodo-cfg" id="kisiZenodoSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="kisiZenodoSettingsNotification"}

	<div class="kz-banner">
		<div>{translate key="plugins.generic.kisiZenodo.settings.intro"}</div>
		{if $managerUrl}
			<a class="kz-btn-link" href="{$managerUrl|escape}" target="_blank" rel="noopener">
				{translate key="plugins.generic.kisiZenodo.settings.openManager"} &rarr;
			</a>
		{/if}
	</div>

	<div class="kz-card kz-card-license">
		<h4>&#128273; {translate key="plugins.generic.kisiZenodo.settings.licenseSection"}</h4>
		<div class="kz-field">
			<label class="kz-label" for="kz-activation-token">
				{translate key="plugins.generic.kisiZenodo.settings.activationToken"}
				{if $isLicensed}
					<span class="kz-badge-ok">&#10003; {translate key="plugins.generic.kisiZenodo.settings.licenseActive"} ({$licenseInfo.holder|escape} &middot; {$licenseInfo.domainRule|escape})</span>
				{else}
					<span class="kz-badge-lock">&#128274; {translate key="plugins.generic.kisiZenodo.settings.licenseLocked"}</span>
				{/if}
			</label>
			<input
				type="password"
				id="kz-activation-token"
				name="activationToken"
				value=""
				autocomplete="new-password"
				placeholder="{if $isLicensed}{translate key="plugins.generic.kisiZenodo.settings.tokenPlaceholderKeep"} ({$activationTokenHint|escape}){else}{translate key="plugins.generic.kisiZenodo.settings.activationPlaceholder"}{/if}"
			/>
			<div class="kz-hint">
				{translate key="plugins.generic.kisiZenodo.settings.licenseHelp"}
				{if $currentHost} (Domain server: <strong>{$currentHost|escape}</strong>){/if}
				{if !$isLicensed && $hasActivationToken && $licenseInfo.reason}
					<br /><span style="color:#b91c1c; font-weight:600;">{$licenseInfo.reason|escape}</span>
				{/if}
			</div>
			{if $hasActivationToken}
				<label class="kz-check" style="margin-top:6px; font-size:12px; color:#475569;">
					<input type="checkbox" name="clearActivationToken" value="1" />
					<span>{translate key="plugins.generic.kisiZenodo.settings.clearActivationToken"}</span>
				</label>
			{/if}
		</div>
	</div>

	<div class="kz-card">
		<div class="kz-field">
			<label class="kz-label" for="kz-environment">{translate key="plugins.generic.kisiZenodo.settings.environment"}</label>
			<select id="kz-environment" name="environment">
				<option value="sandbox"{if $environment !== 'production'} selected{/if}>
					{translate key="plugins.generic.kisiZenodo.settings.environment.sandbox"}
				</option>
				<option value="production"{if $environment === 'production'} selected{/if}>
					{translate key="plugins.generic.kisiZenodo.settings.environment.production"}
				</option>
			</select>
			<div class="kz-hint">{translate key="plugins.generic.kisiZenodo.settings.environment.help"}</div>
		</div>
	</div>

	<div class="kz-card">
		<h4>{translate key="plugins.generic.kisiZenodo.settings.sandboxSection"}</h4>
		<div class="kz-field">
			<label class="kz-label" for="kz-sandbox-token">
				{translate key="plugins.generic.kisiZenodo.settings.sandboxToken"}
				{if $hasSandboxToken}
					<span class="kz-badge-ok">&#10003; {translate key="plugins.generic.kisiZenodo.settings.tokenSavedHint"} ({$sandboxTokenHint|escape})</span>
				{else}
					<span class="kz-badge-empty">{translate key="plugins.generic.kisiZenodo.settings.tokenEmptyHint"}</span>
				{/if}
			</label>
			<input
				type="password"
				id="kz-sandbox-token"
				name="sandboxToken"
				value=""
				autocomplete="new-password"
				placeholder="{if $hasSandboxToken}{translate key="plugins.generic.kisiZenodo.settings.tokenPlaceholderKeep"}{else}{translate key="plugins.generic.kisiZenodo.settings.tokenPlaceholderNew"}{/if}"
			/>
			{if $hasSandboxToken}
				<label class="kz-check" style="margin-top:6px; font-size:12px; color:#475569;">
					<input type="checkbox" name="clearSandboxToken" value="1" />
					<span>{translate key="plugins.generic.kisiZenodo.settings.clearToken"}</span>
				</label>
			{/if}
		</div>
		<div class="kz-field">
			<label class="kz-label" for="kz-sandbox-comm">{translate key="plugins.generic.kisiZenodo.settings.sandboxCommunity"}</label>
			<input type="text" id="kz-sandbox-comm" name="sandboxCommunity" value="{$sandboxCommunity|escape}" placeholder="e.g. kisibsmk-sandbox" />
		</div>
	</div>

	<div class="kz-card">
		<h4>{translate key="plugins.generic.kisiZenodo.settings.productionSection"}</h4>
		<div class="kz-field">
			<label class="kz-label" for="kz-prod-token">
				{translate key="plugins.generic.kisiZenodo.settings.productionToken"}
				{if $hasProductionToken}
					<span class="kz-badge-ok">&#10003; {translate key="plugins.generic.kisiZenodo.settings.tokenSavedHint"} ({$productionTokenHint|escape})</span>
				{else}
					<span class="kz-badge-empty">{translate key="plugins.generic.kisiZenodo.settings.tokenEmptyHint"}</span>
				{/if}
			</label>
			<input
				type="password"
				id="kz-prod-token"
				name="productionToken"
				value=""
				autocomplete="new-password"
				placeholder="{if $hasProductionToken}{translate key="plugins.generic.kisiZenodo.settings.tokenPlaceholderKeep"}{else}{translate key="plugins.generic.kisiZenodo.settings.tokenPlaceholderNew"}{/if}"
			/>
			{if $hasProductionToken}
				<label class="kz-check" style="margin-top:6px; font-size:12px; color:#475569;">
					<input type="checkbox" name="clearProductionToken" value="1" />
					<span>{translate key="plugins.generic.kisiZenodo.settings.clearToken"}</span>
				</label>
			{/if}
		</div>
		<div class="kz-field">
			<label class="kz-label" for="kz-prod-comm">{translate key="plugins.generic.kisiZenodo.settings.productionCommunity"}</label>
			<input type="text" id="kz-prod-comm" name="productionCommunity" value="{$productionCommunity|escape}" placeholder="kisibsmk" />
			<div class="kz-hint">https://zenodo.org/communities/{$productionCommunity|default:'kisibsmk'|escape}</div>
		</div>
	</div>

	<div class="kz-card">
		<h4>{translate key="plugins.generic.kisiZenodo.settings.metadataSection"}</h4>
		<div class="kz-grid-2">
			<div class="kz-field">
				<label class="kz-label" for="kz-issn">{translate key="plugins.generic.kisiZenodo.settings.defaultIssn"}</label>
				<input type="text" id="kz-issn" name="defaultIssn" value="{$defaultIssn|escape}" placeholder="3062-9063" />
			</div>
			<div class="kz-field">
				<label class="kz-label" for="kz-license">{translate key="plugins.generic.kisiZenodo.settings.defaultLicense"}</label>
				<select id="kz-license" name="defaultLicense">
					{foreach from=$licenseOptions key=licId item=licLabel}
						<option value="{$licId|escape}"{if $defaultLicense === $licId} selected{/if}>{$licLabel|escape}</option>
					{/foreach}
				</select>
			</div>
		</div>
	</div>

	<div class="kz-card">
		<h4>{translate key="plugins.generic.kisiZenodo.settings.automationSection"}</h4>
		<label class="kz-check">
			<input type="checkbox" name="autoPreReserve" value="1"{if $autoPreReserve} checked{/if} />
			<span>{translate key="plugins.generic.kisiZenodo.settings.autoPreReserve"}</span>
		</label>
		<label class="kz-check">
			<input type="checkbox" name="autoPublish" value="1"{if $autoPublish} checked{/if} />
			<span>{translate key="plugins.generic.kisiZenodo.settings.autoPublish"}</span>
		</label>
		<label class="kz-check">
			<input type="checkbox" name="replaceDocxOnPublish" value="1"{if $replaceDocxOnPublish} checked{/if} />
			<span>{translate key="plugins.generic.kisiZenodo.settings.replaceDocxOnPublish"}</span>
		</label>
	</div>

	{fbvFormButtons submitText="common.save"}
</form>
