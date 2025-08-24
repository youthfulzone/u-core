# 🧪 Testing Dynamic Icon System

## Step 1: Generate New Icon Files

1. **Open the icon generator:**
   - Open `generate-icons.html` in your browser
   - You should see clearly different blue and grey icons
   - Download all 6 PNG files (3 blue + 3 grey)

2. **Replace the existing icons:**
   - Save the downloaded files to replace the existing PNG files in the extension folder
   - Make sure the filenames match exactly:
     - `icon16-blue.png`
     - `icon48-blue.png` 
     - `icon128-blue.png`
     - `icon16-grey.png`
     - `icon48-grey.png`
     - `icon128-grey.png`

## Step 2: Reload the Extension

1. **Go to Chrome Extensions:**
   - Open `chrome://extensions/`
   - Find "ANAF Cookie Helper"
   - Click the reload button (circular arrow)

2. **Check the default icon:**
   - The extension icon should now be **grey** (inactive state)
   - Hover over it - tooltip should say "ANAF Cookie Helper - Inactive (u-core.test required)"

## Step 3: Test Icon Switching

1. **Test inactive state:**
   - Make sure NO u-core.test tabs are open
   - Icon should be grey
   - Open extension popup - buttons should be disabled with "❌ Open u-core.test first"

2. **Test active state:**
   - Open a new tab and go to `https://u-core.test`
   - Wait a few seconds
   - Icon should change to **blue**
   - Hover over it - tooltip should say "ANAF Cookie Helper - Ready (u-core.test active)"
   - Open extension popup - buttons should be enabled

3. **Test switching back:**
   - Close the u-core.test tab
   - Wait a few seconds
   - Icon should change back to **grey**

## Step 4: Debug if Not Working

1. **Open Extension Console:**
   - Go to `chrome://extensions/`
   - Find "ANAF Cookie Helper"
   - Click "Inspect views: background page"
   - Look for console messages starting with `[ANAF Extension]`

2. **Check for errors:**
   - Look for any errors about missing icon files
   - Check if the tab detection is working
   - Verify icon switching attempts

3. **Common issues:**
   - **Icons look the same:** Make sure you downloaded and replaced the PNG files from `generate-icons.html`
   - **No switching:** Check console for permission errors or missing files
   - **Console errors:** The extension might need the "tabs" permission added

## Expected Console Output

When working correctly, you should see messages like:
```
[ANAF Extension] Extension installed/updated, setting initial icon state...
[ANAF Extension] checkAndUpdateIconState called
[ANAF Extension] Checking 5 tabs for u-core.test
[ANAF Extension] Found 1 u-core.test tabs: ["https://u-core.test/"]
[ANAF Extension] u-core.test open: true, new state: blue
[ANAF Extension] updateExtensionIcon called with state: blue, current: grey
[ANAF Extension] Switching icon from grey to blue
[ANAF Extension] ✅ Icon successfully updated to blue state
[ANAF Extension] ✅ Tooltip updated: ANAF Cookie Helper - Ready (u-core.test active)
```

## Visual Verification

The icons should look clearly different:
- **Blue icons:** Bright blue color (#2563eb) - clearly visible blue tint
- **Grey icons:** Muted grey color (#6b7280) - clearly greyed out

If you still don't see a difference, the PNG files weren't replaced correctly.