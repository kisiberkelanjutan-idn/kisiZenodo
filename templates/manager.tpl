{**
 * plugins/generic/kisiZenodo/templates/manager.tpl
 *
 * OJS 3.5 Backend Dashboard for Kisi Zenodo DOI Integration (renders inside native OJS sidebar layout).
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<div v-pre class="kz-manager">
		<div class="kz-header-row">
			<h1 class="app__pageHeading" style="margin:0;">
				{translate key="plugins.generic.kisiZenodo.managerAction"}
			</h1>
			<div class="kz-quick-links">
				{if $ojsDoisEnabled && $urls.nativeDois}
					<a class="kz-quick-link" href="{$urls.nativeDois|escape}">
						DOIs Bawaan OJS &rarr;
					</a>
				{/if}
				<a class="kz-quick-link" href="{$urls.doiDistributionSettings|escape}">
					Pengaturan Distribusi DOI
				</a>
				<a class="kz-quick-link" href="{$urls.pluginsSettings|escape}">
					Pengaturan Plugin Zenodo
				</a>
			</div>
		</div>

		{if $flash}
			<div class="kz-alert {if $flash.type === 'success'}kz-alert-success{else}kz-alert-error{/if}">
				{$flash.message|escape}
			</div>
		{/if}

		{if !$ojsDoisEnabled}
			<div class="kz-alert kz-alert-warn">
				<strong>Modul DOI bawaan OJS belum diaktifkan.</strong>
				Aktifkan <em>Enable DOIs (Articles)</em> pada <a href="{$urls.doiDistributionSettings|escape}"><strong>Settings &rarr; Distribution &rarr; DOIs</strong></a> agar nomor DOI Zenodo otomatis muncul di halaman artikel, sitasi, Google Scholar, dan menu DOIs jurnal.
			</div>
		{/if}

		{if !$hasToken}
			<div class="kz-alert kz-alert-warn">
				<strong>Personal Access Token belum diisi untuk mode {$environment|upper|escape}.</strong>
				Buka <a href="{$urls.pluginsSettings|escape}"><strong>Settings &rarr; Website &rarr; Plugins &rarr; Kisi Zenodo DOI Integration &rarr; Settings</strong></a> untuk menempelkan token API Zenodo Anda.
			</div>
		{/if}

		<section class="kz-status-strip">
			<div class="kz-meta-pills">
				{if $environment === 'production'}
					<span class="kz-pill kz-pill-prod">&#9679; PRODUCTION ({$webBaseUrl|escape})</span>
				{else}
					<span class="kz-pill kz-pill-sandbox">&#9679; SANDBOX ({$webBaseUrl|escape})</span>
				{/if}

				<span class="kz-pill kz-pill-neutral">
					Community:
					{if $communitySlug}
						<a href="{$webBaseUrl|escape}/communities/{$communitySlug|escape}" target="_blank" rel="noopener" style="color:inherit;">
							<strong>{$communitySlug|escape}</strong>
						</a>
					{else}
						<em>(Tanpa Community)</em>
					{/if}
				</span>

				<span class="kz-pill kz-pill-neutral">
					Auto Pre-Reserve (.docx): <strong>{if $autoPreReserve}ON{else}OFF{/if}</strong>
				</span>
				<span class="kz-pill kz-pill-neutral">
					Auto Publish (.pdf): <strong>{if $autoPublish}ON{else}OFF{/if}</strong>
				</span>
			</div>

			<form method="post" action="{$urls.testConnection|escape}" style="margin:0;">
				{csrf}
				<button type="submit" class="kz-btn kz-btn-outline"{if !$hasToken} disabled{/if}>
					&#9889; Test Connection API
				</button>
			</form>
		</section>

		<div class="kz-tabs">
			<a class="kz-tab {if $activeFilter === 'all'}active{/if}" href="{$urls.index|escape}?filter=all">
				Semua ({$counts.all|escape})
			</a>
			<a class="kz-tab {if $activeFilter === 'production'}active{/if}" href="{$urls.index|escape}?filter=production">
				Tahap Production ({$counts.production|escape})
			</a>
			<a class="kz-tab {if $activeFilter === 'published'}active{/if}" href="{$urls.index|escape}?filter=published">
				Published ({$counts.published|escape})
			</a>
			<a class="kz-tab {if $activeFilter === 'error'}active{/if}" href="{$urls.index|escape}?filter=error">
				Perlu Perhatian / Error ({$counts.error|escape})
			</a>
		</div>

		<div class="kz-card">
			{if empty($items)}
				<div class="kz-empty">
					Belum ada naskah pada kategori ini di tahap Production, Scheduled, maupun Published.
				</div>
			{else}
				<div class="kz-table-wrap">
					<table class="kz-table">
						<thead>
							<tr>
								<th style="width:68px;">ID</th>
								<th>Artikel &amp; Tahap OJS</th>
								<th style="width:225px;">Kesiapan File</th>
								<th style="width:245px;">Sinkronisasi DOI OJS &amp; Zenodo</th>
								<th style="width:200px;">Aksi 1-Klik</th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$items item=item}
								<tr>
									<td><strong>#{$item.id|escape}</strong></td>
									<td>
										<div class="kz-art-title">
											<a href="{$item.workflowUrl|escape}">{$item.title|escape}</a>
										</div>
										<div class="kz-art-meta">
											{if $item.authors}{$item.authors|escape} &middot; {/if}
											{if $item.isPublished}
												<span class="kz-badge kz-badge-pubstage">Published</span>
												&middot; <a href="{$item.articleUrl|escape}" target="_blank" rel="noopener">Lihat Artikel &nearr;</a>
											{elseif $item.isScheduled}
												<span class="kz-badge kz-badge-schedstage">Scheduled</span>
												&middot; <a href="{$item.workflowUrl|escape}">Buka Workflow &rarr;</a>
											{else}
												<span class="kz-badge kz-badge-prodstage">Production</span>
												&middot; <a href="{$item.workflowUrl|escape}">Buka Workflow &rarr;</a>
											{/if}
										</div>
									</td>
									<td>
										<div class="kz-file-row">
											{if $item.docxReady}
												<span class="kz-file-ok">&#10003; Prod/DOCX:</span> {$item.docxName|escape}
											{else}
												<span class="kz-file-miss">&mdash; Prod/DOCX belum ada</span>
											{/if}
										</div>
										<div class="kz-file-row">
											{if $item.pdfReady}
												<span class="kz-file-ok">&#10003; Galley PDF:</span> {$item.pdfName|escape}
											{else}
												<span class="kz-file-miss">&mdash; Galley PDF belum ada</span>
											{/if}
										</div>
									</td>
									<td>
										{if $item.ojsDoi}
											<div style="margin-bottom:4px;">
												<span class="kz-doi-code">{$item.ojsDoi|escape}</span>
											</div>
											<div class="kz-ojs-sync">
												Status OJS: <strong>{$item.ojsDoiStatusLabel|escape}</strong>
											</div>
										{else}
											<div style="margin-bottom:6px; color:#94a3b8; font-size:12px;">Belum ada DOI di OJS</div>
										{/if}

										<div>
											{if $item.depositStatus === 'published'}
												<span class="kz-badge kz-badge-pubstage">&#10003; Zenodo Published</span>
											{elseif $item.depositStatus === 'draft_reserved'}
												<span class="kz-badge kz-badge-draft">&#9203; Draft Reserved</span>
											{elseif $item.depositStatus === 'error'}
												<span class="kz-badge kz-badge-error">&#9888; Error</span>
											{else}
												<span class="kz-badge kz-badge-none">Belum Deposit</span>
											{/if}

											{if $item.zenodoUrl}
												&middot; <a href="{$item.zenodoUrl|escape}" target="_blank" rel="noopener" style="font-size:12px;">
													Zenodo #{$item.recordId|escape} &nearr;
												</a>
											{/if}
										</div>

										{if $item.uploadedFilename}
											<div style="font-size:11.5px; color:#64748b; margin-top:3px;">
												File Zenodo: {$item.uploadedFilename|escape}
											</div>
										{/if}

										{if $item.lastError}
											<div class="kz-err-box">{$item.lastError|escape}</div>
										{/if}
									</td>
									<td>
										<div class="kz-actions">
											{if $item.depositStatus !== 'published'}
												<form method="post" action="{$urls.preReserve|escape}" style="margin:0;">
													{csrf}
													<input type="hidden" name="submissionId" value="{$item.id|escape}" />
													<button
														type="submit"
														class="kz-btn kz-btn-blue"
														{if !$hasToken || !$item.docxReady}disabled{/if}
														title="Buat/perbarui Draft Zenodo dengan file .docx Production dan pesan DOI resmi"
													>
														{if $item.depositStatus === 'draft_reserved'}&#8635; Sync Ulang Draft (.docx){else}1. Pre-Reserve DOI (.docx){/if}
													</button>
												</form>

												<form method="post" action="{$urls.publishDoi|escape}" style="margin:0;" onsubmit="return confirm('Terbitkan artikel #{$item.id|escape} ke Zenodo ({$environment|upper|escape})? File PDF Galley akan dikunci permanen setelah Publish.');">
													{csrf}
													<input type="hidden" name="submissionId" value="{$item.id|escape}" />
													<button
														type="submit"
														class="kz-btn kz-btn-green"
														{if !$hasToken || !$item.pdfReady}disabled{/if}
														title="Ganti file .docx dengan PDF Galley final dan terbitkan ke Zenodo Community"
													>
														2. Upload PDF &amp; Publish DOI
													</button>
												</form>
											{else}
												<a class="kz-btn kz-btn-outline" href="https://doi.org/{$item.reservedDoi|default:$item.ojsDoi|escape}" target="_blank" rel="noopener">
													Buka DOI Publik &nearr;
												</a>
											{/if}
										</div>
									</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</div>
			{/if}
		</div>
	</div>
{/block}
