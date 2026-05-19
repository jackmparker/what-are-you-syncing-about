/**
 * WP Sync DB Simple Admin JavaScript
 */

(function() {
	'use strict';

	const wpsdb = window.syncSimple || {};
	const ajaxUrl = wpsdb.ajaxUrl || '/wp-admin/admin-ajax.php';
	const nonce = wpsdb.nonce || '';
	const connectionKey = wpsdb.connectionKey || '';
	const siteInfo = wpsdb.siteInfo || {};
	const i18n = wpsdb.i18n || {};

	// How many tables to process simultaneously (saved in Advanced Settings)
	const CONCURRENCY = Math.max( 1, Math.min( 10, parseInt( wpsdb.concurrency || '3', 10 ) ) );
	// Tables with fewer than this many bytes are batched together
	const SMALL_TABLE_THRESHOLD = 4096;
	// Max tables per batch request
	const SMALL_TABLE_BATCH_SIZE = 20;

	let remoteUrl = '';
	let remoteKey = '';
	let connectionVerified = false;
	let migrationInProgress = false;

	// DOM Elements
	const connectionKeyInput = document.getElementById('connection-key');
	const copyKeyBtn = document.getElementById('copy-key-btn');
	const remoteUrlInput = document.getElementById('remote-url');
	const remoteKeyInput = document.getElementById('remote-key');
	const verifyConnectionBtn = document.getElementById('verify-connection-btn');
	const connectionStatus = document.getElementById('connection-status');
	const pullDatabaseBtn = document.getElementById('pull-database-btn');
	const pushDatabaseBtn = document.getElementById('push-database-btn');
	const progressSection = document.getElementById('progress-section');
	const progressBarFill = document.getElementById('progress-bar-fill');
	const progressText = document.getElementById('progress-text');
	const warningSection = document.getElementById('warning-section');
	const warningMessage = document.getElementById('warning-message');
	const errorSection = document.getElementById('error-section');
	const errorMessage = document.getElementById('error-message');
	const successSection = document.getElementById('success-section');
	const successMessage = document.getElementById('success-message');
	const logOutput = document.getElementById('sync-log-output');
	const clearLogBtn = document.getElementById('clear-log-btn');
	const saveAdvancedBtn = document.getElementById('save-advanced-settings-btn');
	const settingsSaveStatus = document.getElementById('settings-save-status');
	const basicAuthUserInput = document.getElementById('basic-auth-user');
	const basicAuthPassInput = document.getElementById('basic-auth-pass');
	const skipSslInput = document.getElementById('skip-ssl');
	const concurrencyInput = document.getElementById('concurrency');

	// ── Logging ──────────────────────────────────────────────────────────────

	function log(message, level) {
		level = level || 'info';
		if (!logOutput) {
			console.log('[SYNC ' + level + ']', message);
			return;
		}
		const time = new Date().toLocaleTimeString();
		const line = document.createElement('span');
		line.className = 'log-' + level;
		line.textContent = '[' + time + '] [' + level.toUpperCase() + '] ' + message + '\n';
		logOutput.appendChild(line);
		logOutput.scrollTop = logOutput.scrollHeight;
	}

	function logObject(label, obj, level) {
		try {
			log(label + ': ' + JSON.stringify(sanitizeForLog(obj), null, 2), level);
		} catch (e) {
			log(label + ': [unable to serialize]', level);
		}
	}

	function sanitizeForLog(data) {
		if (data === null || data === undefined) {
			return data;
		}
		if (typeof data !== 'object') {
			return data;
		}
		if (Array.isArray(data)) {
			return data.map(sanitizeForLog);
		}
		const out = {};
		for (const key of Object.keys(data)) {
			const lower = key.toLowerCase();
			if (lower === 'key' || lower === 'sig' || lower === 'nonce') {
				out[key] = '[redacted]';
			} else if (
				(lower === 'sql' || lower === 'sql_b64' || lower === 'sql_gz_b64' || lower === 'data') &&
				typeof data[key] === 'string'
			) {
				out[key] = '[' + key + ' ' + data[key].length + ' chars]';
			} else {
				out[key] = sanitizeForLog(data[key]);
			}
		}
		return out;
	}

	function formatBytes(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

	function clearLog() {
		if (logOutput) {
			logOutput.textContent = '';
		}
	}

	if (clearLogBtn) {
		clearLogBtn.addEventListener('click', clearLog);
	}

	log('Plugin admin loaded', 'info');
	log('Local site: ' + (siteInfo.url || 'unknown') + ' | tables: ' + ((siteInfo.tables || []).length), 'info');
	log('Connection key present: ' + (connectionKey ? 'yes' : 'no'), 'info');

	// ── UI helpers ────────────────────────────────────────────────────────────

	function showProgress(percent, text) {
		if (progressSection) progressSection.style.display = 'block';
		if (progressBarFill) {
			progressBarFill.style.width = percent + '%';
			progressBarFill.textContent = Math.round(percent) + '%';
		}
		if (progressText) progressText.textContent = text || '';
	}

	function showWarning(message) {
		if (!message || message.trim() === '') { hideWarning(); return; }
		if (warningSection) warningSection.style.display = 'block';
		if (warningMessage) warningMessage.textContent = message;
	}

	function hideWarning() {
		if (warningSection) warningSection.style.display = 'none';
	}

	function showError(message) {
		if (!message || message.trim() === '') { hideError(); return; }
		// Append helpful hints for common failure patterns
		let hint = '';
		if (/failed to fetch|networkerror|network error/i.test(message)) {
			hint = ' — check that the remote site is reachable and CORS is allowed';
		} else if (/waf.blocked|access denied/i.test(message)) {
			hint = ' — add the remote site\'s admin-ajax.php to your WAF allowlist';
		} else if (/ssl|certificate/i.test(message)) {
			hint = ' — try enabling "Skip SSL certificate verification" in Advanced Settings';
		} else if (/401|unauthorized/i.test(message)) {
			hint = ' — the remote site may require HTTP Basic Auth credentials (see Advanced Settings)';
		}
		if (errorSection) errorSection.style.display = 'block';
		if (errorMessage) errorMessage.textContent = message + hint;
	}

	function hideError() {
		if (errorSection) errorSection.style.display = 'none';
	}

	function showSuccess(message) {
		if (!message || message.trim() === '') { hideSuccess(); return; }
		if (successSection) successSection.style.display = 'block';
		if (successMessage) successMessage.textContent = message;
	}

	function hideSuccess() {
		if (successSection) successSection.style.display = 'none';
	}

	// ── Async helpers ─────────────────────────────────────────────────────────

	/**
	 * Retry a function up to maxAttempts times on thrown errors (network / HTTP non-200).
	 * Does NOT retry on 200-with-success:false (SQL already ran server-side).
	 */
	async function withRetry(fn, maxAttempts) {
		maxAttempts = maxAttempts || 3;
		let lastErr;
		for (let i = 0; i < maxAttempts; i++) {
			try {
				return await fn();
			} catch (e) {
				lastErr = e;
				if (i < maxAttempts - 1) {
					const delay = 2000 * Math.pow(2, i); // 2s, 4s, 8s
					log('Request failed (attempt ' + (i + 1) + '/' + maxAttempts + '), retrying in ' + (delay / 1000) + 's: ' + e.message, 'warn');
					await new Promise(function(r) { setTimeout(r, delay); });
				}
			}
		}
		throw lastErr;
	}

	/**
	 * Run tasks with at most `limit` executing at the same time.
	 * Fails fast: rejects on first task rejection.
	 */
	async function runWithConcurrency(tasks, limit) {
		const results = [];
		const executing = new Set();
		for (const task of tasks) {
			const p = task().then(function(r) { executing.delete(p); return r; });
			executing.add(p);
			results.push(p);
			if (executing.size >= limit) {
				await Promise.race(executing);
			}
		}
		return Promise.all(results);
	}

	/**
	 * Split tables into small batches and large individual tables.
	 * Small = byte size < SMALL_TABLE_THRESHOLD (from information_schema).
	 */
	function groupTables(tables, tableSizes) {
		const small = [];
		const large = [];
		for (const t of tables) {
			const size = tableSizes[t];
			if (typeof size === 'number' && size < SMALL_TABLE_THRESHOLD) {
				small.push(t);
			} else {
				large.push(t);
			}
		}
		const batches = [];
		for (let i = 0; i < small.length; i += SMALL_TABLE_BATCH_SIZE) {
			batches.push(small.slice(i, i + SMALL_TABLE_BATCH_SIZE));
		}
		return { batches: batches, largeTables: large };
	}

	/**
	 * POST to local AJAX endpoint; throws on network error or HTTP non-200.
	 */
	async function localPost(data) {
		const response = await fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams(data),
			signal: typeof AbortSignal !== 'undefined' && AbortSignal.timeout
				? AbortSignal.timeout(600000)
				: undefined
		});
		if (!response.ok) {
			const text = await response.text().catch(function() { return ''; });
			throw new Error('HTTP ' + response.status + ': ' + text.substring(0, 200));
		}
		return response.json();
	}

	/**
	 * Build and sign an import request body for sync_import_chunk.
	 */
	async function buildImportRequest(sqlField, sqlValue, remoteInfo) {
		const req = {
			action: 'sync_import_chunk',
			key: remoteKey,
			old_url: remoteInfo.url,
			new_url: siteInfo.url,
			old_path: remoteInfo.path,
			new_path: siteInfo.path,
			source_prefix: remoteInfo.prefix
		};
		req[sqlField] = sqlValue;
		const sig = await createSignature(req, connectionKey);
		req.nonce = nonce;
		req.sig = sig;
		return req;
	}

	// ── Event bindings ────────────────────────────────────────────────────────

	if (copyKeyBtn && connectionKeyInput) {
		copyKeyBtn.addEventListener('click', function() {
			connectionKeyInput.select();
			connectionKeyInput.setSelectionRange(0, 99999);
			try {
				document.execCommand('copy');
				copyKeyBtn.textContent = i18n.keyCopied || 'Key copied!';
				setTimeout(function() {
					copyKeyBtn.textContent = i18n.copyKey || 'Copy Key';
				}, 2000);
			} catch (err) {
				console.error('Failed to copy:', err);
			}
		});
	}

	if (verifyConnectionBtn) {
		verifyConnectionBtn.addEventListener('click', verifyConnection);
	}

	if (pullDatabaseBtn) {
		pullDatabaseBtn.addEventListener('click', function() { startMigration('pull'); });
	}

	if (pushDatabaseBtn) {
		pushDatabaseBtn.addEventListener('click', function() { startMigration('push'); });
	}

	if (saveAdvancedBtn) {
		saveAdvancedBtn.addEventListener('click', saveAdvancedSettings);
	}

	// ── Save advanced settings ────────────────────────────────────────────────

	async function saveAdvancedSettings() {
		saveAdvancedBtn.disabled = true;
		if (settingsSaveStatus) { settingsSaveStatus.textContent = ''; settingsSaveStatus.className = 'connection-status'; }

		try {
			const data = {
				action: 'sync_save_settings',
				basic_auth_user: basicAuthUserInput ? basicAuthUserInput.value : '',
				basic_auth_pass: basicAuthPassInput ? basicAuthPassInput.value : '',
				skip_ssl: skipSslInput && skipSslInput.checked ? '1' : '',
				concurrency: concurrencyInput ? concurrencyInput.value : '3'
			};
			const sig = await createSignature(data, connectionKey);
			data.nonce = nonce;
			data.sig = sig;

			const result = await localPost(data);
			if (result.success) {
				if (settingsSaveStatus) { settingsSaveStatus.textContent = 'Saved!'; settingsSaveStatus.className = 'connection-status success'; }
				log('Advanced settings saved', 'success');
				// Clear the password field after saving
				if (basicAuthPassInput) basicAuthPassInput.value = '';
			} else {
				if (settingsSaveStatus) { settingsSaveStatus.textContent = result.error || 'Save failed'; settingsSaveStatus.className = 'connection-status error'; }
			}
		} catch (err) {
			if (settingsSaveStatus) { settingsSaveStatus.textContent = 'Save failed: ' + err.message; settingsSaveStatus.className = 'connection-status error'; }
			log('Settings save failed: ' + err.message, 'error');
		} finally {
			saveAdvancedBtn.disabled = false;
		}
	}

	// ── Connection verification ───────────────────────────────────────────────

	async function verifyConnection() {
		remoteUrl = remoteUrlInput.value.trim();
		remoteKey = remoteKeyInput.value.trim();

		if (!remoteUrl || !remoteKey) {
			log('Verify aborted: missing remote URL or key', 'warn');
			showError(i18n.enterRemoteUrl || 'Please enter remote URL and key');
			return;
		}

		remoteUrl = remoteUrl.replace(/\/$/, '');
		log('Verifying connection to ' + remoteUrl, 'info');

		verifyConnectionBtn.disabled = true;
		verifyConnectionBtn.textContent = i18n.verifyConnection || 'Verifying...';
		connectionStatus.textContent = '';
		connectionStatus.className = 'connection-status';

		try {
			const data = {
				action: 'sync_verify_connection',
				url: remoteUrl,
				key: remoteKey
			};
			const sig = await createSignature(data, connectionKey);
			data.nonce = nonce;
			data.sig = sig;

			const response = await fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams(data)
			});

			log('Verify response HTTP ' + response.status, 'info');
			const result = await response.json();
			logObject('Verify response', result, result.success ? 'success' : 'error');

			if (result.success) {
				connectionVerified = true;
				log('Connection verified — migration buttons enabled', 'success');
				connectionStatus.textContent = i18n.connectionVerified || 'Connection verified!';
				connectionStatus.className = 'connection-status success';
				pullDatabaseBtn.disabled = false;
				pushDatabaseBtn.disabled = false;
			} else {
				connectionVerified = false;
				log('Connection failed: ' + (result.error || 'unknown'), 'error');
				connectionStatus.textContent = result.error || (i18n.connectionFailed || 'Connection failed');
				connectionStatus.className = 'connection-status error';
				pullDatabaseBtn.disabled = true;
				pushDatabaseBtn.disabled = true;
			}
		} catch (error) {
			connectionVerified = false;
			log('Verify exception: ' + error.message, 'error');
			connectionStatus.textContent = i18n.connectionFailed || 'Connection failed';
			connectionStatus.className = 'connection-status error';
			pullDatabaseBtn.disabled = true;
			pushDatabaseBtn.disabled = true;
		} finally {
			verifyConnectionBtn.disabled = false;
			verifyConnectionBtn.textContent = i18n.verifyConnection || 'Verify Connection';
		}
	}

	// ── Migration orchestration ───────────────────────────────────────────────

	async function startMigration(type) {
		if (!connectionVerified) {
			log('Migration blocked: connection not verified', 'warn');
			showError('Please verify connection first');
			return;
		}
		if (migrationInProgress) {
			log('Migration already in progress', 'warn');
			return;
		}

		const syncPluginsEl = document.getElementById('sync-plugins');
		const syncUploadsEl = document.getElementById('sync-uploads');
		const doSyncPlugins = syncPluginsEl && syncPluginsEl.checked;
		const doSyncUploads = syncUploadsEl && syncUploadsEl.checked;

		log('Starting ' + type + ' migration → ' + remoteUrl, 'info');
		if (doSyncPlugins) log('Plugins sync: enabled', 'info');
		if (doSyncUploads) log('Uploads sync: enabled', 'info');

		migrationInProgress = true;
		hideError();
		hideWarning();
		hideSuccess();
		showProgress(0, i18n.migrationInProgress || 'Migration in progress...');

		pullDatabaseBtn.disabled = true;
		pushDatabaseBtn.disabled = true;
		verifyConnectionBtn.disabled = true;

		try {
			if (type === 'pull') {
				await pullDatabase();
			} else {
				await pushDatabase();
			}

			if (doSyncPlugins || doSyncUploads) {
				hideSuccess();
				await syncFiles(type, doSyncPlugins, doSyncUploads);
			}
		} catch (error) {
			log('Migration failed: ' + (error.message || 'unknown error'), 'error');
			showError(error.message || 'Migration failed');
			console.error('Migration error:', error);
		} finally {
			log('Migration finished', 'info');
			migrationInProgress = false;
			pullDatabaseBtn.disabled = false;
			pushDatabaseBtn.disabled = false;
			verifyConnectionBtn.disabled = false;
		}
	}

	// ── Pull migration ────────────────────────────────────────────────────────

	async function pullDatabase() {
		const remoteAjaxUrl = remoteUrl + '/wp-admin/admin-ajax.php';
		log('Pull: remote AJAX URL ' + remoteAjaxUrl, 'info');

		// Step 1: Get remote site info
		showProgress(5, 'Getting remote site info...');
		log('Pull step 1: sync_get_connection_info (remote)', 'info');
		const remoteInfo = await remoteRequest(remoteAjaxUrl, {
			action: 'sync_get_connection_info',
			key: remoteKey
		}, remoteKey);

		if (!remoteInfo.success || !remoteInfo.data) {
			logObject('Pull step 1 failed', remoteInfo, 'error');
			throw new Error(remoteInfo.error || 'Failed to get remote site info');
		}
		log('Pull step 1 OK — remote tables: ' + (remoteInfo.data.tables || []).length + ' | plugin: ' + (remoteInfo.data.plugin_version || 'unknown'), 'success');

		// Charset warning
		if (siteInfo.db_charset && remoteInfo.data.db_charset && siteInfo.db_charset !== remoteInfo.data.db_charset) {
			const msg = 'Charset mismatch: local=' + siteInfo.db_charset + ', remote=' + remoteInfo.data.db_charset + '. Serialized data may not convert correctly.';
			log(msg, 'warn');
			showWarning(msg);
		}

		// Step 2: Initiate migration (creates local backup)
		showProgress(15, i18n.creatingBackup || 'Creating backup...');
		log('Pull step 2: sync_initiate_migration (local backup)', 'info');
		const initiateReq = {
			action: 'sync_initiate_migration',
			action_type: 'pull',
			key: remoteKey
		};
		const initiateSig = await createSignature(initiateReq, connectionKey);
		initiateReq.nonce = nonce;
		initiateReq.sig = initiateSig;

		const initiateData = await withRetry(function() { return localPost(initiateReq); }, 1);
		logObject('Pull step 2 response', initiateData, initiateData.success ? 'success' : 'error');
		if (!initiateData.success) {
			throw new Error(initiateData.error || 'Failed to initiate migration');
		}

		// Step 3: Export and import tables (parallel + batched)
		const tables = remoteInfo.data.tables || [];
		const tableSizes = remoteInfo.data.table_sizes || {};
		const { batches, largeTables } = groupTables(tables, tableSizes);

		log('Pull step 3: ' + tables.length + ' tables → ' + batches.length + ' small batches + ' + largeTables.length + ' large tables (concurrency=' + CONCURRENCY + ')', 'info');

		let completedTasks = 0;
		const totalTasks = batches.length + largeTables.length || 1;

		function updateProgress() {
			showProgress(20 + (completedTasks / totalTasks) * 65, 'Syncing tables (' + completedTasks + '/' + totalTasks + ')...');
		}
		updateProgress();

		const tasks = [];

		// Small table batch tasks
		for (const batch of batches) {
			tasks.push(async function() {
				try {
					log('Pull batch: ' + batch.join(', '), 'info');

					const exportResult = await withRetry(function() {
						return remoteRequest(remoteAjaxUrl, {
							action: 'sync_export_batch',
							key: remoteKey,
							tables: JSON.stringify(batch)
						}, remoteKey);
					});

					if (!exportResult.success) {
						throw new Error(exportResult.error || 'Batch export failed');
					}

					const sqlField = exportResult.sql_gz_b64 ? 'sql_gz_b64' : 'sql';
					const importReq = await buildImportRequest(sqlField, exportResult[sqlField], remoteInfo.data);
					const importData = await withRetry(function() { return localPost(importReq); });
					if (!importData.success) {
						throw new Error(importData.error || 'Batch import failed');
					}

					log('Pull batch OK: ' + batch.length + ' tables', 'success');
					completedTasks++;
					updateProgress();
				} catch (err) {
					throw new Error('Batch [' + batch.join(',') + ']: ' + err.message);
				}
			});
		}

		// Large table tasks
		for (const table of largeTables) {
			tasks.push(async function() {
				try {
					let offset = 0;
					let hasMore = true;

					while (hasMore) {
						const exportResult = await withRetry(function() {
							return remoteRequest(remoteAjaxUrl, {
								action: 'sync_export_chunk',
								key: remoteKey,
								table: table,
								offset: offset
							}, remoteKey);
						});

						if (!exportResult.success) {
							throw new Error(exportResult.error || 'Export failed');
						}
						log('Pull ' + table + ' offset ' + offset + ' — rows ' + exportResult.rows_exported + '/' + exportResult.total_rows, 'info');

						const sqlField = exportResult.sql_gz_b64 ? 'sql_gz_b64' : 'sql';
						const importReq = await buildImportRequest(sqlField, exportResult[sqlField], remoteInfo.data);
						const importData = await withRetry(function() { return localPost(importReq); });
						if (!importData.success) {
							throw new Error(importData.error || 'Import failed');
						}

						hasMore = exportResult.has_more;
						offset += exportResult.rows_exported || 1000;
					}

					log('Pull ' + table + ' complete', 'success');
					completedTasks++;
					updateProgress();
				} catch (err) {
					throw new Error('Table ' + table + ': ' + err.message);
				}
			});
		}

		await runWithConcurrency(tasks, CONCURRENCY);

		// Step 4: Finalize
		showProgress(90, i18n.finalizing || 'Finalizing migration...');
		log('Pull step 4: sync_finalize_migration (local)', 'info');
		const finalizeReq = { action: 'sync_finalize_migration', key: remoteKey };
		const finalizeSig = await createSignature(finalizeReq, connectionKey);
		finalizeReq.nonce = nonce;
		finalizeReq.sig = finalizeSig;

		const finalizeData = await localPost(finalizeReq);
		logObject('Pull step 4 response', finalizeData, finalizeData.success ? 'success' : 'error');
		if (!finalizeData.success) {
			throw new Error(finalizeData.error || 'Finalization failed');
		}

		log('Pull migration completed successfully', 'success');
		showProgress(100, i18n.migrationComplete || 'Migration completed!');
		showSuccess(i18n.migrationComplete || 'Migration completed successfully!');
	}

	// ── Push migration ────────────────────────────────────────────────────────

	async function pushDatabase() {
		const remoteAjaxUrl = remoteUrl + '/wp-admin/admin-ajax.php';
		log('Push: remote AJAX URL ' + remoteAjaxUrl, 'info');

		// Step 1: Get remote site info
		showProgress(5, 'Getting remote site info...');
		log('Push step 1: sync_get_connection_info (remote)', 'info');
		const remoteInfo = await remoteRequest(remoteAjaxUrl, {
			action: 'sync_get_connection_info',
			key: remoteKey
		}, remoteKey);

		if (!remoteInfo.success || !remoteInfo.data) {
			logObject('Push step 1 failed', remoteInfo, 'error');
			throw new Error(remoteInfo.error || 'Failed to get remote site info');
		}
		log('Push step 1 OK — remote: ' + remoteInfo.data.url + ' | plugin: ' + (remoteInfo.data.plugin_version || 'unknown'), 'success');

		// Charset warning
		if (siteInfo.db_charset && remoteInfo.data.db_charset && siteInfo.db_charset !== remoteInfo.data.db_charset) {
			const msg = 'Charset mismatch: local=' + siteInfo.db_charset + ', remote=' + remoteInfo.data.db_charset + '. Serialized data may not convert correctly.';
			log(msg, 'warn');
			showWarning(msg);
		}

		// Step 2: Initiate migration on remote (creates remote backup)
		showProgress(15, 'Initiating migration on remote site...');
		log('Push step 2: sync_initiate_migration (remote backup)', 'info');
		const initiateResult = await remoteRequest(remoteAjaxUrl, {
			action: 'sync_initiate_migration',
			action_type: 'push',
			key: remoteKey
		}, remoteKey);

		if (!initiateResult.success) {
			logObject('Push step 2 failed', initiateResult, 'error');
			throw new Error(initiateResult.error || 'Failed to initiate migration');
		}
		log('Push step 2 OK', 'success');

		// Step 3: Export and send tables (parallel + batched)
		const tables = siteInfo.tables || [];
		const tableSizes = siteInfo.table_sizes || {};
		const { batches, largeTables } = groupTables(tables, tableSizes);

		log('Push step 3: ' + tables.length + ' tables → ' + batches.length + ' small batches + ' + largeTables.length + ' large tables (concurrency=' + CONCURRENCY + ')', 'info');

		let completedTasks = 0;
		const totalTasks = batches.length + largeTables.length || 1;

		function updateProgress() {
			showProgress(20 + (completedTasks / totalTasks) * 65, 'Sending tables (' + completedTasks + '/' + totalTasks + ')...');
		}
		updateProgress();

		const tasks = [];

		// Small table batch tasks
		for (const batch of batches) {
			tasks.push(async function() {
				try {
					log('Push batch: ' + batch.join(', '), 'info');

					// Export batch locally
					const exportReq = {
						action: 'sync_export_batch',
						key: remoteKey,
						tables: JSON.stringify(batch)
					};
					const exportSig = await createSignature(exportReq, connectionKey);
					exportReq.nonce = nonce;
					exportReq.sig = exportSig;

					const exportData = await withRetry(function() { return localPost(exportReq); });
					if (!exportData.success) {
						throw new Error(exportData.error || 'Batch export failed');
					}

					// Send to remote via proxy
					const sqlField = exportData.sql_gz_b64 ? 'sql_gz_b64' : 'sql';
					const importResult = await withRetry(function() {
						return proxyRemoteImport({
							remote_url: remoteUrl,
							key: remoteKey,
							[sqlField]: exportData[sqlField],
							old_url: siteInfo.url,
							new_url: remoteInfo.data.url,
							old_path: siteInfo.path,
							new_path: remoteInfo.data.path,
							source_prefix: siteInfo.prefix
						});
					});

					if (!importResult.success) {
						throw new Error(importResult.error || 'Batch import failed');
					}

					log('Push batch OK: ' + batch.length + ' tables', 'success');
					completedTasks++;
					updateProgress();
				} catch (err) {
					throw new Error('Batch [' + batch.join(',') + ']: ' + err.message);
				}
			});
		}

		// Large table tasks
		for (const table of largeTables) {
			tasks.push(async function() {
				try {
					let offset = 0;
					let hasMore = true;

					while (hasMore) {
						const exportReq = {
							action: 'sync_export_chunk',
							key: remoteKey,
							table: table,
							offset: offset
						};
						const exportSig = await createSignature(exportReq, connectionKey);
						exportReq.nonce = nonce;
						exportReq.sig = exportSig;

						const exportData = await withRetry(function() { return localPost(exportReq); });
						if (!exportData.success) {
							throw new Error(exportData.error || 'Export failed');
						}
						const sqlKb = exportData.sql_bytes ? Math.round(exportData.sql_bytes / 1024) : 0;
						log('Push ' + table + ' offset ' + offset + ' — rows ' + exportData.rows_exported + '/' + exportData.total_rows + ', ~' + sqlKb + 'KB', 'info');

						if (sqlKb > 400) {
							log('Large chunk upload in progress for ' + table, 'warn');
						}

						const sqlField = exportData.sql_gz_b64 ? 'sql_gz_b64' : 'sql';
						const importResult = await withRetry(function() {
							return proxyRemoteImport({
								remote_url: remoteUrl,
								key: remoteKey,
								[sqlField]: exportData[sqlField],
								old_url: siteInfo.url,
								new_url: remoteInfo.data.url,
								old_path: siteInfo.path,
								new_path: remoteInfo.data.path,
								source_prefix: siteInfo.prefix
							});
						});

						if (!importResult.success) {
							throw new Error(importResult.error || 'Import failed');
						}

						hasMore = exportData.has_more;
						offset += exportData.rows_exported || 1000;
					}

					log('Push ' + table + ' complete', 'success');
					completedTasks++;
					updateProgress();
				} catch (err) {
					throw new Error('Table ' + table + ': ' + err.message);
				}
			});
		}

		await runWithConcurrency(tasks, CONCURRENCY);

		// Step 4: Finalize on remote
		showProgress(90, 'Finalizing migration on remote site...');
		log('Push step 4: sync_finalize_migration (remote)', 'info');
		const finalizeResult = await remoteRequest(remoteAjaxUrl, {
			action: 'sync_finalize_migration',
			key: remoteKey
		}, remoteKey);

		if (!finalizeResult.success) {
			logObject('Push step 4 failed', finalizeResult, 'error');
			throw new Error(finalizeResult.error || 'Finalization failed');
		}

		log('Push migration completed successfully', 'success');
		showProgress(100, i18n.migrationComplete || 'Migration completed!');
		showSuccess(i18n.migrationComplete || 'Migration completed successfully!');
	}

	// ── Network helpers ───────────────────────────────────────────────────────

	/**
	 * Push import via local PHP proxy (server-to-server).
	 * Accepts either sql or sql_gz_b64 in payload.
	 */
	async function proxyRemoteImport(payload) {
		log('Proxy import via local server → ' + payload.remote_url, 'info');

		const data = {
			action: 'sync_remote_import_chunk',
			remote_url: payload.remote_url,
			key: payload.key,
			old_url: payload.old_url,
			new_url: payload.new_url,
			old_path: payload.old_path,
			new_path: payload.new_path,
			source_prefix: payload.source_prefix
		};

		// Pass through whichever SQL field we have
		if (payload.sql_gz_b64) {
			data.sql_gz_b64 = payload.sql_gz_b64;
		} else {
			data.sql = payload.sql;
		}

		const sig = await createSignature(data, connectionKey);
		data.nonce = nonce;
		data.sig = sig;

		const json = await localPost(data);
		log('Proxy import response success=' + json.success, json.success ? 'success' : 'error');
		if (!json.success) {
			logObject('Proxy import response', json, 'error');
		}
		return json;
	}

	/**
	 * Make a signed request to a remote site's admin-ajax.php.
	 */
	async function remoteRequest(url, data, key) {
		log('Remote request → ' + (data.action || 'unknown') + ' @ ' + url, 'info');
		logObject('Remote request payload', data, 'info');

		data.sig = await createSignature(data, key);

		let response;
		try {
			response = await fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams(data)
			});
		} catch (error) {
			const hint = ' — check that the remote site is reachable and CORS is allowed';
			log('Remote fetch failed: ' + error.message + hint, 'error');
			const err = new Error(error.message + hint);
			err.cause = error;
			throw err;
		}

		if (!response.ok) {
			const errorText = await response.text().catch(function() { return ''; });
			log('Remote request HTTP ' + response.status + ' — body: ' + errorText.substring(0, 200), 'error');
			let parsed;
			try { parsed = JSON.parse(errorText); } catch (e) { parsed = null; }
			throw new Error(
				(parsed && (parsed.error || parsed.message)) ||
				'HTTP ' + response.status + ': ' + errorText.substring(0, 200)
			);
		}

		const json = await response.json();
		log('Remote response ← ' + (data.action || 'unknown') + ' success=' + json.success, json.success ? 'success' : 'warn');
		if (!json.success) {
			logObject('Remote response body', json, 'error');
		}
		return json;
	}

	// ── File sync orchestration ───────────────────────────────────────────────

	async function syncFiles(type, syncPlugins, syncUploads) {
		const remoteAjaxUrl = remoteUrl + '/wp-admin/admin-ajax.php';

		if (syncPlugins) {
			log('File sync: plugins phase (' + type + ')', 'info');
			showProgress(0, 'Syncing plugins...');
			if (type === 'pull') {
				await pullPlugins(remoteAjaxUrl);
			} else {
				await pushPlugins(remoteAjaxUrl);
			}
		}

		if (syncUploads) {
			log('File sync: uploads phase (' + type + ')', 'info');
			showProgress(0, 'Scanning uploads...');
			if (type === 'pull') {
				await pullUploads(remoteAjaxUrl);
			} else {
				await pushUploads(remoteAjaxUrl);
			}
		}

		showProgress(100, 'File sync complete!');
		showSuccess('Migration and file sync completed successfully!');
	}

	// ── Plugins sync ──────────────────────────────────────────────────────────

	/**
	 * Pull: remote creates ZIP, browser fetches chunks → imports locally → finalize locally.
	 */
	async function pullPlugins(remoteAjaxUrl) {
		let zipId = null;
		let offset = 0;
		let done = false;
		let totalSize = 0;

		log('Plugins pull: creating ZIP on remote', 'info');

		while (!done) {
			const payload = { action: 'sync_export_zip_chunk', key: remoteKey, offset: offset };
			if (zipId) payload.zip_id = zipId;

			const exportResult = await withRetry(function() { return remoteRequest(remoteAjaxUrl, payload, remoteKey); });
			if (!exportResult.success) {
				throw new Error(exportResult.error || 'Plugin ZIP export failed');
			}

			zipId      = exportResult.zip_id;
			totalSize  = exportResult.total_size || totalSize;
			done       = exportResult.done;
			const nextOffset = exportResult.next_offset;

			const importReq = { action: 'sync_import_zip_chunk', zip_id: zipId, data: exportResult.data };
			const importSig = await createSignature(importReq, connectionKey);
			importReq.nonce = nonce;
			importReq.sig   = importSig;

			const importResult = await withRetry(function() { return localPost(importReq); });
			if (!importResult.success) {
				throw new Error(importResult.error || 'Plugin ZIP import failed');
			}

			if (totalSize > 0) {
				showProgress(Math.round((nextOffset / totalSize) * 90), 'Syncing plugins (' + formatBytes(nextOffset) + ' / ' + formatBytes(totalSize) + ')...');
			}
			offset = nextOffset;
		}

		log('Plugins pull: finalizing locally', 'info');
		const finalizeReq = { action: 'sync_finalize_plugins', zip_id: zipId };
		const finalizeSig = await createSignature(finalizeReq, connectionKey);
		finalizeReq.nonce = nonce;
		finalizeReq.sig   = finalizeSig;

		const finalizeResult = await localPost(finalizeReq);
		if (!finalizeResult.success) {
			throw new Error(finalizeResult.error || 'Plugin finalization failed');
		}

		log('Plugins pull complete', 'success');
		showProgress(100, 'Plugins synced!');
	}

	/**
	 * Push: local creates ZIP, browser fetches chunks → imports on remote → finalize remote.
	 */
	async function pushPlugins(remoteAjaxUrl) {
		let zipId = null;
		let offset = 0;
		let done = false;
		let totalSize = 0;

		log('Plugins push: creating ZIP locally', 'info');

		while (!done) {
			const exportReq = { action: 'sync_export_zip_chunk', key: remoteKey, offset: offset };
			if (zipId) exportReq.zip_id = zipId;
			const exportSig = await createSignature(exportReq, connectionKey);
			exportReq.nonce = nonce;
			exportReq.sig   = exportSig;

			const exportResult = await withRetry(function() { return localPost(exportReq); });
			if (!exportResult.success) {
				throw new Error(exportResult.error || 'Plugin ZIP export failed');
			}

			zipId     = exportResult.zip_id;
			totalSize = exportResult.total_size || totalSize;
			done      = exportResult.done;
			const nextOffset = exportResult.next_offset;

			const importResult = await withRetry(function() {
				return remoteRequest(remoteAjaxUrl, {
					action: 'sync_import_zip_chunk',
					key: remoteKey,
					zip_id: zipId,
					data: exportResult.data
				}, remoteKey);
			});
			if (!importResult.success) {
				throw new Error(importResult.error || 'Plugin ZIP import failed');
			}

			if (totalSize > 0) {
				showProgress(Math.round((nextOffset / totalSize) * 90), 'Syncing plugins (' + formatBytes(nextOffset) + ' / ' + formatBytes(totalSize) + ')...');
			}
			offset = nextOffset;
		}

		log('Plugins push: finalizing on remote', 'info');
		const finalizeResult = await remoteRequest(remoteAjaxUrl, {
			action: 'sync_finalize_plugins',
			key: remoteKey,
			zip_id: zipId
		}, remoteKey);
		if (!finalizeResult.success) {
			throw new Error(finalizeResult.error || 'Plugin finalization failed');
		}

		log('Plugins push complete', 'success');
		showProgress(100, 'Plugins synced!');
	}

	// ── Uploads sync ──────────────────────────────────────────────────────────

	/**
	 * Compute relative paths present in sourceInv but absent or hash-changed in destInv.
	 */
	function diffInventories(sourceInv, destInv) {
		const toTransfer = [];
		for (const path of Object.keys(sourceInv)) {
			const srcInfo = sourceInv[path];
			const srcHash = srcInfo && typeof srcInfo === 'object' ? srcInfo.hash : srcInfo;
			if (!destInv[path]) {
				toTransfer.push(path);
			} else {
				const dstInfo = destInv[path];
				const dstHash = dstInfo && typeof dstInfo === 'object' ? dstInfo.hash : dstInfo;
				if (srcHash !== dstHash) {
					toTransfer.push(path);
				}
			}
		}
		return toTransfer;
	}

	async function getLocalInventory(dirType) {
		const req = { action: 'sync_get_file_inventory', key: remoteKey, dir_type: dirType };
		const sig = await createSignature(req, connectionKey);
		req.nonce = nonce;
		req.sig   = sig;
		const result = await withRetry(function() { return localPost(req); });
		if (!result.success) {
			throw new Error(result.error || 'Failed to get local ' + dirType + ' inventory');
		}
		return result.inventory || {};
	}

	async function getRemoteInventory(remoteAjaxUrl, dirType) {
		const result = await withRetry(function() {
			return remoteRequest(remoteAjaxUrl, {
				action: 'sync_get_file_inventory',
				key: remoteKey,
				dir_type: dirType
			}, remoteKey);
		});
		if (!result.success) {
			throw new Error(result.error || 'Failed to get remote ' + dirType + ' inventory');
		}
		return result.inventory || {};
	}

	async function transferUploadFiles(files, sourceInv, fetchChunk, importChunk) {
		const totalBytes = files.reduce(function(sum, path) {
			const info = sourceInv[path];
			return sum + (info && info.size ? info.size : 0);
		}, 0);

		log('Uploads: ' + files.length + ' files to transfer (' + formatBytes(totalBytes) + ')', 'info');

		if (files.length === 0) {
			showProgress(100, 'Uploads up to date, nothing to transfer.');
			return;
		}

		let bytesTransferred = 0;

		for (const relativePath of files) {
			let offset = 0;
			let done = false;

			while (!done) {
				const exportResult = await withRetry(function() { return fetchChunk(relativePath, offset); });
				if (!exportResult.success) {
					throw new Error(exportResult.error || 'Upload export failed: ' + relativePath);
				}

				done = exportResult.done;

				const importResult = await withRetry(function() { return importChunk(relativePath, exportResult.data, done); });
				if (!importResult.success) {
					throw new Error(importResult.error || 'Upload import failed: ' + relativePath);
				}

				const chunkBytes = exportResult.next_offset - offset;
				bytesTransferred += chunkBytes;
				offset = exportResult.next_offset;

				if (totalBytes > 0) {
					showProgress(
						10 + Math.round((bytesTransferred / totalBytes) * 85),
						'Syncing uploads (' + formatBytes(bytesTransferred) + ' / ' + formatBytes(totalBytes) + ')...'
					);
				}
			}
		}
	}

	async function pullUploads(remoteAjaxUrl) {
		log('Uploads pull: getting inventories', 'info');
		showProgress(5, 'Scanning uploads on both sites...');

		const remoteInv = await getRemoteInventory(remoteAjaxUrl, 'uploads');
		const localInv  = await getLocalInventory('uploads');
		const toTransfer = diffInventories(remoteInv, localInv);

		if (toTransfer.length === 0) {
			log('Uploads pull: all files up to date', 'success');
			showProgress(100, 'Uploads already in sync!');
			return;
		}

		await transferUploadFiles(
			toTransfer,
			remoteInv,
			function(relativePath, offset) {
				return remoteRequest(remoteAjaxUrl, {
					action: 'sync_export_upload_chunk',
					key: remoteKey,
					relative_path: relativePath,
					offset: offset
				}, remoteKey);
			},
			async function(relativePath, data, isLast) {
				const req = {
					action: 'sync_import_upload_chunk',
					key: remoteKey,
					relative_path: relativePath,
					data: data,
					is_last: isLast ? '1' : ''
				};
				const sig = await createSignature(req, connectionKey);
				req.nonce = nonce;
				req.sig   = sig;
				return localPost(req);
			}
		);

		log('Uploads pull complete', 'success');
		showProgress(100, 'Uploads synced!');
	}

	async function pushUploads(remoteAjaxUrl) {
		log('Uploads push: getting inventories', 'info');
		showProgress(5, 'Scanning uploads on both sites...');

		const localInv  = await getLocalInventory('uploads');
		const remoteInv = await getRemoteInventory(remoteAjaxUrl, 'uploads');
		const toTransfer = diffInventories(localInv, remoteInv);

		if (toTransfer.length === 0) {
			log('Uploads push: all files up to date', 'success');
			showProgress(100, 'Uploads already in sync!');
			return;
		}

		await transferUploadFiles(
			toTransfer,
			localInv,
			async function(relativePath, offset) {
				const req = {
					action: 'sync_export_upload_chunk',
					key: remoteKey,
					relative_path: relativePath,
					offset: offset
				};
				const sig = await createSignature(req, connectionKey);
				req.nonce = nonce;
				req.sig   = sig;
				return localPost(req);
			},
			function(relativePath, data, isLast) {
				return remoteRequest(remoteAjaxUrl, {
					action: 'sync_import_upload_chunk',
					key: remoteKey,
					relative_path: relativePath,
					data: data,
					is_last: isLast ? '1' : ''
				}, remoteKey);
			}
		);

		log('Uploads push complete', 'success');
		showProgress(100, 'Uploads synced!');
	}

	// ── HMAC signing ──────────────────────────────────────────────────────────

	async function createSignature(data, key) {
		const cleanData = Object.assign({}, data);
		delete cleanData.sig;
		delete cleanData.nonce;

		const sortedKeys = Object.keys(cleanData).sort();
		const sortedData = {};
		for (const k of sortedKeys) {
			sortedData[k] = cleanData[k];
		}

		const params = new URLSearchParams(sortedData);
		const queryString = params.toString();

		const encoder = new TextEncoder();
		const cryptoKey = await crypto.subtle.importKey(
			'raw',
			encoder.encode(key),
			{ name: 'HMAC', hash: 'SHA-256' },
			false,
			['sign']
		);

		const signatureBuffer = await crypto.subtle.sign('HMAC', cryptoKey, encoder.encode(queryString));
		return Array.from(new Uint8Array(signatureBuffer))
			.map(function(b) { return b.toString(16).padStart(2, '0'); })
			.join('');
	}

})();
