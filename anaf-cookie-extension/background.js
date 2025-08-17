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
});

// Test connection to the Laravel app
async function testConnection() {
  try {
    const result = await chrome.storage.sync.get(['appUrl']);
    const appUrl = result.appUrl || 'https://u-core.test';
    
    console.log('Testing connection to:', `${appUrl}/api/anaf/session/status`);
    
    const response = await fetch(`${appUrl}/api/anaf/session/status`);
    
    if (response.ok) {
      const data = await response.json();
      console.log('Connection test successful:', data);
      return {
        success: true, 
        message: `Connection successful! Session active: ${data.session?.active ? 'Yes' : 'No'}`,
        data: data
      };
    } else {
      console.error('Connection test failed:', response.status, response.statusText);
      return {
        success: false, 
        message: `HTTP ${response.status} - ${response.statusText}`
      };
    }
  } catch (error) {
    console.error('Connection test error:', error);
    
    let errorMessage = error.message;
    if (error.message.includes('net::ERR_CERT_AUTHORITY_INVALID')) {
      errorMessage = 'SSL Certificate not accepted. Please visit the app URL first and accept the certificate.';
    } else if (error.message.includes('Failed to fetch')) {
      errorMessage = 'Cannot connect to app. Check if the Laravel app is running and the URL is correct.';
    }
    
    return {
      success: false, 
      message: errorMessage
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