// Service Worker to attempt ANAF API calls
self.addEventListener('message', async (event) => {
    if (event.data.type === 'ANAF_REQUEST') {
        try {
            console.log('Service Worker attempting ANAF request:', event.data.url);
            
            const response = await fetch(event.data.url, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                },
                mode: 'cors'
            });
            
            if (response.ok) {
                const data = await response.json();
                event.ports[0].postMessage({
                    success: true,
                    data: data
                });
            } else {
                event.ports[0].postMessage({
                    success: false,
                    error: `HTTP ${response.status}: ${response.statusText}`
                });
            }
        } catch (error) {
            console.error('Service Worker ANAF request failed:', error);
            event.ports[0].postMessage({
                success: false,
                error: error.message
            });
        }
    }
});

// Install event
self.addEventListener('install', (event) => {
    console.log('ANAF Service Worker installed');
    self.skipWaiting();
});

// Activate event
self.addEventListener('activate', (event) => {
    console.log('ANAF Service Worker activated');
    event.waitUntil(self.clients.claim());
});