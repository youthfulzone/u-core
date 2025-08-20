// Background service worker for ANAF Cookie Helper
// Enhanced version with improved error handling and retry logic

let syncInProgress = false;
let lastSyncAttempt = 0;
const MIN_SYNC_INTERVAL = 5000; // Minimum 5 seconds between sync attempts
const MAX_RETRY_ATTEMPTS = 3;
const RETRY_DELAY_BASE = 2000; // Base delay for exponential backoff

// Enhanced cookie change monitoring
chrome.cookies.onChanged.addListener((changeInfo) => {
  const cookie = changeInfo.cookie;
  
  // Check if it's specifically a webserviced.anaf.ro cookie
  if (cookie.domain === 'webserviced.anaf.ro' || cookie.domain === '.webserviced.anaf.ro') {
    console.log('[ANAF Extension] Cookie changed:', cookie.name, cookie.domain, 
                changeInfo.removed ? '(removed)' : '(added/updated)');
    
    // Only sync on cookie additions/updates, not removals
    if (!changeInfo.removed) {
      // If it's a key ANAF session cookie, auto-sync with rate limiting
      const keyCookies = ['MRHSession', 'F5_ST', 'LastMRH_Session', 'JSESSIONID'];
      if (keyCookies.includes(cookie.name)) {
        console.log('[ANAF Extension] Key cookie detected, scheduling auto-sync...');
        scheduleSync('cookie_change', cookie.name);
      }
    }
  }
});

// Enhanced page load monitoring
chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.status === 'complete' && tab.url && tab.url.startsWith('https://webserviced.anaf.ro/')) {
    console.log('[ANAF Extension] ANAF page loaded, scheduling cookie check...');
    // Wait for page to fully load and cookies to be set
    setTimeout(() => {
      scheduleSync('page_load', tab.url);
    }, 3000);
  }
});

// Smart sync scheduling with rate limiting
function scheduleSync(trigger, detail) {
  const now = Date.now();
  
  // Rate limiting: don't sync too frequently
  if (now - lastSyncAttempt < MIN_SYNC_INTERVAL) {
    console.log('[ANAF Extension] Sync rate limited, skipping:', trigger);
    return;
  }
  
  // Prevent concurrent syncs
  if (syncInProgress) {
    console.log('[ANAF Extension] Sync already in progress, skipping:', trigger);
    return;
  }
  
  console.log('[ANAF Extension] Scheduled sync triggered by:', trigger, detail);
  syncCookiesToApp(trigger);
}

