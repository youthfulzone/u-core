// ANAF Cookie Helper - Content Script
// This script runs on the Laravel app pages and provides an API for the web app to request cookies

console.log('🔌 ANAF Cookie Helper extension loaded');

// Create a global API that the web app can use
window.anafCookieHelper = {
    version: '1.0.2',
    isExtensionActive: true,
    
    // Get ANAF cookies - returns a Promise
    async getCookies() {
        try {
            console.log('🔄 Extension: Fetching ANAF cookies...');
            
            const response = await chrome.runtime.sendMessage({
                action: 'getCookies',
                domain: 'webserviced.anaf.ro'
            });
            
            if (response && response.success) {
                console.log('✅ Extension: Cookies retrieved successfully', Object.keys(response.cookies));
                return {
                    success: true,
                    cookies: response.cookies,
                    timestamp: new Date().toISOString()
                };
            } else {
                console.log('❌ Extension: Failed to get cookies', response?.error);
                return {
                    success: false,
                    error: response?.error || 'Failed to retrieve cookies'
                };
            }
        } catch (error) {
            console.error('❌ Extension: Error getting cookies', error);
            return {
                success: false,
                error: error.message
            };
        }
    },
    
    // Sync cookies to Laravel app - returns a Promise
    async syncCookies(appUrl = null) {
        try {
            console.log('🔄 Extension: Starting cookie sync...');
            
            // Get the app URL from settings or use provided one
            if (!appUrl) {
                const { appUrl: storedUrl } = await chrome.storage.sync.get(['appUrl']);
                appUrl = storedUrl || window.location.origin;
            }
            
            const cookieResult = await this.getCookies();
            if (!cookieResult.success) {
                return cookieResult;
            }
            
            // Send cookies to Laravel app
            const syncResponse = await fetch(`${appUrl}/api/anaf/extension-cookies`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cookies: this.formatCookiesForLaravel(cookieResult.cookies),
                    timestamp: Date.now(),
                    source: 'extension_api'
                })
            });
            
            const syncResult = await syncResponse.json();
            
            if (syncResponse.ok && syncResult.success) {
                console.log('✅ Extension: Cookies synced to Laravel app');
                return {
                    success: true,
                    message: syncResult.message,
                    cookieCount: Object.keys(cookieResult.cookies).length
                };
            } else {
                console.log('❌ Extension: Failed to sync cookies to Laravel', syncResult);
                return {
                    success: false,
                    error: syncResult.message || 'Failed to sync cookies'
                };
            }
        } catch (error) {
            console.error('❌ Extension: Error syncing cookies', error);
            return {
                success: false,
                error: error.message
            };
        }
    },
    
    // Helper method to format cookies for Laravel
    formatCookiesForLaravel(cookies) {
        const cookieStrings = [];
        for (const [name, value] of Object.entries(cookies)) {
            cookieStrings.push(`${name}=${value}`);
        }
        return cookieStrings.join('; ');
    },
    
    // Check if user is authenticated at ANAF
    async checkAnafAuth() {
        try {
            const cookieResult = await this.getCookies();
            if (!cookieResult.success) {
                return { authenticated: false, error: cookieResult.error };
            }
            
            const requiredCookies = ['JSESSIONID', 'MRHSession', 'F5_ST'];
            const hasCookies = requiredCookies.some(name => 
                Object.keys(cookieResult.cookies).some(cookieName => 
                    cookieName.includes(name)
                )
            );
            
            return {
                authenticated: hasCookies,
                cookieCount: Object.keys(cookieResult.cookies).length,
                cookies: cookieResult.cookies
            };
        } catch (error) {
            return { authenticated: false, error: error.message };
        }
    }
};

// Listen for messages from the background script
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.action === 'extensionReady') {
        // Notify the page that the extension is ready
        window.dispatchEvent(new CustomEvent('anaf-extension-ready', {
            detail: {
                version: '1.0.2',
                timestamp: new Date().toISOString()
            }
        }));
        sendResponse({ success: true });
    }
});

// Notify the page that the extension API is available
window.dispatchEvent(new CustomEvent('anaf-extension-loaded', {
    detail: {
        version: '1.0.2',
        api: 'window.anafCookieHelper',
        timestamp: new Date().toISOString()
    }
}));

// Auto-sync cookies on page load for SPV pages
if (window.location.pathname.includes('/spv')) {
    console.log('🎯 Extension: SPV page detected, attempting auto-sync...');
    
    // Wait a moment for the page to load, then auto-sync
    setTimeout(async () => {
        try {
            const syncResult = await window.anafCookieHelper.syncCookies();
            if (syncResult.success) {
                console.log('✅ Extension: Auto-sync completed successfully');
                
                // Dispatch event to notify the page
                window.dispatchEvent(new CustomEvent('anaf-cookies-synced', {
                    detail: syncResult
                }));
            } else {
                console.log('ℹ️ Extension: Auto-sync failed (likely no ANAF cookies yet):', syncResult.error);
            }
        } catch (error) {
            console.log('ℹ️ Extension: Auto-sync error:', error.message);
        }
    }, 1000);
}