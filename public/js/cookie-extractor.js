/**
 * Browser Cookie Extractor for ANAF
 * This script runs in the browser console to extract cookies
 */

window.anafCookieExtractor = {
    // Target ANAF cookies
    targetCookies: ['JSESSIONID', 'MRHSession', 'F5_ST', 'LastMRH_Session'],
    
    // Extract all ANAF cookies from current page
    extractCookies: function() {
        const cookies = {};
        const allCookies = document.cookie.split(';');
        
        console.log('🍪 All cookies found:', allCookies.length);
        
        allCookies.forEach(cookie => {
            const [name, value] = cookie.trim().split('=');
            if (name && this.targetCookies.includes(name)) {
                cookies[name] = value;
                console.log(`✅ Found ANAF cookie: ${name} = ${value}`);
            }
        });
        
        return cookies;
    },
    
    // Send cookies to Laravel endpoint
    sendToLaravel: async function(endpoint = 'https://u-core.test/api/anaf/extension-cookies') {
        const cookies = this.extractCookies();
        
        if (Object.keys(cookies).length === 0) {
            console.log('❌ No ANAF cookies found on this page');
            return { success: false, message: 'No ANAF cookies found' };
        }
        
        console.log('📤 Sending cookies to Laravel:', cookies);
        
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    cookies: cookies,
                    source: 'browser_console',
                    timestamp: Math.floor(Date.now() / 1000),
                    browser_info: {
                        userAgent: navigator.userAgent,
                        url: window.location.href
                    }
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log('✅ Cookies sent successfully:', result.message);
                alert('🎉 Cookie-uri ANAF trimise cu succes! Puteți închide această fereastră.');
                return result;
            } else {
                console.error('❌ Failed to send cookies:', result.message);
                alert('❌ Eroare la trimiterea cookie-urilor: ' + result.message);
                return result;
            }
            
        } catch (error) {
            console.error('❌ Network error:', error);
            alert('❌ Eroare de rețea: ' + error.message);
            return { success: false, message: error.message };
        }
    },
    
    // Auto-extract and send cookies
    autoExtract: function() {
        console.log('🚀 ANAF Cookie Auto-Extractor Started');
        console.log('📍 Current page:', window.location.href);
        
        // Check if we're on ANAF domain
        if (!window.location.hostname.includes('anaf.ro')) {
            console.log('⚠️ Not on ANAF domain. Navigate to https://webserviced.anaf.ro first.');
            alert('Navigați mai întâi la https://webserviced.anaf.ro și autentificați-vă cu tokenul.');
            return;
        }
        
        // Wait a moment for page to load fully
        setTimeout(() => {
            this.sendToLaravel();
        }, 1000);
    }
};

// Auto-run if on ANAF domain
if (window.location.hostname.includes('anaf.ro')) {
    console.log('🔍 ANAF domain detected - ready for cookie extraction');
    window.anafCookieExtractor.autoExtract();
}

console.log('🍪 ANAF Cookie Extractor loaded. Use: window.anafCookieExtractor.autoExtract()');