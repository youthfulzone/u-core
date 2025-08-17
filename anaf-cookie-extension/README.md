# ANAF Cookie Helper Extension

This Chrome extension automatically extracts session cookies from webserviced.anaf.ro and syncs them to your Laravel application for seamless ANAF API integration.

## Features

- 🍪 **Automatic Cookie Detection**: Monitors webserviced.anaf.ro for session cookies
- 🔄 **Real-time Sync**: Automatically syncs cookies when they change
- 🧪 **Connection Testing**: Test connection to your Laravel app
- 📊 **Status Monitoring**: Shows sync status and session information
- 🔍 **Debug Support**: Detailed error reporting and troubleshooting

## Installation

1. **Load the Extension**:
   - Open Chrome and go to `chrome://extensions/`
   - Enable "Developer mode" (toggle in top right)
   - Click "Load unpacked" and select the `anaf-cookie-extension` folder

2. **Accept SSL Certificate**:
   - Visit `https://u-core.test` first
   - Click "Advanced" → "Proceed to u-core.test (unsafe)"
   - This allows the extension to communicate with your local Laravel app

3. **Configure App URL**:
   - Click the extension icon in Chrome toolbar
   - Set the correct app URL (default: `https://u-core.test`)
   - Click "💾 Save Settings"

## Usage

### Step 1: Test Connection
1. Click the extension icon
2. Click "🧪 Test Connection"
3. Verify you see "✅ Connection successful!"

### Step 2: Authenticate with ANAF
1. Visit `https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60`
2. Insert your physical token/smart card
3. Complete the certificate-based authentication
4. The extension will automatically detect and sync the session cookies

### Step 3: Verify Sync
1. Check the extension popup for sync status
2. Visit your SPV page at `https://u-core.test/spv`
3. Click "Sync ANAF Messages" to retrieve messages

## How It Works

### Automatic Monitoring
The extension monitors three key ANAF session cookies:
- `MRHSession` - Main session identifier
- `F5_ST` - Load balancer session
- `LastMRH_Session` - Session tracking

### Background Sync
When these cookies change or when you visit webserviced.anaf.ro pages, the extension:
1. Extracts relevant session cookies
2. Sends them to your Laravel app via POST to `/api/anaf/extension-cookies`
3. Your Laravel app stores them for API calls

### Session Management
Your Laravel app uses these cookies to make authenticated requests to ANAF APIs without requiring manual certificate setup.

## Troubleshooting

### Connection Issues
- **SSL Certificate Error**: Visit `https://u-core.test` and accept the certificate
- **Connection Failed**: Ensure your Laravel app is running (`npm run dev`)
- **CORS Error**: The extension automatically handles CORS with proper headers

### Cookie Issues
- **No Cookies Found**: Make sure you're authenticated on webserviced.anaf.ro
- **Sync Failed**: Check the error details in the extension popup
- **Session Expired**: Re-authenticate on the ANAF website

### Debug Information
1. Right-click extension icon → "Inspect popup" for frontend logs
2. Go to `chrome://extensions/` → Click "service worker" link for background logs
3. Check Laravel logs at `storage/logs/laravel.log`

## Security

- Only monitors cookies from `webserviced.anaf.ro` domain
- Does not access cookies from other websites
- Uses secure HTTPS communication with your Laravel app
- Cookies are only sent to your configured app URL

## Development

### Extension Structure
```
anaf-cookie-extension/
├── manifest.json          # Extension configuration
├── background.js          # Service worker for cookie monitoring
├── popup.html            # Extension popup interface
├── popup.js              # Popup functionality
├── icon*.png            # Extension icons
└── README.md           # This file
```

### Laravel Integration
The extension communicates with these Laravel endpoints:
- `POST /api/anaf/extension-cookies` - Receive synced cookies
- `GET /api/anaf/session/status` - Check session status

### Permissions
- `cookies` - Read cookies from webserviced.anaf.ro
- `activeTab` - Monitor tab changes for auto-sync
- `storage` - Save extension settings
- `host_permissions` - Access to webserviced.anaf.ro and your app domain

## License

This extension is part of the U-Core Laravel application and follows the same license terms.