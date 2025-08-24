const fs = require('fs');

// Base64 data for simple modern icons
// These are simple 16x16, 48x48, and 128x128 PNG icons created with basic shapes

// Blue 16x16 icon - simple rounded square with 'A' for ANAF
const icon16Blue = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAJ5JREFUOI2tk8ENwjAMRV+cCBYgJmAFRmAEdoAJGIEVGIERGIER2IEVGIEVGIEJGIERGIEVGKEvOXJJSKOWJ1mO/3/+swPwAzzAE3gAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AATwBLQDmj7hkx+AAAAABJRU5ErkJggg==';

// Grey 16x16 icon
const icon16Grey = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAJ5JREFUOI2tk8ENwjAMRV+cCBYgJmAFRmAEdoAJGIEVGIEJGIERGIER2IEVGIEVGIEJGIERGIEVGKEvOXJJSKOWJ1mO/3/+swPwAzzAE3gAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AAT/AATwBLQDmj7hkx+AAAAABJRU5ErkJggg==';

// For now, I'll create the actual icon files by writing SVG content and then 
// manually convert or use a simpler approach

// Create a more sophisticated modern icon
function createModernIconSVG(color, size) {
  const strokeWidth = Math.max(1, size / 32);
  const fontSize = Math.max(8, size / 8);
  const radius = size / 2 - strokeWidth * 2;
  
  return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
    <!-- Background circle -->
    <circle cx="${size/2}" cy="${size/2}" r="${radius}" fill="${color}" opacity="0.1" stroke="${color}" stroke-width="${strokeWidth}"/>
    
    <!-- Cookie/sync icon -->
    <g transform="translate(${size/2}, ${size/2})">
      <!-- Central circle (cookie) -->
      <circle cx="0" cy="0" r="${radius * 0.5}" fill="none" stroke="${color}" stroke-width="${strokeWidth}"/>
      
      <!-- Cookie dots -->
      <circle cx="${-radius * 0.2}" cy="${-radius * 0.2}" r="${strokeWidth}" fill="${color}"/>
      <circle cx="${radius * 0.2}" cy="${-radius * 0.1}" r="${strokeWidth * 0.8}" fill="${color}"/>
      <circle cx="${-radius * 0.1}" cy="${radius * 0.2}" r="${strokeWidth * 0.6}" fill="${color}"/>
      <circle cx="${radius * 0.2}" cy="${radius * 0.2}" r="${strokeWidth * 0.8}" fill="${color}"/>
      
      <!-- Sync arrows -->
      <path d="M${-radius * 0.7},${-radius * 0.2} L${-radius * 0.5},${-radius * 0.4} L${-radius * 0.5},${-radius * 0.3} L${-radius * 0.3},${-radius * 0.3} L${-radius * 0.3},${-radius * 0.1} L${-radius * 0.5},${-radius * 0.1} Z" fill="${color}"/>
      <path d="M${radius * 0.7},${radius * 0.2} L${radius * 0.5},${radius * 0.4} L${radius * 0.5},${radius * 0.3} L${radius * 0.3},${radius * 0.3} L${radius * 0.3},${radius * 0.1} L${radius * 0.5},${radius * 0.1} Z" fill="${color}"/>
    </g>
    
    <!-- Small text indicator -->
    <text x="${size/2}" y="${size - fontSize/2}" text-anchor="middle" font-family="Arial, sans-serif" font-size="${fontSize * 0.7}" font-weight="bold" fill="${color}">A</text>
  </svg>`;
}

// Create SVG files for both colors and all sizes
const blueColor = '#2563eb';
const greyColor = '#6b7280';
const sizes = [16, 48, 128];

sizes.forEach(size => {
  fs.writeFileSync(`icon${size}-blue.svg`, createModernIconSVG(blueColor, size));
  fs.writeFileSync(`icon${size}-grey.svg`, createModernIconSVG(greyColor, size));
});

console.log('✅ Modern SVG icons created successfully!');
console.log('📁 Files created:');
sizes.forEach(size => {
  console.log(`  - icon${size}-blue.svg & icon${size}-grey.svg`);
});

console.log('');
console.log('🌐 To convert to PNG:');
console.log('1. Open svg-to-png.html in your browser');
console.log('2. Or use an online SVG to PNG converter');
console.log('3. Or use a tool like Inkscape or ImageMagick');

// For now, let's create simplified PNG files by copying existing ones and renaming
// We'll create the actual implementation without the PNG files first
console.log('');
console.log('⚡ Creating placeholder PNG files for testing...');

// Copy existing icons as placeholders
try {
  // Copy existing icons to new names
  fs.copyFileSync('icon16.png', 'icon16-blue.png');
  fs.copyFileSync('icon48.png', 'icon48-blue.png');
  fs.copyFileSync('icon128.png', 'icon128-blue.png');
  
  fs.copyFileSync('icon16.png', 'icon16-grey.png');
  fs.copyFileSync('icon48.png', 'icon48-grey.png');
  fs.copyFileSync('icon128.png', 'icon128-grey.png');
  
  console.log('✅ Placeholder PNG files created from existing icons');
  console.log('📝 Note: Replace these with proper blue/grey versions later');
} catch (error) {
  console.log('⚠️ Could not create placeholder PNGs - existing icons not found');
  console.log('You will need to create the PNG files manually from the SVGs');
}