// Enhanced function to sync cookies to the Laravel app with retry logic
async function syncCookiesToApp(trigger = 'manual', retryCount = 0) {
  // Set sync in progress flag
  syncInProgress = true;
  lastSyncAttempt = Date.now();
  
  try {
    console.log(`[ANAF Extension] Starting sync attempt ${retryCount + 1}/${MAX_RETRY_ATTEMPTS + 1} (trigger: ${trigger})`);
    
    // Get ONLY webserviced.anaf.ro cookies
    const webservicedCookies = await chrome.cookies.getAll({domain: "webserviced.anaf.ro"});
    
    if (webservicedCookies.length === 0) {
      console.log('[ANAF Extension] No webserviced.anaf.ro cookies found');
      await updateSyncStatus('no_cookies', 'No ANAF cookies found');
      return;
    }
    
    // Enhanced cookie filtering with better logic
    const relevantCookies = webservicedCookies.filter(cookie => {
      // Include session cookies (no expiration date) and key ANAF cookies
      const isSessionCookie = cookie.session;
      const isKeyCookie = ['MRHSession', 'F5_ST', 'LastMRH_Session', 'JSESSIONID'].includes(cookie.name);
      const isRecentCookie = !cookie.expirationDate || (cookie.expirationDate * 1000) > Date.now();
      
      return (isSessionCookie || isKeyCookie) && isRecentCookie;
    });
    
    if (relevantCookies.length === 0) {
      console.log('[ANAF Extension] No relevant/valid ANAF cookies found');
      await updateSyncStatus('invalid_cookies', 'No valid ANAF session cookies found');
      return;
    }
    
    // Format cookies as string (like browser sends them)
    const cookieString = relevantCookies
      .map(cookie => `${cookie.name}=${cookie.value}`)
      .join('; ');
    
    console.log(`[ANAF Extension] Syncing ${relevantCookies.length} cookies to app:`, 
                relevantCookies.map(c => c.name).join(', '));
    
    // Get app URL from storage or use default
    const storage = await chrome.storage.sync.get(['appUrl']);
    const appUrl = storage.appUrl || 'https://u-core.test';
    
    // Enhanced request with timeout and better headers
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000); // 15 second timeout
    
    console.log(`[ANAF Extension] Sending to: ${appUrl}/api/anaf/extension-cookies`);
    
    const requestData = {
      cookies: cookieString,
      timestamp: Date.now(),
      source: 'browser_extension_enhanced',
      trigger: trigger,
      cookie_count: relevantCookies.length,
      user_agent: navigator.userAgent,
      extension_version: '1.0.5'
    };
    
    const response = await fetch(`${appUrl}/api/anaf/extension-cookies`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Extension-Version': '1.0.5',
        'X-Sync-Trigger': trigger
      },
      body: JSON.stringify(requestData),
      signal: controller.signal
    });
    
    clearTimeout(timeoutId);
    
    if (response.ok) {
      const data = await response.json();
      console.log(`[ANAF Extension] ✅ Sync successful:`, data);
      
      // Store enhanced success details
      await updateSyncStatus('success', data.message || 'Cookies synced successfully', {
        cookieCount: data.cookie_count || relevantCookies.length,
        trigger: trigger,
        retryCount: retryCount,
        syncDuration: Date.now() - lastSyncAttempt,
        serverResponse: data
      });
      
      // Clear any previous error states
      await chrome.storage.local.remove(['lastError', 'errorCode', 'errorDetails']);
      
    } else {
      // Enhanced error handling with retry logic
      let errorDetails = `HTTP ${response.status} - ${response.statusText}`;
      let serverErrorData = null;
      
      try {
        serverErrorData = await response.json();
        errorDetails = serverErrorData.message || errorDetails;
        console.error('[ANAF Extension] Server error response:', serverErrorData);
      } catch (parseError) {
        try {
          const errorText = await response.text();
          errorDetails = errorText || errorDetails;
          console.error('[ANAF Extension] Server text response:', errorText);
        } catch (textError) {
          console.error('[ANAF Extension] No response body available');
        }
      }
      
      const shouldRetry = shouldRetryRequest(response.status, retryCount);
      
      if (shouldRetry) {
        console.warn(`[ANAF Extension] ⚠️ Sync failed (${response.status}), retrying in ${calculateRetryDelay(retryCount)}ms...`);
        
        // Wait before retry with exponential backoff
        setTimeout(() => {
          syncCookiesToApp(trigger, retryCount + 1);
        }, calculateRetryDelay(retryCount));
        
        // Don't update error status yet, we're retrying
        return;
      } else {
        console.error(`[ANAF Extension] ❌ Sync failed permanently:`, response.status, errorDetails);
        await updateSyncStatus('error', errorDetails, {
          errorCode: response.status,
          retryCount: retryCount,
          serverError: serverErrorData,
          trigger: trigger
        });
      }
    }
    
  } catch (error) {
    console.error('[ANAF Extension] ❌ Network/runtime error:', error);
    
    const errorMessage = error.message || 'Unknown network error';
    const errorType = error.name || 'NetworkError';
    const isNetworkError = error.name === 'AbortError' || error.message.includes('fetch');
    
    // Retry on network errors
    if (isNetworkError && retryCount < MAX_RETRY_ATTEMPTS) {
      console.warn(`[ANAF Extension] ⚠️ Network error, retrying in ${calculateRetryDelay(retryCount)}ms...`);
      
      setTimeout(() => {
        syncCookiesToApp(trigger, retryCount + 1);
      }, calculateRetryDelay(retryCount));
      
      return;
    }
    
    // Permanent failure
    await updateSyncStatus('error', `${errorType}: ${errorMessage}`, {
      errorType: errorType,
      errorMessage: errorMessage,
      stack: error.stack,
      retryCount: retryCount,
      trigger: trigger,
      isNetworkError: isNetworkError
    });
    
  } finally {
    // Always clear the sync in progress flag
    syncInProgress = false;
  }
}

