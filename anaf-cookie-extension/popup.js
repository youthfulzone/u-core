// Popup script for ANAF Cookie Helper

document.addEventListener('DOMContentLoaded', function() {
    const syncBtn = document.getElementById('syncBtn');
    const testBtn = document.getElementById('testBtn');
    const viewBtn = document.getElementById('viewBtn');
    const clearBtn = document.getElementById('clearBtn');
    const errorBtn = document.getElementById('errorBtn');
    const saveSettingsBtn = document.getElementById('saveSettings');
    const statusDiv = document.getElementById('status');
    const cookieDisplay = document.getElementById('cookieDisplay');
    const errorDetails = document.getElementById('errorDetails');
    const appUrlInput = document.getElementById('appUrl');
    
    // Check if u-core.test is open before initializing
    checkUCoreTestAvailability();
    
    // Load saved settings
    loadSettings();
    
    // Load initial status
    updateStatus();
    updateConnectionStatus();
    
    // Event listeners
    syncBtn.addEventListener('click', syncCookies);
    testBtn.addEventListener('click', testConnection);
    viewBtn.addEventListener('click', toggleCookieView);
    clearBtn.addEventListener('click', clearCookies);
    errorBtn.addEventListener('click', toggleErrorDetails);
    saveSettingsBtn.addEventListener('click', saveSettings);
    
    // Auto-update status every 5 seconds
    setInterval(updateStatus, 5000);
    
    // Auto-check u-core.test availability every 3 seconds
    setInterval(checkUCoreTestAvailability, 3000);
    
    function checkUCoreTestAvailability() {
        chrome.tabs.query({}, function(tabs) {
            const isUCoreOpen = tabs.some(tab => 
                tab.url && tab.url.includes('u-core.test')
            );
            
            updateUIBasedOnUCoreAvailability(isUCoreOpen);
        });
    }
    
    function updateUIBasedOnUCoreAvailability(isUCoreOpen) {
        if (!isUCoreOpen) {
            // Disable all action buttons
            syncBtn.disabled = true;
            testBtn.disabled = true;
            viewBtn.disabled = true;
            clearBtn.disabled = true;
            
            // Update button text to show requirement
            syncBtn.textContent = '❌ Open u-core.test first';
            testBtn.textContent = '❌ Open u-core.test first';
            viewBtn.textContent = '❌ Open u-core.test first';
            clearBtn.textContent = '❌ Open u-core.test first';
            
            // Show clear status message
            showStatus('⚠️ u-core.test not open - please open u-core.test in your browser to use this extension', 'warning');
        } else {
            // Re-enable all action buttons
            syncBtn.disabled = false;
            testBtn.disabled = false;
            viewBtn.disabled = false;
            clearBtn.disabled = false;
            
            // Restore normal button text
            syncBtn.textContent = '🔄 Sync Cookies to App';
            testBtn.textContent = '🧪 Test Connection';
            viewBtn.textContent = '👁️ View Current Cookies';
            clearBtn.textContent = '🗑️ Clear ANAF Cookies';
            
            // Clear the warning if it was showing
            if (statusDiv.textContent.includes('u-core.test not open')) {
                showStatus('✅ u-core.test detected - extension ready to use', 'success');
            }
        }
    }
    
    function loadSettings() {
        chrome.storage.sync.get(['appUrl'], function(result) {
            if (result.appUrl) {
                appUrlInput.value = result.appUrl;
            }
        });
    }
    
    function saveSettings() {
        const appUrl = appUrlInput.value.trim();
        
        chrome.storage.sync.set({appUrl: appUrl}, function() {
            showStatus('Settings saved successfully', 'success');
        });
    }
    
    function testConnection() {
        testBtn.disabled = true;
        testBtn.textContent = '🧪 Testing...';
        
        // Use background script's test connection method for consistency
        chrome.runtime.sendMessage({action: 'testConnection'}, function(response) {
            testBtn.disabled = false;
            testBtn.textContent = '🧪 Test Connection';
            
            if (response && response.success) {
                // Show detailed success message
                let successMessage = response.message;
                if (response.method) {
                    successMessage += ` (via ${response.method})`;
                }
                showStatus(successMessage, 'success');
                
                // Store successful connection test
                chrome.storage.local.set({
                    lastConnectionTest: Date.now(),
                    lastConnectionStatus: 'success',
                    lastConnectionMessage: response.message,
                    connectionMethod: response.method || 'HTTPS'
                });
            } else {
                const errorMessage = response?.message || response?.error || 'Connection test failed';
                const isSSLError = response?.errorType === 'SSL_CERTIFICATE_REQUIRED' || 
                                 errorMessage.includes('SSL') || 
                                 errorMessage.includes('certificate') || 
                                 errorMessage.includes('Failed to fetch') || 
                                 errorMessage.includes('Network Connection Failed');
                
                if (isSSLError) {
                    showStatus(`⚠️ SSL Certificate Required: ${errorMessage}`, 'warning');
                    
                    // Store SSL error status
                    chrome.storage.local.set({
                        lastConnectionTest: Date.now(),
                        lastConnectionStatus: 'ssl_error',
                        lastConnectionMessage: errorMessage
                    });
                } else {
                    showStatus(`❌ Connection failed: ${errorMessage}`, 'error');
                    
                    // Store general error status
                    chrome.storage.local.set({
                        lastConnectionTest: Date.now(),
                        lastConnectionStatus: 'error',
                        lastConnectionMessage: errorMessage
                    });
                }
            }
            
            updateConnectionStatus();
        });
    }
    
    function syncCookies() {
        syncBtn.disabled = true;
        syncBtn.textContent = '🔄 Syncing...';
        
        // Send message to background script to sync cookies
        chrome.runtime.sendMessage({action: 'syncCookies'}, function(response) {
            syncBtn.disabled = false;
            syncBtn.textContent = '🔄 Sync Cookies to App';
            
            if (response && response.success) {
                showStatus('✅ Cookies synced successfully!', 'success');
            } else {
                showStatus('❌ Failed to sync cookies: ' + (response?.error || 'Unknown error'), 'error');
            }
            
            updateStatus();
        });
    }
    
    function clearCookies() {
        // Show confirmation dialog
        const confirmMessage = 'Are you sure you want to clear all ANAF cookies?\n\n' +
                              'This will:\n' +
                              '• Remove all webserviced.anaf.ro cookies\n' +
                              '• Clear extension sync history\n' +
                              '• Require re-authentication at ANAF\n\n' +
                              'Click OK to proceed or Cancel to abort.';
        
        if (!confirm(confirmMessage)) {
            return; // User cancelled
        }
        
        clearBtn.disabled = true;
        clearBtn.textContent = '🗑️ Clearing...';
        
        // Send message to background script to clear cookies
        chrome.runtime.sendMessage({action: 'clearCookies'}, function(response) {
            clearBtn.disabled = false;
            clearBtn.textContent = '🗑️ Clear ANAF Cookies';
            
            if (response && response.success) {
                const message = response.message || 'ANAF cookies cleared successfully!';
                const detailMessage = response.clearedCount ? 
                    `${message} (${response.clearedCount} cookies removed)` : 
                    message;
                
                showStatus(`✅ ${detailMessage}`, 'success');
                
                // Hide cookie display if it was open since cookies are now cleared
                if (cookieDisplay.style.display !== 'none') {
                    cookieDisplay.style.display = 'none';
                    viewBtn.textContent = '👁️ View Current Cookies';
                }
                
                // Store successful clear operation
                chrome.storage.local.set({
                    lastClear: Date.now(),
                    lastClearStatus: 'success',
                    lastClearMessage: detailMessage,
                    clearedCookieCount: response.clearedCount || 0
                });
                
            } else {
                const errorMessage = response?.message || response?.error || 'Unknown error';
                showStatus(`❌ Failed to clear cookies: ${errorMessage}`, 'error');
                
                // Store failed clear operation
                chrome.storage.local.set({
                    lastClear: Date.now(),
                    lastClearStatus: 'error',
                    lastClearError: errorMessage
                });
            }
            
            updateStatus();
        });
    }
    
    function toggleCookieView() {
        if (cookieDisplay.style.display === 'none') {
            viewBtn.textContent = '🔄 Loading cookies...';
            viewBtn.disabled = true;
            
            // Get cookies from background script
            chrome.runtime.sendMessage({action: 'getCookies'}, function(response) {
                viewBtn.disabled = false;
                
                if (response && response.cookies) {
                    displayCookies(response.cookies);
                    cookieDisplay.style.display = 'block';
                    viewBtn.textContent = '🙈 Hide Cookies';
                } else {
                    showStatus('❌ Failed to get cookies: ' + (response?.error || 'Unknown error'), 'error');
                    viewBtn.textContent = '👁️ View Current Cookies';
                }
            });
        } else {
            cookieDisplay.style.display = 'none';
            viewBtn.textContent = '👁️ View Current Cookies';
        }
    }
    
    function displayCookies(cookies) {
        if (cookies.length === 0) {
            cookieDisplay.innerHTML = '<div class="cookie-item">No webserviced.anaf.ro session cookies found. Please visit https://webserviced.anaf.ro and authenticate.</div>';
            return;
        }
        
        let html = '';
        cookies.forEach(cookie => {
            const isImportant = ['MRHSession', 'F5_ST', 'LastMRH_Session'].includes(cookie.name);
            const style = isImportant ? 'font-weight: bold; color: #007bff;' : '';
            
            html += `
                <div class="cookie-item" style="${style}">
                    <strong>${cookie.name}:</strong> ${cookie.value.substring(0, 30)}${cookie.value.length > 30 ? '...' : ''}<br>
                    <small>Domain: ${cookie.domain} | Secure: ${cookie.secure ? 'Yes' : 'No'}</small>
                </div>
            `;
        });
        
        cookieDisplay.innerHTML = html;
    }
    
    function updateConnectionStatus() {
        chrome.storage.local.get([
            'lastConnectionTest',
            'lastConnectionStatus', 
            'lastConnectionMessage'
        ], function(result) {
            const healthElement = document.getElementById('connectionHealth');
            
            if (result.lastConnectionTest) {
                const testTime = new Date(result.lastConnectionTest).toLocaleTimeString();
                let status, color;
                
                if (result.lastConnectionStatus === 'success') {
                    status = '✅ healthy';
                    color = '#28a745';
                } else if (result.lastConnectionStatus === 'ssl_error') {
                    status = '⚠️ needs SSL cert';
                    color = '#ffc107';
                } else {
                    status = '❌ error';
                    color = '#dc3545';
                }
                
                healthElement.textContent = `Connection: ${status} (${testTime})`;
                healthElement.style.color = color;
            } else {
                // Auto-test connection on popup load if never tested
                healthElement.textContent = 'Connection: 🔄 testing...';
                healthElement.style.color = '#6c757d';
                
                chrome.runtime.sendMessage({action: 'testConnection'}, function(response) {
                    if (response && response.success) {
                        healthElement.textContent = 'Connection: ✅ healthy';
                        healthElement.style.color = '#28a745';
                        
                        // Store successful connection test
                        chrome.storage.local.set({
                            lastConnectionTest: Date.now(),
                            lastConnectionStatus: 'success',
                            lastConnectionMessage: response.message
                        });
                    } else {
                        const isSSLError = response?.errorType === 'SSL_CERTIFICATE_REQUIRED' || 
                                         response?.message?.includes('SSL') || 
                                         response?.message?.includes('certificate');
                        
                        if (isSSLError) {
                            healthElement.textContent = 'Connection: ⚠️ needs SSL cert';
                            healthElement.style.color = '#ffc107';
                            
                            // Store SSL error status
                            chrome.storage.local.set({
                                lastConnectionTest: Date.now(),
                                lastConnectionStatus: 'ssl_error',
                                lastConnectionMessage: response?.message || 'SSL certificate needs acceptance'
                            });
                        } else {
                            healthElement.textContent = 'Connection: ❌ error';
                            healthElement.style.color = '#dc3545';
                            
                            // Store general error status
                            chrome.storage.local.set({
                                lastConnectionTest: Date.now(),
                                lastConnectionStatus: 'error',
                                lastConnectionMessage: response?.message || 'Connection failed'
                            });
                        }
                    }
                });
            }
        });
    }

    function updateStatus() {
        chrome.storage.local.get([
            'lastSync', 
            'lastSyncStatus', 
            'lastError', 
            'lastSyncMessage', 
            'cookieCount', 
            'errorCode',
            'errorDetails',
            'lastConnectionTest',
            'lastConnectionStatus',
            'lastConnectionMessage',
            'lastClear',
            'lastClearStatus',
            'lastClearMessage',
            'lastClearError',
            'clearedCookieCount',
            'trigger',
            'retryCount',
            'syncDuration',
            'serverResponse'
        ], function(result) {
            // Determine which operation was most recent
            const lastSyncTime = result.lastSync || 0;
            const lastClearTime = result.lastClear || 0;
            
            // Show the most recent operation's status
            if (lastClearTime > lastSyncTime && result.lastClear) {
                // Show clear operation status
                const clearTime = new Date(result.lastClear).toLocaleTimeString();
                
                if (result.lastClearStatus === 'success') {
                    const clearInfo = result.clearedCookieCount ? ` (${result.clearedCookieCount} cookies removed)` : '';
                    const message = result.lastClearMessage || 'ANAF cookies cleared successfully';
                    showStatus(`🗑️ ${clearTime}: ${message}${clearInfo}`, 'success');
                    errorBtn.style.display = 'none';
                } else if (result.lastClearStatus === 'error') {
                    const errorMessage = result.lastClearError || 'Failed to clear cookies';
                    showStatus(`❌ ${clearTime}: ${errorMessage}`, 'error');
                    errorBtn.style.display = 'none'; // Clear errors are usually simpler
                }
                
            } else if (result.lastSync) {
                // Show sync operation status
                const syncTime = new Date(result.lastSync).toLocaleTimeString();
                
                if (result.lastSyncStatus === 'success') {
                    const cookieInfo = result.cookieCount ? ` (${result.cookieCount} cookies)` : '';
                    const triggerInfo = result.trigger ? ` via ${result.trigger}` : '';
                    const retryInfo = result.retryCount > 0 ? ` (${result.retryCount} retries)` : '';
                    const durationInfo = result.syncDuration ? ` in ${result.syncDuration}ms` : '';
                    const message = result.lastSyncMessage || 'Cookies synced successfully';
                    showStatus(`✅ ${syncTime}: ${message}${cookieInfo}${triggerInfo}${retryInfo}${durationInfo}`, 'success');
                    errorBtn.style.display = 'none';
                } else if (result.lastSyncStatus === 'error') {
                    let errorMessage = result.lastError || 'Unknown error';
                    
                    // Add error code if available
                    if (result.errorCode) {
                        errorMessage = `[${result.errorCode}] ${errorMessage}`;
                    }
                    
                    showStatus(`❌ ${syncTime}: ${errorMessage}`, 'error');
                    
                    // Show error details button if we have detailed error info
                    if (result.errorDetails || result.lastError) {
                        errorBtn.style.display = 'block';
                        // Store error data for the details view
                        window.lastErrorData = {
                            error: result.lastError,
                            code: result.errorCode,
                            details: result.errorDetails,
                            timestamp: syncTime
                        };
                    } else {
                        errorBtn.style.display = 'none';
                    }
                    
                    // Log detailed error info to console for debugging
                    if (result.errorDetails) {
                        console.error('Detailed sync error:', result.errorDetails);
                    }
                }
            } else {
                // No operations performed yet
                showStatus('⚠️ No operations performed yet. Click "Sync Cookies" to start.', 'warning');
                errorBtn.style.display = 'none';
            }
        });
    }
    
    function toggleErrorDetails() {
        if (errorDetails.style.display === 'none') {
            // Show error details
            if (window.lastErrorData) {
                displayErrorDetails(window.lastErrorData);
                errorDetails.style.display = 'block';
                errorBtn.textContent = '🙈 Hide Error Details';
            }
        } else {
            // Hide error details
            errorDetails.style.display = 'none';
            errorBtn.textContent = '🔍 Show Error Details';
        }
    }
    
    function displayErrorDetails(errorData) {
        let html = `<div class="cookie-item">
            <strong>Error Timestamp:</strong> ${errorData.timestamp}<br>
            <strong>Error Message:</strong> ${errorData.error || 'Unknown error'}
        </div>`;
        
        if (errorData.code) {
            html += `<div class="cookie-item">
                <strong>HTTP Status Code:</strong> ${errorData.code}
            </div>`;
        }
        
        if (errorData.details) {
            html += `<div class="cookie-item">
                <strong>Error Type:</strong> ${errorData.details.type || 'Unknown'}<br>
                <strong>Detailed Message:</strong> ${errorData.details.message || 'No details available'}
            </div>`;
            
            if (errorData.details.stack) {
                html += `<div class="cookie-item">
                    <strong>Stack Trace:</strong><br>
                    <small style="font-family: monospace; white-space: pre-wrap;">${errorData.details.stack}</small>
                </div>`;
            }
        }
        
        html += `<div class="cookie-item" style="background-color: #fff3cd; border: 1px solid #ffeaa7;">
            <strong>Troubleshooting Tips:</strong><br>
            • Check that the app URL is correct in settings<br>
            • Ensure the Laravel/Flask app is running<br>
            • Verify network connectivity<br>
            • Check browser console for additional errors
        </div>`;
        
        errorDetails.innerHTML = html;
    }
    
    function showStatus(message, type) {
        statusDiv.textContent = message;
        statusDiv.className = `status ${type}`;
    }
});