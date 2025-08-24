// Script to create modern icons for ANAF Extension
// Creates both blue (active) and grey (inactive) versions

const fs = require('fs');

// Modern SVG icon design - cookie with sync arrows
function createSVGIcon(color, backgroundColor) {
  return `<svg width="128" height="128" viewBox="0 0 128 128" xmlns="http://www.w3.org/2000/svg">
  <!-- Background circle -->
  <circle cx="64" cy="64" r="58" fill="${backgroundColor}" stroke="${color}" stroke-width="4"/>
  
  <!-- Cookie shape -->
  <circle cx="64" cy="64" r="32" fill="${color}" opacity="0.2"/>
  <circle cx="64" cy="64" r="32" fill="none" stroke="${color}" stroke-width="3"/>
  
  <!-- Cookie chips/dots -->
  <circle cx="56" cy="56" r="3" fill="${color}"/>
  <circle cx="72" cy="58" r="2.5" fill="${color}"/>
  <circle cx="58" cy="72" r="2" fill="${color}"/>
  <circle cx="70" cy="70" r="2.5" fill="${color}"/>
  <circle cx="64" cy="64" r="1.5" fill="${color}"/>
  
  <!-- Sync arrows around the cookie -->
  <g transform="translate(64,64)">
    <!-- Top arrow -->
    <path d="M-8,-40 L-3,-35 L-3,-38 L3,-38 L3,-35 L8,-40 L3,-45 L3,-42 L-3,-42 L-3,-45 Z" fill="${color}"/>
    <!-- Bottom arrow -->
    <path d="M8,40 L3,35 L3,38 L-3,38 L-3,35 L-8,40 L-3,45 L-3,42 L3,42 L3,45 Z" fill="${color}"/>
    <!-- Left arrow -->
    <path d="M-40,-8 L-35,-3 L-38,-3 L-38,3 L-35,3 L-40,8 L-45,3 L-42,3 L-42,-3 L-45,-3 Z" fill="${color}"/>
    <!-- Right arrow -->
    <path d="M40,8 L35,3 L38,3 L38,-3 L35,-3 L40,-8 L45,-3 L42,-3 L42,3 L45,3 Z" fill="${color}"/>
  </g>
  
  <!-- Small ANAF text -->
  <text x="64" y="110" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" font-weight="bold" fill="${color}">ANAF</text>
</svg>`;
}

// Create SVG icons
const blueIcon = createSVGIcon('#2563eb', '#dbeafe'); // Blue theme
const greyIcon = createSVGIcon('#6b7280', '#f3f4f6'); // Grey theme

// Write SVG files
fs.writeFileSync('icon-blue.svg', blueIcon);
fs.writeFileSync('icon-grey.svg', greyIcon);

console.log('✅ SVG icons created successfully!');
console.log('📁 Files created:');
console.log('  - icon-blue.svg (active state)');
console.log('  - icon-grey.svg (inactive state)');
console.log('');
console.log('🔧 Next steps:');
console.log('1. Convert SVG to PNG using an online converter or image editor');
console.log('2. Create sizes: 16x16, 48x48, 128x128 for both colors');
console.log('3. Name them: icon16-blue.png, icon16-grey.png, etc.');