// Enhanced status update function
async function updateSyncStatus(status, message, details = {}) {
  const statusData = {
    lastSync: Date.now(),
    lastSyncStatus: status,
    lastSyncMessage: message,
    ...details
  };
  
  if (status === 'error') {
    statusData.lastError = message;
    if (details.errorCode) statusData.errorCode = details.errorCode;
    if (details.errorType || details.serverError || details.stack) {
      statusData.errorDetails = {
        type: details.errorType,
        message: details.errorMessage,
        stack: details.stack,
        serverError: details.serverError,
        retryCount: details.retryCount,
        trigger: details.trigger
      };
    }
  }
  
  await chrome.storage.local.set(statusData);
  console.log(`[ANAF Extension] Status updated:`, status, message);
}

// Determine if we should retry based on error type and retry count
function shouldRetryRequest(statusCode, retryCount) {
  if (retryCount >= MAX_RETRY_ATTEMPTS) {
    return false;
  }
  
  // Retry on temporary server errors
  const retryableStatuses = [500, 502, 503, 504, 408, 429];
  return retryableStatuses.includes(statusCode);
}

// Calculate retry delay with exponential backoff
function calculateRetryDelay(retryCount) {
  return RETRY_DELAY_BASE * Math.pow(2, retryCount);
}

// Enhanced message handler for popup and content script communication
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  console.log('[ANAF Extension] Message received:', request.action, sender.tab ? 'from tab' : 'from popup');
  
  if (request.action === 'syncCookies') {
    // Manual sync triggered from popup
    syncCookiesToApp('popup_manual').then(() => {
      sendResponse({success: true, message: 'Sync completed successfully'});
    }).catch(error => {
      sendResponse({success: false, error: error.message});
    });
    return true; // Keep message channel open for async response
  }
  
  if (request.action === 'getCookies') {
    // Handle requests from content script for cookies
    if (request.domain === 'webserviced.anaf.ro') {
      getAllANAFCookies().then(cookies => {
        // Convert array to object format for easier use
        const cookieObj = {};
        cookies.forEach(cookie => {
          cookieObj[cookie.name] = cookie.value;
        });
        sendResponse({success: true, cookies: cookieObj});
      }).catch(error => {
        sendResponse({success: false, error: error.message});
      });
    } else {
      // Legacy format for popup
      getAllANAFCookies().then(cookies => {
        sendResponse({cookies: cookies});
      }).catch(error => {
        sendResponse({error: error.message});
      });
    }
    return true;
  }
  
  if (request.action === 'testConnection') {
    testConnection().then(result => {
      sendResponse(result);
    }).catch(error => {
      sendResponse({success: false, error: error.message});
    });
    return true;
  }
  
  if (request.action === 'manualSync') {
    syncCookiesToApp().then(() => {
      sendResponse({success: true, message: 'Manual sync completed successfully'});
    }).catch(error => {
      sendResponse({success: false, error: error.message});
    });
    return true;
  }
  
  if (request.action === 'clearCookies') {
    clearANAFCookies().then((result) => {
      sendResponse(result);
    }).catch(error => {
      sendResponse({success: false, error: error.message});
    });
    return true;
  }
});

