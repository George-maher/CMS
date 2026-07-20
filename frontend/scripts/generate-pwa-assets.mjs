/**
 * PWA Asset Generator
 *
 * Converts SVG icons to PNG and generates splash screens.
 * Run: node scripts/generate-pwa-assets.mjs
 *
 * Requires: sharp (npm install --save-dev sharp)
 */

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const PUBLIC_DIR = path.resolve(__dirname, '..', 'public')

async function main() {
  let sharp
  try {
    sharp = (await import('sharp')).default
  } catch {
    console.error('Error: sharp is not installed.')
    console.error('Run: npm install --save-dev sharp')
    process.exit(1)
  }

  // ── Icon Sizes ──
  const ICON_SIZES = [72, 96, 128, 144, 152, 192, 384, 512]
  const FAVICON_SIZES = [16, 32]

  // ── Splash Screen Sizes ──
  const SPLASH_SCREENS = [
    { width: 640, height: 1136, label: 'iPhone SE / 5/5s/SE (1st gen)' },
    { width: 750, height: 1334, label: 'iPhone 6/6s/7/8/SE (2nd gen)' },
    { width: 1242, height: 2208, label: 'iPhone 6+/6s+/7+/8+' },
    { width: 1125, height: 2436, label: 'iPhone X/XS/11 Pro' },
    { width: 828, height: 1792, label: 'iPhone XR/11' },
    { width: 1242, height: 2688, label: 'iPhone XS Max/11 Pro Max' },
    { width: 1170, height: 2532, label: 'iPhone 12/12 Pro/13/13 Pro/14' },
    { width: 1284, height: 2778, label: 'iPhone 12 Pro Max/13 Pro Max/14 Plus' },
    { width: 1179, height: 2556, label: 'iPhone 14 Pro' },
    { width: 1290, height: 2796, label: 'iPhone 14 Pro Max' },
    { width: 1536, height: 2048, label: 'iPad Mini / Air (9.7-inch)' },
    { width: 1668, height: 2224, label: 'iPad Pro (10.5-inch)' },
    { width: 1668, height: 2388, label: 'iPad Pro (11-inch)' },
    { width: 2048, height: 2732, label: 'iPad Pro (12.9-inch)' },
  ]

  const ICONS_DIR = path.resolve(PUBLIC_DIR, 'icons')
  const SPLASH_DIR = path.resolve(PUBLIC_DIR, 'splash-screens')

  // Ensure directories exist
  if (!existsSync(ICONS_DIR)) mkdirSync(ICONS_DIR, { recursive: true })
  if (!existsSync(SPLASH_DIR)) mkdirSync(SPLASH_DIR, { recursive: true })

  // Read source SVG
  const sourceSvg = readFileSync(path.resolve(ICONS_DIR, 'icon-512.svg'), 'utf-8')

  // ── Generate PNG Icons ──
  console.log('Generating PNG icons...')
  for (const size of ICON_SIZES) {
    const outputPath = path.resolve(ICONS_DIR, `icon-${size}.png`)
    await sharp(Buffer.from(sourceSvg))
      .resize(size, size)
      .png()
      .toFile(outputPath)
    console.log(`  ✓ icon-${size}.png (${size}x${size})`)
  }

  // ── Generate 512 maskable ──
  const maskableSvg = readFileSync(path.resolve(ICONS_DIR, 'icon-512-maskable.svg'), 'utf-8')
  const maskablePath = path.resolve(ICONS_DIR, 'icon-512-maskable.png')
  await sharp(Buffer.from(maskableSvg))
    .resize(512, 512)
    .png()
    .toFile(maskablePath)
  console.log('  ✓ icon-512-maskable.png')

  // ── Generate 192 maskable ──
  const maskable192Path = path.resolve(ICONS_DIR, 'icon-192.png')
  await sharp(Buffer.from(maskableSvg))
    .resize(192, 192)
    .png()
    .toFile(maskable192Path)
  console.log('  ✓ icon-192.png (maskable)')

  // ── Generate Apple Touch Icon ──
  const appleTouchSvg = readFileSync(path.resolve(ICONS_DIR, 'apple-touch-icon.svg'), 'utf-8')
  const appleTouchPath = path.resolve(ICONS_DIR, 'apple-touch-icon.png')
  await sharp(Buffer.from(appleTouchSvg))
    .resize(180, 180)
    .png()
    .toFile(appleTouchPath)
  console.log('  ✓ apple-touch-icon.png (180x180)')

  // ── Generate Favicons ──
  for (const size of FAVICON_SIZES) {
    const outputPath = path.resolve(ICONS_DIR, `favicon-${size}.png`)
    await sharp(Buffer.from(sourceSvg))
      .resize(size, size)
      .png()
      .toFile(outputPath)
    console.log(`  ✓ favicon-${size}.png (${size}x${size})`)
  }

  // ── Generate Splash Screens ──
  console.log('\nGenerating splash screens...')
  for (const { width, height, label } of SPLASH_SCREENS) {
    const outputPath = path.resolve(SPLASH_DIR, `${width}x${height}.png`)
    await sharp(Buffer.from(sourceSvg))
      .resize(width, height, { fit: 'cover', position: 'center' })
      .png()
      .toFile(outputPath)
    console.log(`  ✓ ${width}x${height}.png (${label})`)
  }

  // ── Generate App Screenshots ──
  const SCREENSHOTS_DIR = path.resolve(PUBLIC_DIR, 'screenshots')
  if (!existsSync(SCREENSHOTS_DIR)) mkdirSync(SCREENSHOTS_DIR, { recursive: true })
  console.log('\nGenerating screenshots...')

  const desktopScreenshot = path.resolve(SCREENSHOTS_DIR, 'desktop.png')
  await sharp(Buffer.from(sourceSvg))
    .resize(1280, 800, { fit: 'contain', background: '#0f1d3d' })
    .png()
    .toFile(desktopScreenshot)
  console.log('  ✓ screenshots/desktop.png (1280x800)')

  const mobileScreenshot = path.resolve(SCREENSHOTS_DIR, 'mobile.png')
  await sharp(Buffer.from(sourceSvg))
    .resize(390, 844, { fit: 'contain', background: '#0f1d3d' })
    .png()
    .toFile(mobileScreenshot)
  console.log('  ✓ screenshots/mobile.png (390x844)')

  console.log('\n✅ All PWA assets generated successfully!')
}

main().catch((err) => {
  console.error('Error:', err)
  process.exit(1)
})
