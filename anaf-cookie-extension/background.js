// Background service worker for ANAF Cookie Helper
// Enhanced version with improved error handling, retry logic, and conservative resource usage
// 
// Resource Conservation Strategy:
// - 15 second minimum between sync attempts (prevents excessive API calls)
// - 8 second minimum between status reports (lighter operations)
// - 3 second batching delay (groups rapid cookie changes together)
// - Tab detection (only works when u-core.test is open)
// - Smart retry logic with exponential backoff

let syncInProgress = false;
let lastSyncAttempt = 0;
let lastStatusReport = 0;
let pendingSyncTimeout = null;
let currentIconState = 'grey'; // Track current icon state
const MIN_SYNC_INTERVAL = 15000; // Minimum 15 seconds between cookie syncs (conservative)
const MIN_STATUS_INTERVAL = 8000; // Minimum 8 seconds between status reports 
const BATCH_SYNC_DELAY = 3000; // Wait 3 seconds to batch multiple rapid changes
const MAX_RETRY_ATTEMPTS = 3;
const RETRY_DELAY_BASE = 2000; // 2 second base delay for retries

// Icon paths for different states
const ICONS = {
  blue: {
    16: 'icon16-blue.png',
    48: 'icon48-blue.png', 
    128: 'icon128-blue.png'
  },
  grey: {
    16: 'icon16-grey.png',
    48: 'icon48-grey.png',
    128: 'icon128-grey.png'
  }
};

// Icon management functions
async function updateExtensionIcon(state) {
  console.log(`[ANAF Extension] updateExtensionIcon called with state: ${state}, current: ${currentIconState}`);
  
  if (currentIconState === state) {
    console.log(`[ANAF Extension] Icon already in ${state} state, no change needed`);
    return; // No change needed
  }
  
  try {
    console.log(`[ANAF Extension] Switching icon from ${currentIconState} to ${state}`);
    console.log(`[ANAF Extension] Icon paths:`, ICONS[state]);
    
    await chrome.action.setIcon({
      path: ICONS[state]
    });
    
    currentIconState = state;
    console.log(`[ANAF Extension] ✅ Icon successfully updated to ${state} state`);
    
    // Update tooltip based on state
    const title = state === 'blue' 
      ? 'ANAF Cookie Helper - Ready (u-core.test active)'
      : 'ANAF Cookie Helper - Inactive (u-core.test required)';
    
    await chrome.action.setTitle({ title });
    console.log(`[ANAF Extension] ✅ Tooltip updated: ${title}`);
    
  } catch (error) {
    console.error('[ANAF Extension] ❌ Failed to update icon:', error);
    console.error('[ANAF Extension] Error details:', error.message);
  }
}

// Monitor u-core.test availability and update icon accordingly
async function checkAndUpdateIconState() {
  console.log(`[ANAF Extension] checkAndUpdateIconState called`);
  const isUCoreOpen = await isUCoreTestTabOpen();
  const newState = isUCoreOpen ? 'blue' : 'grey';
  console.log(`[ANAF Extension] u-core.test open: ${isUCoreOpen}, new state: ${newState}`);
  await updateExtensionIcon(newState);
}

// Enhanced cookie change monitoring with u-core.test tab check
chrome.cookies.onChanged.addListener(async (changeInfo) => {
  const cookie = changeInfo.cookie;
  
  // Check if it's specifically a webserviced.anaf.ro cookie
  if (cookie.domain === 'webserviced.anaf.ro' || cookie.domain === '.webserviced.anaf.ro') {
    console.log('[ANAF Extension] Cookie changed:', cookie.name, cookie.domain, 
                changeInfo.removed ? '(removed)' : '(added/updated)');
    
    // Update icon state when cookies change
    await checkAndUpdateIconState();
    
    // Check if u-core.test is open before syncing
    const isUCoreOpen = await isUCoreTestTabOpen();
    if (!isUCoreOpen) {
      console.log('[ANAF Extension] u-core.test not open, skipping auto-sync');
      return;
    }
    
    // Only sync on cookie additions/updates, not removals
    if (!changeInfo.removed) {
      // If it's a key ANAF session cookie, schedule smart batched sync
      const keyCookies = ['MRHSession', 'F5_ST', 'LastMRH_Session', 'JSESSIONID'];
      if (keyCookies.includes(cookie.name)) {
        console.log('[ANAF Extension] Key cookie detected, scheduling batched auto-sync...');
        scheduleBatchedSync('cookie_change', cookie.name);
      }
    } else {
      // Cookie was removed - check remaining cookies and report status
      const remainingCookies = await chrome.cookies.getAll({domain: "webserviced.anaf.ro"});
      const keyCookieCount = remainingCookies.filter(c => 
        ['MRHSession', 'F5_ST', 'LastMRH_Session'].includes(c.name)
      ).length;
      
      console.log(`[ANAF Extension] Cookie removed, ${keyCookieCount}/3 key cookies remaining`);
      await reportCookieStatus(keyCookieCount, 'cookie_removed');
    }
  }
});