// Test connection to the Laravel app
async function testConnection() {
  try {
    const result = await chrome.storage.sync.get(['appUrl']);
    let appUrl = result.appUrl || 'https://u-core.test';
    
    console.log('Testing connection to:', `${appUrl}/api/anaf/session/status`);
    
    // Try HTTPS first with proper timeout and error handling
    let response;
    let method = 'HTTPS';
    
    try {
      // Set a reasonable timeout for the HTTPS request
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 5000);
      
      response = await fetch(`${appUrl}/api/anaf/session/status`, {
        signal: controller.signal,
        method: 'GET'
      });
      
      clearTimeout(timeoutId);
      
      // If we get here, HTTPS worked
      method = 'HTTPS';
      
    } catch (httpsError) {
      console.log('HTTPS failed:', httpsError.message);
      console.log('Trying HTTP fallback...');
      
      // If HTTPS fails, try HTTP as fallback
      const httpUrl = appUrl.replace('https://', 'http://');
      console.log('Trying HTTP fallback:', `${httpUrl}/api/anaf/session/status`);
      
      try {
        const httpController = new AbortController();
        const httpTimeoutId = setTimeout(() => httpController.abort(), 5000);
        
        response = await fetch(`${httpUrl}/api/anaf/session/status`, {
          signal: httpController.signal,
          method: 'GET',
          redirect: 'follow' // Follow redirects automatically
        });
        
        clearTimeout(httpTimeoutId);
        appUrl = httpUrl; // Update appUrl for success message
        method = 'HTTP (fallback)';
        
      } catch (httpError) {
        console.log('Both HTTPS and HTTP failed');
        
        // If both fail, show a helpful message about SSL certificate acceptance
        return {
          success: false,
          message: 'Connection failed: SSL certificate needs acceptance. Please visit https://u-core.test in your browser and accept the certificate warning. The extension auto-sync functionality works independently of this popup test.',
          errorType: 'SSL_CERTIFICATE_REQUIRED',
          troubleshooting: [
            'Visit https://u-core.test in your browser',
            'Accept the SSL certificate warning when prompted',
            'Return to this popup and test connection again',
            'Note: Extension auto-sync works on the SPV page regardless of this popup test result'
          ]
        };
      }
    }
    
    if (response.ok) {
      const data = await response.json();
      console.log('Connection test successful via', method, ':', data);
      
      return {
        success: true, 
        message: `✅ Connection successful via ${method}! Session active: ${data.session?.active ? 'Yes' : 'No'}`,
        method: method,
        data: data
      };
    } else {
      console.error('Connection test failed:', response.status, response.statusText);
      return {
        success: false, 
        message: `Connection failed: HTTP ${response.status} - ${response.statusText}`,
        method: method
      };
    }
  } catch (error) {
    console.error('Connection test error:', error);
    
    let errorMessage = error.message;
    let troubleshooting = '';
    
    if (error.name === 'AbortError') {
      errorMessage = 'Connection Timeout';
      troubleshooting = 'The connection attempt timed out. Check if the Laravel app is running and accessible.';
    } else if (error.message.includes('net::ERR_CERT_AUTHORITY_INVALID')) {
      errorMessage = 'SSL Certificate Error';
      troubleshooting = 'Please visit https://u-core.test in your browser and accept the SSL certificate warning first.';
    } else if (error.message.includes('net::ERR_CERT_COMMON_NAME_INVALID')) {
      errorMessage = 'SSL Certificate Name Mismatch';
      troubleshooting = 'The SSL certificate does not match the domain. Try using localhost or 127.0.0.1 instead.';
    } else if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
      errorMessage = 'Network Connection Failed';
      troubleshooting = 'Check if the Laravel app is running and accessible. Try visiting the app URL directly first.';
    } else if (error.message.includes('ERR_CONNECTION_REFUSED')) {
      errorMessage = 'Connection Refused';
      troubleshooting = 'The Laravel app appears to be offline. Check if it\'s running on the correct port.';
    }
    
    const fullMessage = troubleshooting ? `${errorMessage}: ${troubleshooting}` : errorMessage;
    
    return {
      success: false, 
      message: fullMessage,
      errorType: error.name,
      errorCode: error.code || 'unknown'
    };
  }
}

