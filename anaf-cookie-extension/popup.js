// Popup script for ANAF Cookie Helper

document.addEventListener('DOMContentLoaded', function() {
    const syncBtn = document.getElementById('syncBtn');
    const testBtn = document.getElementById('testBtn');
    const viewBtn = document.getElementById('viewBtn');
    const errorBtn = document.getElementById('errorBtn');
    const saveSettingsBtn = document.getElementById('saveSettings');
    const statusDiv = document.getElementById('status');
    const cookieDisplay = document.getElementById('cookieDisplay');
    const errorDetails = document.getElementById('errorDetails');
    const appUrlInput = document.getElementById('appUrl');
    
    // Load saved settings
    loadSettings();
    
    // Load initial status
    updateStatus();
    
    // Event listeners
    syncBtn.addEventListener('click', syncCookies);
    testBtn.addEventListener('click', testConnection);
    viewBtn.addEventListener('click', toggleCookieView);
    errorBtn.addEventListener('click', toggleErrorDetails);
    saveSettingsBtn.addEventListener('click', saveSettings);
    
    // Auto-update status every 5 seconds
    setInterval(updateStatus, 5000);
    
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
        
        const appUrl = appUrlInput.value.trim() || 'https://u-core.test';
        
        // Test connection to the app
        fetch(`${appUrl}/api/anaf/session/status`)
            .then(response => {
                testBtn.disabled = false;
                testBtn.textContent = '🧪 Test Connection';
                
                if (response.ok) {
                    return response.json().then(data => {
                        showStatus(`✅ Connection successful! Session active: ${data.session?.active ? 'Yes' : 'No'}`, 'success');
                    });
                } else {
                    showStatus(`❌ Connection failed: HTTP ${response.status} - ${response.statusText}`, 'error');
                }
            })
            .catch(error => {
                testBtn.disabled = false;
                testBtn.textContent = '🧪 Test Connection';
                
                let errorMessage = error.message;
                if (error.message.includes('net::ERR_CERT_AUTHORITY_INVALID')) {
                    errorMessage = 'SSL Certificate not accepted. Please visit ' + appUrl + ' first and accept the certificate.';
                } else if (error.message.includes('Failed to fetch')) {
                    errorMessage = 'Cannot connect to app. Check if the Laravel app is running and the URL is correct.';
                }
                
                showStatus(`❌ Connection error: ${errorMessage}`, 'error');
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
    
    function updateStatus() {
        chrome.storage.local.get([
            'lastSync', 
            'lastSyncStatus', 
            'lastError', 
            'lastSyncMessage', 
            'cookieCount', 
            'errorCode',
            'errorDetails'
        ], function(result) {
            if (result.lastSync) {
                const lastSyncTime = new Date(result.lastSync).toLocaleTimeString();
                
                if (result.lastSyncStatus === 'success') {
                    const cookieInfo = result.cookieCount ? ` (${result.cookieCount} cookies)` : '';
                    const message = result.lastSyncMessage || 'Cookies synced successfully';
                    showStatus(`✅ ${lastSyncTime}: ${message}${cookieInfo}`, 'success');
                } else if (result.lastSyncStatus === 'error') {
                    let errorMessage = result.lastError || 'Unknown error';
                    
                    // Add error code if available
                    if (result.errorCode) {
                        errorMessage = `[${result.errorCode}] ${errorMessage}`;
                    }
                    
                    showStatus(`❌ ${lastSyncTime}: ${errorMessage}`, 'error');
                    
                    // Show error details button if we have detailed error info
                    if (result.errorDetails || result.lastError) {
                        errorBtn.style.display = 'block';
                        // Store error data for the details view
                        window.lastErrorData = {
                            error: result.lastError,
                            code: result.errorCode,
                            details: result.errorDetails,
                            timestamp: lastSyncTime
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
                showStatus('⚠️ No sync performed yet. Click "Sync Cookies" to start.', 'warning');
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