{**
 * plugins/generic/kisiZenodo/templates/manager.tpl
 *
 * Standalone OJS 3.5 Editorial Dashboard for Kisi Zenodo DOI Integration.
 *}
<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Zenodo DOI Manager — {$journalName|escape}</title>
	<style>
		:root {
			--kz-bg: #f8fafc;
			--kz-surface: #ffffff;
			--kz-border: #e2e8f0;
			--kz-text: #0f172a;
			--kz-muted: #64748b;
			--kz-primary: #0284c7;
			--kz-primary-hover: #0369a1;
			--kz-emerald: #059669;
			--kz-emerald-hover: #047857;
			--kz-amber-bg: #fef3c7;
			--kz-amber-text: #92400e;
			--kz-green-bg: #dcfce7;
			--kz-green-text: #166534;
			--kz-red-bg: #fee2e2;
			--kz-red-text: #991b1b;
			--kz-blue-bg: #e0f2fe;
			--kz-blue-text: #075985;
		}
		* { box-sizing: border-box; }
		body {
			margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", sans-serif;
			background: var(--kz-bg); color: var(--kz-text); font-size: 14px; line-height: 1.5;
		}
		.kz-topbar {
			background: #0f172a; color: #fff; padding: 14px 24px;
			display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
		}
		.kz-topbar-title { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
		.kz-topbar h1 { margin: 0; font-size: 18px; font-weight: 700; letter-spacing: -0.01em; }
		.kz-topbar-sub { color: #94a3b8; font-size: 13px; }
		.kz-nav-links { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
		.kz-nav-links a {
			color: #e2e8f0; text-decoration: none; font-size: 13px; padding: 6px 12px;
			border: 1px solid #334155; border-radius: 6px; transition: background 0.15s;
		}
		.kz-nav-links a:hover { background: #1e293b; color: #fff; }
		.kz-wrap { max-width: 1280px; margin: 22px auto; padding: 0 20px 48px; }
		.kz-status-strip {
			background: var(--kz-surface); border: 1px solid var(--kz-border); border-radius: 10px;
			padding: 16px 20px; display: flex; align-items: center; justify-content: space-between;
			flex-wrap: wrap; gap: 16px; margin-bottom: 18px; box-shadow: 0 1px 2px rgba(15,23,42,0.03);
		}
		.kz-meta-pills { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
		.kz-pill {
			display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600;
			padding: 5px 11px; border-radius: 999px; border: 1px solid transparent;
		}
		.kz-pill-sandbox { background: var(--kz-amber-bg); color: var(--kz-amber-text); border-color: #fde68a; }
		.kz-pill-prod { background: var(--kz-green-bg); color: var(--kz-green-text); border-color: #bbf7d0; }
		.kz-pill-neutral { background: #f1f5f9; color: #334155; border-color: #e2e8f0; }
		.kz-pill-err { background: var(--kz-red-bg); color: var(--kz-red-text); border-color: #fecaca; }
		.kz-alert {
			padding: 13px 16px; border-radius: 8px; margin-bottom: 18px; font-weight: 500; font-size: 13.5px;
		}
		.kz-alert-success { background: var(--kz-green-bg); color: var(--kz-green-text); border: 1px solid #86efac; }
		.kz-alert-error { background: var(--kz-red-bg); color: var(--kz-red-text); border: 1px solid #fca5a5; }
		.kz-alert-warn { background: var(--kz-amber-bg); color: var(--kz-amber-text); border: 1px solid #fcd34d; }
		.kz-tabs { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
		.kz-tab {
			text-decoration: none; color: #475569; background: #fff; border: 1px solid var(--kz-border);
			padding: 7px 14px; border-radius: 7px; font-size: 13px; font-weight: 600;
		}
		.kz-tab:hover { background: #f8fafc; color: #0f172a; }
		.kz-tab.active { background: #0f172a; color: #fff; border-color: #0f172a; }
		.kz-card {
			background: var(--kz-surface); border: 1px solid var(--kz-border); border-radius: 10px;
			overflow: hidden; box-shadow: 0 1px 2px rgba(15,23,42,0.03);
		}
		.kz-table-wrap { overflow-x: auto; }
		table.kz-table { width: 100%; border-collapse: collapse; text-align: left; }
		table.kz-table th {
			background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700;
			text-transform: uppercase; letter-spacing: 0.03em; padding: 12px 16px; border-bottom: 1px solid var(--kz-border);
		}
		table.kz-table td {
			padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: 13.5px;
		}
		table.kz-table tr:last-child td { border-bottom: none; }
		.kz-art-title { font-weight: 600; color: #0f172a; margin-bottom: 3px; line-height: 1.4; }
		.kz-art-title a { color: inherit; text-decoration: none; }
		.kz-art-title a:hover { color: var(--kz-primary); text-decoration: underline; }
		.kz-art-meta { font-size: 12px; color: var(--kz-muted); }
		.kz-badge {
			display: inline-block; font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 5px;
		}
		.kz-badge-prodstage { background: var(--kz-blue-bg); color: var(--kz-blue-text); }
		.kz-badge-pubstage { background: var(--kz-green-bg); color: var(--kz-green-text); }
		.kz-badge-draft { background: var(--kz-amber-bg); color: var(--kz-amber-text); }
		.kz-badge-none { background: #f1f5f9; color: #64748b; }
		.kz-badge-error { background: var(--kz-red-bg); color: var(--kz-red-text); }
		.kz-file-row { font-size: 12px; margin-bottom: 4px; color: #334155; }
		.kz-file-ok { color: #15803d; font-weight: 600; }
		.kz-file-miss { color: #94a3b8; }
		.kz-doi-code {
			font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
			font-size: 12.5px; background: #f1f5f9; padding: 3px 7px; border-radius: 4px; display: inline-block;
		}
		.kz-err-box {
			margin-top: 6px; padding: 7px 9px; background: #fef2f2; border: 1px solid #fecaca;
			border-radius: 5px; color: #991b1b; font-size: 11.5px; max-width: 340px; word-break: break-word;
		}
		.kz-actions { display: flex; flex-direction: column; gap: 7px; min-width: 185px; }
		.kz-btn {
			display: inline-flex; align-items: center; justify-content: center; gap: 6px;
			width: 100%; border: none; border-radius: 6px; padding: 8px 12px;
			font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.15s;
		}
		.kz-btn-blue { background: var(--kz-primary); color: #fff; }
		.kz-btn-blue:hover { background: var(--kz-primary-hover); }
		.kz-btn-green { background: var(--kz-emerald); color: #fff; }
		.kz-btn-green:hover { background: var(--kz-emerald-hover); }
		.kz-btn-outline {
			background: #fff; color: #334155; border: 1px solid #cbd5e1; width: auto;
		}
		.kz-btn-outline:hover { background: #f8fafc; border-color: #94a3b8; }
		.kz-btn[disabled] { opacity: 0.45; cursor: not-allowed; }
		.kz-empty { padding: 40px 20px; text-align: center; color: var(--kz-muted); }
	</style>
</head>
<body>
	<header class="kz-topbar">
		<div class="kz-topbar-title">
			<h1>Zenodo DOI Manager</h1>
			<span class="kz-topbar-sub">{$journalName|escape}</span>
		</div>
		<nav class="kz-nav-links">
			<a href="{$urls.submissions|escape}">&larr; Submissions OJS</a>
			<a href="{$urls.pluginsSettings|escape}">Website &amp; Plugin Settings</a>
		</nav>
	</header>

	<main class="kz-wrap">
		{if $flash}
			<div class="kz-alert {if $flash.type === 'success'}kz-alert-success{else}kz-alert-error{/if}">
				{$flash.message|escape}
			</div>
		{/if}

		{if !$hasToken}
			<div class="kz-alert kz-alert-warn">
				<strong>Personal Access Token belum diisi untuk mode {$environment|upper|escape}.</strong>
				Buka <em>Website Settings &rarr; Plugins &rarr; Kisi Zenodo DOI Integration &rarr; Settings</em> untuk menempelkan token API Zenodo Anda.
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
					Belum ada naskah pada kategori ini di tahap Production maupun Published.
				</div>
			{else}
				<div class="kz-table-wrap">
					<table class="kz-table">
						<thead>
							<tr>
								<th style="width:72px;">ID</th>
								<th>Artikel &amp; Tahap OJS</th>
								<th style="width:230px;">Kesiapan File</th>
								<th style="width:230px;">DOI OJS &amp; Status Zenodo</th>
								<th style="width:205px;">Aksi 1-Klik</th>
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
											<div style="margin-bottom:6px;">
												<span class="kz-doi-code">{$item.ojsDoi|escape}</span>
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
	</main>
</body>
</html>
