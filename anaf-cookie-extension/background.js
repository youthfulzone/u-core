// Background service worker for ANAF Cookie Helper

// Monitor cookie changes ONLY for webserviced.anaf.ro
chrome.cookies.onChanged.addListener((changeInfo) => {
  const cookie = changeInfo.cookie;
  
  // Check if it's specifically a webserviced.anaf.ro cookie
  if (cookie.domain === 'webserviced.anaf.ro' || cookie.domain === '.webserviced.anaf.ro') {
    console.log('webserviced.anaf.ro cookie changed:', cookie.name, cookie.domain);
    
    // If it's a key ANAF session cookie, auto-sync
    const keyCookies = ['MRHSession', 'F5_ST', 'LastMRH_Session'];
    if (keyCookies.includes(cookie.name)) {
      console.log('Key webserviced.anaf.ro cookie detected, auto-syncing...');
      syncCookiesToApp();
    }
  }
});

// Auto-sync cookies when webserviced.anaf.ro pages are loaded
chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.status === 'complete' && tab.url && tab.url.startsWith('https://webserviced.anaf.ro/')) {
    console.log('webserviced.anaf.ro page loaded, checking for cookies...');
    setTimeout(() => {
      syncCookiesToApp();
    }, 2000); // Wait 2 seconds for cookies to be set
  }
});

// Function to sync cookies to the Laravel app
async function syncCookiesToApp() {
  try {
    // Get ONLY webserviced.anaf.ro cookies
    const webservicedCookies = await chrome.cookies.getAll({domain: "webserviced.anaf.ro"});
    
    if (webservicedCookies.length === 0) {
      console.log('No webserviced.anaf.ro cookies found');
      return;
    }
    
    // Filter to only include session cookies and essential ones
    const sessionCookies = webservicedCookies.filter(cookie => {
      // Include session cookies (no expiration date) and key ANAF cookies
      const isSessionCookie = cookie.session;
      const isKeyCookie = ['MRHSession', 'F5_ST', 'LastMRH_Session'].includes(cookie.name);
      const isAnalyticsCookie = cookie.name.startsWith('_ga') || cookie.name.startsWith('AMP_');
      
      return isSessionCookie || isKeyCookie || isAnalyticsCookie;
    });
    
    if (sessionCookies.length === 0) {
      console.log('No relevant webserviced.anaf.ro session cookies found');
      return;
    }
    
    // Format cookies as string (like browser sends them)
    const cookieString = sessionCookies
      .map(cookie => `${cookie.name}=${cookie.value}`)
      .join('; ');
    
    console.log('Syncing webserviced.anaf.ro cookies to app:', sessionCookies.length, 'cookies');
    
    // Get app URL from storage or use default
    const result = await chrome.storage.sync.get(['appUrl']);
    const appUrl = result.appUrl || 'https://u-core.test';
    
    // Send cookies to Laravel app
    console.log('Sending cookies to:', `${appUrl}/api/anaf/extension-cookies`);
    
    const response = await fetch(`${appUrl}/api/anaf/extension-cookies`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        cookies: cookieString,
        timestamp: Date.now(),
        source: 'browser_extension'
      })
    });
    
    if (response.ok) {
      const data = await response.json();
      console.log('Cookies synced successfully:', data);
      
      // Store last sync time and success details
      await chrome.storage.local.set({
        lastSync: Date.now(),
        lastSyncStatus: 'success',
        lastSyncMessage: data.message || 'Cookies synced successfully',
        cookieCount: data.cookie_count || sessionCookies.length
      });
    } else {
      // Get detailed error response
      let errorDetails = `HTTP ${response.status} - ${response.statusText}`;
      try {
        const errorData = await response.json();
        errorDetails = errorData.message || errorDetails;
        console.error('Failed to sync cookies - Server response:', errorData);
      } catch (parseError) {
        // If response is not JSON, use the text
        try {
          const errorText = await response.text();
          errorDetails = errorText || errorDetails;
          console.error('Failed to sync cookies - Server text:', errorText);
        } catch (textError) {
          console.error('Failed to sync cookies - No response body available');
        }
      }
      
      console.error('Failed to sync cookies:', response.status, errorDetails);
      await chrome.storage.local.set({
        lastSyncStatus: 'error',
        lastError: errorDetails,
        lastSync: Date.now(),
        errorCode: response.status
      });
    }
    
  } catch (error) {
    console.error('Error syncing cookies:', error);
    const errorMessage = error.message || 'Unknown network error';
    const errorType = error.name || 'NetworkError';
    
    await chrome.storage.local.set({
      lastSyncStatus: 'error',
      lastError: `${errorType}: ${errorMessage}`,
      lastSync: Date.now(),
      errorDetails: {
        type: errorType,
        message: errorMessage,
        stack: error.stack
      }
    });
  }
}

// Message handler for popup and content script communication
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'syncCookies') {
    syncCookiesToApp().then(() => {
      sendResponse({success: true});
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