// Enhanced page load monitoring with conservative timing
chrome.tabs.onUpdated.addListener(async (tabId, changeInfo, tab) => {
  // Update icon state when tabs change (especially on u-core.test pages)
  if (changeInfo.status === 'complete' && tab.url) {
    if (tab.url.includes('u-core.test')) {
      console.log('[ANAF Extension] u-core.test page loaded, updating icon to active state');
      await checkAndUpdateIconState();
    }
    
    if (tab.url.startsWith('https://webserviced.anaf.ro/')) {
      console.log('[ANAF Extension] ANAF page loaded, scheduling conservative cookie check...');
      // Wait longer for page to fully load and cookies to be set, then use batched sync
      setTimeout(() => {
        scheduleBatchedSync('page_load', tab.url);
      }, 8000); // Increased delay to ensure cookies are fully set
    }
  }
});

// Monitor tab removal to update icon state
chrome.tabs.onRemoved.addListener(async (tabId, removeInfo) => {
  // Small delay to allow tab list to update, then check if u-core.test is still open
  setTimeout(async () => {
    await checkAndUpdateIconState();
  }, 500);
});

// Monitor when tabs become active/inactive  
chrome.tabs.onActivated.addListener(async (activeInfo) => {
  await checkAndUpdateIconState();
});

// Initialize extension - set initial icon state
chrome.runtime.onStartup.addListener(async () => {
  console.log('[ANAF Extension] Extension starting up, checking initial icon state...');
  await checkAndUpdateIconState();
});

chrome.runtime.onInstalled.addListener(async () => {
  console.log('[ANAF Extension] Extension installed/updated, setting initial icon state...');
  await checkAndUpdateIconState();
});

// Periodic icon state check (every 30 seconds)
setInterval(async () => {
  await checkAndUpdateIconState();
}, 30000);

// Smart batched sync scheduling to reduce resource consumption
function scheduleBatchedSync(trigger, detail) {
  // Clear any existing pending sync to batch rapid changes
  if (pendingSyncTimeout) {
    console.log('[ANAF Extension] Batching sync - clearing previous timeout');
    clearTimeout(pendingSyncTimeout);
  }
  
  console.log(`[ANAF Extension] Batched sync scheduled: ${trigger} (${detail}) - will execute in ${BATCH_SYNC_DELAY}ms`);
  
  // Schedule the sync with batching delay
  pendingSyncTimeout = setTimeout(() => {
    pendingSyncTimeout = null;
    scheduleSync(trigger, detail);
  }, BATCH_SYNC_DELAY);
}

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

// Check if u-core.test is open in any tab (STRICT - only u-core.test allowed)
async function isUCoreTestTabOpen() {
  try {
    const tabs = await chrome.tabs.query({});
    console.log(`[ANAF Extension] Checking ${tabs.length} tabs for u-core.test`);
    
    const ucoreTabs = tabs.filter(tab => tab.url && tab.url.includes('u-core.test'));
    console.log(`[ANAF Extension] Found ${ucoreTabs.length} u-core.test tabs:`, ucoreTabs.map(t => t.url));
    
    return ucoreTabs.length > 0;
  } catch (error) {
    console.error('[ANAF Extension] Error checking tabs:', error);
    return false; // Default to false if can't check
  }
}

