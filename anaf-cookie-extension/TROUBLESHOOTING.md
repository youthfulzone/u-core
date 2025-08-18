# ANAF Cookie Helper - Troubleshooting Guide

## 🚨 Common Issues and Solutions

### ❌ "Connection: error" in Extension Popup

**Problem**: The extension shows a connection error when testing.

**Solutions** (try in order):

#### 1. **Accept SSL Certificate** ⭐ Most Common Fix
1. Open your browser and visit: `https://u-core.test`
2. You'll see a security warning about the SSL certificate
3. Click "Advanced" → "Proceed to u-core.test (unsafe)"
4. You should see the Laravel application
5. Go back to the extension and click "Test Connection"

#### 2. **Check App URL Settings**
1. Open the extension popup
2. Check the "App URL" field at the bottom
3. Make sure it's set to: `https://u-core.test`
4. Click "Save Settings"
5. Click "Test Connection"

#### 3. **Verify Laravel App is Running**
1. Check that Laravel Herd is running
2. Visit `https://u-core.test` directly in browser
3. You should see the application, not an error page

#### 4. **Extension Not Loading on SPV Page**
1. Make sure you're visiting: `https://u-core.test/spv` (not HTTP)
2. Check browser console (F12) for errors
3. Look for "Extension loaded" messages in console
4. Reload the extension: Chrome → Extensions → Reload

### ❌ "Extension not available" Error

**Problem**: The frontend shows "Extension not available" when testing.

**Solutions**:

#### 1. **Verify Extension Installation**
1. Go to Chrome → Extensions (chrome://extensions/)
2. Find "ANAF Cookie Helper"
3. Make sure it's enabled (toggle switch is blue)
4. Check that "Allow in incognito" is enabled if needed

#### 2. **Check Domain Authorization**
1. Make sure you're on: `https://u-core.test/spv`
2. Extension only works on allowed domains
3. Check browser console for "Domain not authorized" messages

#### 3. **Reload Extension**
1. Go to Chrome Extensions page
2. Click "Reload" button under ANAF Cookie Helper
3. Refresh the SPV page
4. Try testing again

### ❌ SSL Certificate Issues

**Problem**: Various SSL-related errors.

**Solutions**:

#### Option A: Accept Certificate (Recommended)
1. Visit `https://u-core.test` in browser
2. Accept the security warning
3. Bookmark the page for easy access

#### Option B: Use HTTP (Less Secure)
1. Update extension settings to use: `http://u-core.test`
2. Update Laravel app to allow HTTP if needed
3. Note: ANAF cookies might not sync properly over HTTP

#### Option C: Use Localhost
1. Check if Laravel is accessible via: `http://localhost:8000`
2. Update extension App URL accordingly
3. Make sure domain is in the allowed list

## 🔧 Debug Steps

### 1. **Test Extension Loading**
1. Save this as test.html and open from u-core.test:
```html
<script>
setTimeout(() => {
    if (window.anafCookieHelper) {
        console.log('✅ Extension loaded:', window.anafCookieHelper.version);
    } else {
        console.log('❌ Extension not found');
    }
}, 1000);
</script>
```

### 2. **Check Browser Console**
1. Visit SPV page: `https://u-core.test/spv`
2. Press F12 → Console
3. Look for messages starting with:
   - "🔌 ANAF Cookie Helper extension loaded"
   - "✅ Extension loaded event"
   - "❌ Extension blocked"

### 3. **Test API Manually**
```bash
curl -k https://u-core.test/api/anaf/session/status
```
Should return JSON with `"success": true`

## 📋 Extension Requirements Checklist

- [ ] Chrome browser with extensions enabled
- [ ] Extension installed and enabled
- [ ] Laravel app running on https://u-core.test
- [ ] SSL certificate accepted in browser
- [ ] Visiting the SPV page via HTTPS
- [ ] Domain is in allowed list (u-core.test, localhost, etc.)

## 🆘 Still Having Issues?

1. **Check Extension Version**: Should be 1.0.3 or higher
2. **Browser Console**: Look for specific error messages
3. **Network Tab**: Check for failed requests to u-core.test
4. **Extension Console**: Check background script errors in Chrome Extensions debug

### Quick Reset Steps:
1. Remove and reinstall the extension
2. Clear browser cache and cookies
3. Restart Chrome
4. Accept SSL certificate again
5. Test step by step

---

💡 **Pro Tip**: The most common issue is the SSL certificate. Always try accepting the certificate first!