// Helper function to get ONLY webserviced.anaf.ro cookies
async function getAllANAFCookies() {
  try {
    const webservicedCookies = await chrome.cookies.getAll({domain: "webserviced.anaf.ro"});
    
    // Filter to only relevant cookies
    const relevantCookies = webservicedCookies.filter(cookie => {
      const isSessionCookie = cookie.session;
      const isKeyCookie = ['MRHSession', 'F5_ST', 'LastMRH_Session'].includes(cookie.name);
      const isAnalyticsCookie = cookie.name.startsWith('_ga') || cookie.name.startsWith('AMP_');
      
      return isSessionCookie || isKeyCookie || isAnalyticsCookie;
    });
    
    return relevantCookies.map(cookie => ({
      name: cookie.name,
      value: cookie.value,
      domain: cookie.domain,
      path: cookie.path,
      secure: cookie.secure,
      httpOnly: cookie.httpOnly,
      session: cookie.session
    }));
  } catch (error) {
    console.error('Error getting cookies:', error);
    return [];
  }
}

// Function to clear ANAF cookies
async function clearANAFCookies() {
  try {
    console.log('Starting to clear ANAF cookies...');
    
    // Get all cookies for webserviced.anaf.ro domain
    const webservicedCookies = await chrome.cookies.getAll({domain: "webserviced.anaf.ro"});
    const dotWebservicedCookies = await chrome.cookies.getAll({domain: ".webserviced.anaf.ro"});
    
    // Combine both domain variations
    const allCookies = [...webservicedCookies, ...dotWebservicedCookies];
    
    if (allCookies.length === 0) {
      console.log('No ANAF cookies found to clear');
      return {
        success: true,
        message: 'No ANAF cookies found to clear',
        clearedCount: 0
      };
    }
    
    console.log(`Found ${allCookies.length} ANAF cookies to clear`);
    
    // Clear each cookie
    let clearedCount = 0;
    let errorCount = 0;
    const errors = [];
    
    for (const cookie of allCookies) {
      try {
        // Construct the URL for cookie removal
        const protocol = cookie.secure ? 'https:' : 'http:';
        const url = `${protocol}//${cookie.domain}${cookie.path}`;
        
        console.log(`Clearing cookie: ${cookie.name} from ${url}`);
        
        await chrome.cookies.remove({
          url: url,
          name: cookie.name
        });
        
        clearedCount++;
        console.log(`✅ Cleared cookie: ${cookie.name}`);
        
      } catch (error) {
        errorCount++;
        const errorMsg = `Failed to clear cookie ${cookie.name}: ${error.message}`;
        console.error(errorMsg);
        errors.push(errorMsg);
      }
    }
    
    // Clear stored sync status to reset the extension state
    await chrome.storage.local.clear();
    console.log('Cleared extension storage');
    
    // Prepare result message
    let message = `Cleared ${clearedCount} ANAF cookies`;
    if (errorCount > 0) {
      message += ` (${errorCount} errors)`;
    }
    
    const result = {
      success: true,
      message: message,
      clearedCount: clearedCount,
      errorCount: errorCount,
      totalFound: allCookies.length
    };
    
    if (errors.length > 0) {
      result.errors = errors;
    }
    
    console.log('Cookie clearing completed:', result);
    
    // Store the clear operation details
    await chrome.storage.local.set({
      lastClear: Date.now(),
      lastClearStatus: 'success',
      lastClearMessage: message,
      clearedCookieCount: clearedCount
    });
    
    return result;
    
  } catch (error) {
    console.error('Error clearing ANAF cookies:', error);
    
    const errorMessage = `Failed to clear cookies: ${error.message}`;
    
    // Store the error details
    await chrome.storage.local.set({
      lastClear: Date.now(),
      lastClearStatus: 'error',
      lastClearError: errorMessage
    });
    
    return {
      success: false,
      message: errorMessage,
      error: error.message
    };
  }
}