// Report cookie status to the application with conservative rate limiting
async function reportCookieStatus(cookieCount, trigger = 'status_check') {
  try {
    const now = Date.now();
    
    // Conservative rate limiting for status reports
    if (now - lastStatusReport < MIN_STATUS_INTERVAL) {
      console.log(`[ANAF Extension] Status report rate limited (${now - lastStatusReport}ms < ${MIN_STATUS_INTERVAL}ms), skipping`);
      return;
    }
    
    // Only report if u-core.test is open
    const isUCoreOpen = await isUCoreTestTabOpen();
    if (!isUCoreOpen) {
      console.log('[ANAF Extension] u-core.test not open, skipping status report');
      return;
    }
    
    lastStatusReport = now;

    const storage = await chrome.storage.sync.get(['appUrl']);
    const appUrl = storage.appUrl || 'https://u-core.test';
    
    const statusData = {
      cookie_count: cookieCount,
      required_count: 3,
      status: cookieCount === 3 ? 'complete' : cookieCount === 1 ? 'expired' : cookieCount === 2 ? 'incomplete' : 'no_session',
      timestamp: Date.now(),
      source: 'browser_extension_status',
      trigger: trigger,
      extension_version: '1.0.5'
    };
    
    console.log(`[ANAF Extension] Reporting cookie status: ${cookieCount}/3 cookies (${statusData.status})`);
    
    const response = await fetch(`${appUrl}/api/anaf/extension-cookies`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Extension-Version': '1.0.5',
        'X-Cookie-Status': statusData.status
      },
      body: JSON.stringify(statusData)
    });
    
    if (response.ok) {
      const data = await response.json();
      console.log(`[ANAF Extension] ✅ Status reported successfully:`, data);
    } else {
      console.warn(`[ANAF Extension] ⚠️ Status report failed:`, response.status);
    }
    
  } catch (error) {
    console.error('[ANAF Extension] Error reporting cookie status:', error);
  }
}

// Enhanced function to sync cookies to the Laravel app with retry logic
async function syncCookiesToApp(trigger = 'manual', retryCount = 0) {
  // Set sync in progress flag
  syncInProgress = true;
  lastSyncAttempt = Date.now();
  
  try {
    console.log(`[ANAF Extension] Starting sync attempt ${retryCount + 1}/${MAX_RETRY_ATTEMPTS + 1} (trigger: ${trigger})`);
    
    // Check if u-core.test is open (REQUIRED for ALL operations)
    const isUCoreOpen = await isUCoreTestTabOpen();
    if (!isUCoreOpen) {
      console.log('[ANAF Extension] u-core.test not open, skipping sync');
      await updateSyncStatus('tab_not_open', 'u-core.test tab not open - extension only works when u-core.test is open');
      return;
    }
    
    // Get ONLY webserviced.anaf.ro cookies
    const webservicedCookies = await chrome.cookies.getAll({domain: "webserviced.anaf.ro"});
    
    if (webservicedCookies.length === 0) {
      console.log('[ANAF Extension] No webserviced.anaf.ro cookies found');
      await updateSyncStatus('no_cookies', 'No ANAF cookies found');
      await reportCookieStatus(0, trigger);
      return;
    }
    
    // Filter for EXACTLY the 3 required ANAF session cookies
    const requiredCookieNames = ['MRHSession', 'F5_ST', 'LastMRH_Session'];
    const relevantCookies = webservicedCookies.filter(cookie => {
      const isRequiredCookie = requiredCookieNames.includes(cookie.name);
      const isRecentCookie = !cookie.expirationDate || (cookie.expirationDate * 1000) > Date.now();
      
      return isRequiredCookie && isRecentCookie;
    });
    
    // Count exactly how many of the required cookies we have
    const keyCookieCount = relevantCookies.length;
    console.log(`[ANAF Extension] Found ${keyCookieCount}/3 required cookies:`, 
                relevantCookies.map(c => c.name).join(', '));
    
    // Report cookie status regardless of count
    await reportCookieStatus(keyCookieCount, trigger);
    
    if (keyCookieCount < 3) {
      const missingCookies = requiredCookieNames.filter(name => 
        !relevantCookies.some(cookie => cookie.name === name)
      );
      
      console.log(`[ANAF Extension] Insufficient cookies - missing: ${missingCookies.join(', ')}`);
      await updateSyncStatus('insufficient_cookies', 
        `Only ${keyCookieCount}/3 required cookies found. Missing: ${missingCookies.join(', ')}`);
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
    // Check if u-core.test is open (REQUIRED for ALL operations)
    const isUCoreOpen = await isUCoreTestTabOpen();
    if (!isUCoreOpen) {
      return {
        success: false,
        message: 'u-core.test tab not open - please open u-core.test in your browser first',
        errorType: 'TAB_NOT_OPEN',
        troubleshooting: [
          'Open u-core.test in your browser',
          'Navigate to any page on the site',
          'Return to this popup and test connection again',
          'The extension only works when u-core.test is open'
        ]
      };
    }
    
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