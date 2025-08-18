// ANAF Cookie Helper - Content Script
// This script runs on the Laravel app pages and provides an API for the web app to request cookies

console.log('🔌 ANAF Cookie Helper extension loaded');

// Security: Validate allowed domains
const ALLOWED_DOMAINS = [
    'u-core.test',
    'localhost',
    '127.0.0.1',
    '::1'
];

// Check if current domain is allowed
function validateDomain() {
    const hostname = window.location.hostname;
    const isAllowed = ALLOWED_DOMAINS.some(domain => 
        hostname === domain || hostname.endsWith('.' + domain)
    );
    
    if (!isAllowed) {
        console.warn('🚫 ANAF Cookie Helper: Domain not allowed:', hostname);
        return false;
    }
    
    return true;
}

// Only initialize API on allowed domains
if (!validateDomain()) {
    // Don't expose API on unauthorized domains
    console.log('🚫 ANAF Cookie Helper: Extension blocked on unauthorized domain');
} else {
    console.log('✅ ANAF Cookie Helper: Domain authorized, initializing API');
}

// Extension status tracking
let extensionStatus = {
    connected: validateDomain(),
    lastPing: Date.now(),
    lastSync: null,
    lastError: null,
    connectionHealth: 'unknown'
};

// Create a global API that the web app can use (only on authorized domains)
if (validateDomain()) {
    window.anafCookieHelper = {
        version: '1.0.3',
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
        if (!validateDomain()) {
            return { authenticated: false, error: 'Domain not authorized' };
        }
        
        try {
            const cookieResult = await this.getCookies();
            if (!cookieResult.success) {
                extensionStatus.lastError = cookieResult.error;
                return { authenticated: false, error: cookieResult.error };
            }
            
            const requiredCookies = ['JSESSIONID', 'MRHSession', 'F5_ST'];
            const hasCookies = requiredCookies.some(name => 
                Object.keys(cookieResult.cookies).some(cookieName => 
                    cookieName.includes(name)
                )
            );
            
            extensionStatus.lastPing = Date.now();
            extensionStatus.connectionHealth = 'healthy';
            
            return {
                authenticated: hasCookies,
                cookieCount: Object.keys(cookieResult.cookies).length,
                cookies: cookieResult.cookies
            };
        } catch (error) {
            extensionStatus.lastError = error.message;
            extensionStatus.connectionHealth = 'error';
            return { authenticated: false, error: error.message };
        }
    },
    
    // Get extension status and health
    getStatus() {
        if (!validateDomain()) {
            return { 
                error: 'Domain not authorized',
                authorized: false,
                domain: window.location.hostname
            };
        }
        
        return {
            ...extensionStatus,
            authorized: true,
            domain: window.location.hostname,
            uptime: Date.now() - extensionStatus.lastPing,
            version: this.version
        };
    },
    
    // Test connection to background script
    async testConnection() {
        if (!validateDomain()) {
            return { 
                success: false, 
                error: 'Domain not authorized',
                domain: window.location.hostname
            };
        }
        
        try {
            console.log('🔄 Testing extension connection...');
            
            const startTime = Date.now();
            const response = await chrome.runtime.sendMessage({
                action: 'testConnection'
            });
            const latency = Date.now() - startTime;
            
            if (response && response.success) {
                extensionStatus.lastPing = Date.now();
                extensionStatus.connectionHealth = 'healthy';
                extensionStatus.lastError = null;
                
                console.log('✅ Extension connection test successful');
                return {
                    success: true,
                    latency: latency,
                    message: response.message,
                    extensionHealth: 'healthy',
                    backgroundScript: 'responsive'
                };
            } else {
                throw new Error(response?.error || 'Connection test failed');
            }
        } catch (error) {
            console.error('❌ Extension connection test failed', error);
            extensionStatus.lastError = error.message;
            extensionStatus.connectionHealth = 'error';
            
            return {
                success: false,
                error: error.message,
                extensionHealth: 'error',
                backgroundScript: 'unresponsive'
            };
        }
    },
    
    // Manual sync trigger
    async manualSync() {
        if (!validateDomain()) {
            return { 
                success: false, 
                error: 'Domain not authorized',
                domain: window.location.hostname
            };
        }
        
        try {
            console.log('🔄 Manual sync triggered...');
            
            // Use background script for manual sync
            const response = await chrome.runtime.sendMessage({
                action: 'manualSync'
            });
            
            if (response && response.success) {
                extensionStatus.lastSync = Date.now();
                extensionStatus.connectionHealth = 'healthy';
                extensionStatus.lastError = null;
                console.log('✅ Manual sync completed successfully');
                
                return {
                    success: true,
                    message: response.message || 'Manual sync completed',
                    cookieCount: await this.getCookieCount()
                };
            } else {
                throw new Error(response?.error || 'Manual sync failed');
            }
        } catch (error) {
            console.error('❌ Manual sync failed', error);
            extensionStatus.lastError = error.message;
            extensionStatus.connectionHealth = 'error';
            return {
                success: false,
                error: error.message
            };
        }
    },
    
    // Helper to get cookie count
    async getCookieCount() {
        try {
            const cookieResult = await this.getCookies();
            return cookieResult.success ? Object.keys(cookieResult.cookies).length : 0;
        } catch (error) {
            return 0;
        }
    }
    };

    // Notify the page that the extension API is available
    window.dispatchEvent(new CustomEvent('anaf-extension-loaded', {
        detail: {
            version: '1.0.3',
            api: 'window.anafCookieHelper',
            timestamp: new Date().toISOString(),
            authorized: true,
            domain: window.location.hostname
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
} else {
    // For unauthorized domains, dispatch a blocked event
    window.dispatchEvent(new CustomEvent('anaf-extension-blocked', {
        detail: {
            version: '1.0.3',
            authorized: false,
            domain: window.location.hostname,
            reason: 'Domain not in allowed list'
        }
    }));
}

// Listen for messages from the background script
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.action === 'extensionReady') {
        // Notify the page that the extension is ready (only if domain is authorized)
        if (validateDomain()) {
            window.dispatchEvent(new CustomEvent('anaf-extension-ready', {
                detail: {
                    version: '1.0.3',
                    timestamp: new Date().toISOString(),
                    authorized: true,
                    domain: window.location.hostname
                }
            }));
        }
        sendResponse({ success: validateDomain() });
    }
});