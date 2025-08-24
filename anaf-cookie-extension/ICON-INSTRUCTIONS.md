# ANAF Extension Icon System

## Overview
The extension now uses modern, dynamic icons that change color based on u-core.test availability:
- **Blue Icons**: Active state (u-core.test is open)
- **Grey Icons**: Inactive state (u-core.test required)

## Files Structure
```
anaf-cookie-extension/
├── icon16-blue.png      # 16x16 active icon
├── icon48-blue.png      # 48x48 active icon  
├── icon128-blue.png     # 128x128 active icon
├── icon16-grey.png      # 16x16 inactive icon
├── icon48-grey.png      # 48x48 inactive icon
├── icon128-grey.png     # 128x128 inactive icon
├── icon16-blue.svg      # SVG source (editable)
├── icon48-blue.svg      # SVG source (editable)
├── icon128-blue.svg     # SVG source (editable)
├── icon16-grey.svg      # SVG source (editable)
├── icon48-grey.svg      # SVG source (editable)
├── icon128-grey.svg     # SVG source (editable)
└── svg-to-png.html      # Converter tool
```

## Creating New PNG Icons
Currently using placeholder PNG files. To create proper PNG versions:

### Option 1: Use the HTML Converter
1. Open `svg-to-png.html` in your browser
2. Click download buttons for each size
3. Replace the existing PNG files

### Option 2: Online Converter
1. Visit any SVG to PNG converter (e.g., convertio.co)
2. Upload the SVG files
3. Convert to PNG at exact sizes (16x16, 48x48, 128x128)
4. Replace the existing PNG files

### Option 3: Command Line (if you have ImageMagick)
```bash
magick icon16-blue.svg icon16-blue.png
magick icon48-blue.svg icon48-blue.png
magick icon128-blue.svg icon128-blue.png
magick icon16-grey.svg icon16-grey.png
magick icon48-grey.svg icon48-grey.png
magick icon128-grey.svg icon128-grey.png
```

## Icon Design
The icons feature:
- Central cookie shape with dots
- Sync arrows around the cookie (indicating API synchronization)
- "A" text indicator for ANAF
- Professional blue (#2563eb) and grey (#6b7280) color schemes
- Clean, modern design that scales well at all sizes

## Dynamic Behavior
The extension automatically switches icons based on:
- Extension startup/installation
- u-core.test page loads/closes
- Tab activation changes
- ANAF cookie changes
- Periodic checks (every 30 seconds)

## Customization
To modify the icon design:
1. Edit the SVG files directly or use the JavaScript generators
2. Convert to PNG using one of the methods above
3. Restart the extension to see changes

The icon system provides clear visual feedback to users about when the extension is ready to work!