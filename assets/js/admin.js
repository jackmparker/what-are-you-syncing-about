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
	const errorSection = document.getElementById('error-section');
	const errorMessage = document.getElementById('error-message');
	const successSection = document.getElementById('success-section');
	const successMessage = document.getElementById('success-message');
	const logOutput = document.getElementById('sync-log-output');
	const clearLogBtn = document.getElementById('clear-log-btn');

	/**
	 * Append a timestamped line to the activity log
	 */
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
			} else if ((lower === 'sql' || lower === 'sql_b64') && typeof data[key] === 'string') {
				out[key] = '[' + key + ' ' + data[key].length + ' chars]';
			} else {
				out[key] = sanitizeForLog(data[key]);
			}
		}
		return out;
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

	// Copy key to clipboard
	if (copyKeyBtn && connectionKeyInput) {
		copyKeyBtn.addEventListener('click', function() {
			connectionKeyInput.select();
			connectionKeyInput.setSelectionRange(0, 99999); // For mobile devices

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

	// Verify connection
	if (verifyConnectionBtn) {
		verifyConnectionBtn.addEventListener('click', verifyConnection);
	}

	// Pull database
	if (pullDatabaseBtn) {
		pullDatabaseBtn.addEventListener('click', function() {
			startMigration('pull');
		});
	}

	// Push database
	if (pushDatabaseBtn) {
		pushDatabaseBtn.addEventListener('click', function() {
			startMigration('push');
		});
	}

	/**
	 * Verify connection to remote site
	 */
	async function verifyConnection() {
		remoteUrl = remoteUrlInput.value.trim();
		remoteKey = remoteKeyInput.value.trim();

		if (!remoteUrl || !remoteKey) {
			log('Verify aborted: missing remote URL or key', 'warn');
			showError(i18n.enterRemoteUrl || 'Please enter remote URL and key');
			return;
		}

		// Normalize URL
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

			// Create signature using the local key (server expects local key for wp_ajax)
			const sig = await createSignature(data, connectionKey);
			data.nonce = nonce;
			data.sig = sig;

			const response = await fetch(ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
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
				log('Connection failed: ' + (result.error || result.data?.message || 'unknown'), 'error');
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
			console.error('Connection error:', error);
		} finally {
			verifyConnectionBtn.disabled = false;
			verifyConnectionBtn.textContent = i18n.verifyConnection || 'Verify Connection';
		}
	}

	/**
	 * Start migration (pull or push)
	 */
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

		log('Starting ' + type + ' migration → ' + remoteUrl, 'info');
		migrationInProgress = true;
		hideError();
		hideSuccess();
		showProgress(0, i18n.migrationInProgress || 'Migration in progress...');

		// Disable buttons
		pullDatabaseBtn.disabled = true;
		pushDatabaseBtn.disabled = true;
		verifyConnectionBtn.disabled = true;

		try {
			if (type === 'pull') {
				await pullDatabase();
			} else {
				await pushDatabase();
			}
		} catch (error) {
			log('Migration failed: ' + (error.message || 'unknown error'), 'error');
			showError(error.message || 'Migration failed');
			console.error('Migration error:', error);
		} finally {
			log('Migration finished (inProgress=false)', 'info');
			migrationInProgress = false;
			pullDatabaseBtn.disabled = false;
			pushDatabaseBtn.disabled = false;
			verifyConnectionBtn.disabled = false;
		}
	}

	/**
	 * Pull database from remote
	 */
	async function pullDatabase() {
		const remoteAjaxUrl = remoteUrl + '/wp-admin/admin-ajax.php';
		log('Pull: remote AJAX URL ' + remoteAjaxUrl, 'info');

		// Step 1: Get remote site info
		showProgress(10, i18n.creatingBackup || 'Getting remote site info...');
		log('Pull step 1: sync_get_connection_info (remote)', 'info');
		const remoteInfo = await remoteRequest(remoteAjaxUrl, {
			action: 'sync_get_connection_info',
			key: remoteKey
		}, remoteKey);

		if (!remoteInfo.success || !remoteInfo.data) {
			logObject('Pull step 1 failed', remoteInfo, 'error');
			throw new Error(remoteInfo.error || 'Failed to get remote site info');
		}
		log('Pull step 1 OK — remote tables: ' + (remoteInfo.data.tables || []).length, 'success');
		logObject('Remote site info', remoteInfo.data, 'info');

		// Step 2: Initiate migration (creates local backup)
		showProgress(20, i18n.creatingBackup || 'Creating backup...');
		log('Pull step 2: sync_initiate_migration (local backup)', 'info');
		const initiateData_req = {
			action: 'sync_initiate_migration',
			action_type: 'pull',
			key: remoteKey
		};
		const initiateSig = await createSignature(initiateData_req, connectionKey);
		initiateData_req.nonce = nonce;
		initiateData_req.sig = initiateSig;

		const initiateResult = await fetch(ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams(initiateData_req)
		});
		const initiateData = await initiateResult.json();
		logObject('Pull step 2 response', initiateData, initiateData.success ? 'success' : 'error');

		if (!initiateData.success) {
			throw new Error(initiateData.error || 'Failed to initiate migration');
		}

		// Step 3: Export and import tables
		const tables = remoteInfo.data.tables || [];
		const totalTables = tables.length;
		let completedTables = 0;
		log('Pull step 3: syncing ' + totalTables + ' tables', 'info');

		for (const table of tables) {
			log('Pull table ' + (completedTables + 1) + '/' + totalTables + ': ' + table, 'info');
			let offset = 0;
			let hasMore = true;

			while (hasMore) {
				showProgress(
					30 + (completedTables / totalTables) * 50,
					`${i18n.exportingTables || 'Exporting'} ${table}...`
				);

				// Export chunk from remote
				const exportResult = await remoteRequest(remoteAjaxUrl, {
					action: 'sync_export_chunk',
					key: remoteKey,
					table: table,
					offset: offset
				}, remoteKey);

				if (!exportResult.success) {
					logObject('Export chunk failed (' + table + '@' + offset + ')', exportResult, 'error');
					throw new Error(exportResult.error || 'Export failed');
				}
				log('Exported ' + table + ' offset ' + offset + ' — rows: ' + exportResult.rows_exported + '/' + exportResult.total_rows + ', has_more: ' + exportResult.has_more, 'info');

				// Import chunk locally
				showProgress(
					30 + (completedTables / totalTables) * 50,
					`${i18n.importingTables || 'Importing'} ${table}... (${offset} / ${exportResult.total_rows})`
				);

				// Create request data
				const importData_req = {
					action: 'sync_import_chunk',
					key: remoteKey,
					sql: exportResult.sql,
					old_url: remoteInfo.data.url,
					new_url: siteInfo.url,
					old_path: remoteInfo.data.path,
					new_path: siteInfo.path,
					source_prefix: remoteInfo.data.prefix
				};

				// Create signature from the data that will be sent
				const sig = await createSignature(importData_req, connectionKey);

				// Add nonce and signature
				importData_req.nonce = nonce;
				importData_req.sig = sig;

				const importResult = await fetch(ajaxUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams(importData_req)
				});
				const importData = await importResult.json();

				if (!importData.success) {
					logObject('Import chunk failed (' + table + '@' + offset + ')', importData, 'error');
					throw new Error(importData.error || 'Import failed');
				}
				log('Imported ' + table + ' offset ' + offset, 'success');

				hasMore = exportResult.has_more;
				offset += exportResult.rows_exported || 1000;
			}

			completedTables++;
		}

		// Step 4: Finalize migration
		showProgress(90, i18n.finalizing || 'Finalizing migration...');
		log('Pull step 4: sync_finalize_migration (local)', 'info');
		const finalizeData_req = {
			action: 'sync_finalize_migration',
			key: remoteKey
		};
		const finalizeSig = await createSignature(finalizeData_req, connectionKey);
		finalizeData_req.nonce = nonce;
		finalizeData_req.sig = finalizeSig;

		const finalizeResult = await fetch(ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams(finalizeData_req)
		});
		const finalizeData = await finalizeResult.json();
		logObject('Pull step 4 response', finalizeData, finalizeData.success ? 'success' : 'error');

		if (!finalizeData.success) {
			throw new Error(finalizeData.error || 'Finalization failed');
		}

		log('Pull migration completed successfully', 'success');
		showProgress(100, i18n.migrationComplete || 'Migration completed!');
		showSuccess(i18n.migrationComplete || 'Migration completed successfully!');
	}

	/**
	 * Push database to remote
	 */
	async function pushDatabase() {
		const remoteAjaxUrl = remoteUrl + '/wp-admin/admin-ajax.php';
		log('Push: remote AJAX URL ' + remoteAjaxUrl, 'info');

		// Step 1: Get remote site info
		showProgress(10, 'Getting remote site info...');
		log('Push step 1: sync_get_connection_info (remote)', 'info');
		const remoteInfo = await remoteRequest(remoteAjaxUrl, {
			action: 'sync_get_connection_info',
			key: remoteKey
		}, remoteKey);

		if (!remoteInfo.success || !remoteInfo.data) {
			logObject('Push step 1 failed', remoteInfo, 'error');
			throw new Error(remoteInfo.error || 'Failed to get remote site info');
		}
		log('Push step 1 OK — remote: ' + remoteInfo.data.url, 'success');

		// Step 2: Initiate migration on remote (creates remote backup)
		showProgress(20, 'Initiating migration on remote site...');
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

		// Step 3: Export and send tables
		const tables = siteInfo.tables || [];
		const totalTables = tables.length;
		let completedTables = 0;
		log('Push step 3: syncing ' + totalTables + ' local tables', 'info');

		for (const table of tables) {
			log('Push table ' + (completedTables + 1) + '/' + totalTables + ': ' + table, 'info');
			let offset = 0;
			let hasMore = true;

			while (hasMore) {
				showProgress(
					30 + (completedTables / totalTables) * 50,
					`Exporting ${table}...`
				);

				// Export chunk locally
				const exportData_req = {
					action: 'sync_export_chunk',
					key: remoteKey,
					table: table,
					offset: offset
				};
				const exportSig = await createSignature(exportData_req, connectionKey);
				exportData_req.nonce = nonce;
				exportData_req.sig = exportSig;

				const exportResult = await fetch(ajaxUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams(exportData_req)
				});
				const exportData = await exportResult.json();

				if (!exportData.success) {
					logObject('Push export failed (' + table + '@' + offset + ')', exportData, 'error');
					throw new Error(exportData.error || 'Export failed');
				}
				const sqlKb = exportData.sql_bytes ? Math.round(exportData.sql_bytes / 1024) : Math.round((exportData.sql || '').length / 1024);
				log('Push exported ' + table + ' offset ' + offset + ' — rows: ' + exportData.rows_exported + '/' + exportData.total_rows + ', ~' + sqlKb + 'KB', 'info');

				// Import chunk on remote
				showProgress(
					30 + (completedTables / totalTables) * 50,
					`Sending ${table} to remote... (${offset + exportData.rows_exported} / ${exportData.total_rows}, ~${sqlKb}KB)`
				);

				if (sqlKb > 400) {
					log('Large chunk upload in progress for ' + table + ' — this may take a minute', 'warn');
				}

				const importResult = await proxyRemoteImport({
					remote_url: remoteUrl,
					key: remoteKey,
					sql: exportData.sql,
					old_url: siteInfo.url,
					new_url: remoteInfo.data.url,
					old_path: siteInfo.path,
					new_path: remoteInfo.data.path,
					source_prefix: siteInfo.prefix
				});

				if (!importResult.success) {
					logObject('Push remote import failed (' + table + '@' + offset + ')', importResult, 'error');
					throw new Error(importResult.error || 'Import failed');
				}
				log('Push sent ' + table + ' offset ' + offset + ' to remote', 'success');

				hasMore = exportData.has_more;
				offset += exportData.rows_exported || 1000;
			}

			completedTables++;
		}

		// Step 4: Finalize migration on remote
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

	/**
	 * Push import via local PHP proxy (server-to-server, base64 SQL).
	 */
	async function proxyRemoteImport(payload) {
		log('Proxy import via local server → ' + payload.remote_url, 'info');
		logObject('Proxy import payload', payload, 'info');

		const data = {
			action: 'sync_remote_import_chunk',
			remote_url: payload.remote_url,
			key: payload.key,
			sql: payload.sql,
			old_url: payload.old_url,
			new_url: payload.new_url,
			old_path: payload.old_path,
			new_path: payload.new_path,
			source_prefix: payload.source_prefix
		};

		const sig = await createSignature(data, connectionKey);
		data.nonce = nonce;
		data.sig = sig;

		let response;
		try {
			response = await fetch(ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams(data),
				signal: typeof AbortSignal !== 'undefined' && AbortSignal.timeout
					? AbortSignal.timeout(600000)
					: undefined
			});
		} catch (error) {
			log('Proxy import fetch failed: ' + error.message, 'error');
			throw error;
		}

		if (!response.ok) {
			const errorText = await response.text();
			log('Proxy import HTTP ' + response.status + ' — ' + errorText.substring(0, 500), 'error');
			throw new Error('Proxy import failed: ' + response.status);
		}

		const json = await response.json();
		log('Proxy import response success=' + json.success, json.success ? 'success' : 'error');
		if (!json.success) {
			logObject('Proxy import response', json, 'error');
		}
		return json;
	}

	/**
	 * Make remote request
	 */
	async function remoteRequest(url, data, key) {
		log('Remote request → ' + (data.action || 'unknown') + ' @ ' + url, 'info');
		logObject('Remote request payload', data, 'info');

		// Create signature
		data.sig = await createSignature(data, key);

		let response;
		try {
			response = await fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams(data)
			});
		} catch (error) {
			log('Remote fetch failed: ' + error.message + (error.cause ? ' (' + error.cause + ')' : ''), 'error');
			throw error;
		}

		if (!response.ok) {
			const errorText = await response.text();
			log('Remote request HTTP ' + response.status + ' — body length ' + errorText.length, 'error');
			let errorData;
			try {
				errorData = JSON.parse(errorText);
				logObject('Remote error response', errorData, 'error');
			} catch (e) {
				log('Remote non-JSON error: ' + errorText.substring(0, 500), 'error');
				throw new Error(`Request failed: ${response.status} ${response.statusText}`);
			}
			throw new Error(errorData.error || errorData.message || `Request failed: ${response.status}`);
		}

		const json = await response.json();
		log('Remote response ← ' + (data.action || 'unknown') + ' success=' + json.success, json.success ? 'success' : 'warn');
		if (!json.success) {
			logObject('Remote response body', json, 'error');
		}
		return json;
	}

	/**
	 * Create HMAC signature (client-side using Web Crypto API)
	 * This ensures the signature matches exactly what PHP will receive
	 */
	async function createSignature(data, key) {
		// Remove existing signature and nonce (nonce is verified separately, not in signature)
		const cleanData = {...data};
		delete cleanData.sig;
		delete cleanData.nonce;

		// Sort data by key (matching PHP ksort)
		const sortedKeys = Object.keys(cleanData).sort();
		const sortedData = {};
		for (const k of sortedKeys) {
			sortedData[k] = cleanData[k];
		}

		// Create query string using URLSearchParams (matches how data is sent)
		const params = new URLSearchParams(sortedData);
		const queryString = params.toString();

		// Import key for HMAC
		const encoder = new TextEncoder();
		const keyData = encoder.encode(key);
		const cryptoKey = await crypto.subtle.importKey(
			'raw',
			keyData,
			{ name: 'HMAC', hash: 'SHA-256' },
			false,
			['sign']
		);

		// Sign the query string
		const signatureBuffer = await crypto.subtle.sign(
			'HMAC',
			cryptoKey,
			encoder.encode(queryString)
		);

		// Convert to hex string (matching PHP hash_hmac output)
		const signatureArray = Array.from(new Uint8Array(signatureBuffer));
		const signatureHex = signatureArray.map(b => b.toString(16).padStart(2, '0')).join('');

		return signatureHex;
	}

	/**
	 * Show progress
	 */
	function showProgress(percent, text) {
		if (progressSection) {
			progressSection.style.display = 'block';
		}
		if (progressBarFill) {
			progressBarFill.style.width = percent + '%';
			progressBarFill.textContent = Math.round(percent) + '%';
		}
		if (progressText) {
			progressText.textContent = text || '';
		}
	}

	/**
	 * Show error
	 */
	function showError(message) {
		if (!message || message.trim() === '') {
			hideError();
			return;
		}
		if (errorSection) {
			errorSection.style.display = 'block';
		}
		if (errorMessage) {
			errorMessage.textContent = message;
		}
	}

	/**
	 * Hide error
	 */
	function hideError() {
		if (errorSection) {
			errorSection.style.display = 'none';
		}
	}

	/**
	 * Show success
	 */
	function showSuccess(message) {
		if (!message || message.trim() === '') {
			hideSuccess();
			return;
		}
		if (successSection) {
			successSection.style.display = 'block';
		}
		if (successMessage) {
			successMessage.textContent = message;
		}
	}

	/**
	 * Hide success
	 */
	function hideSuccess() {
		if (successSection) {
			successSection.style.display = 'none';
		}
	}
})();

