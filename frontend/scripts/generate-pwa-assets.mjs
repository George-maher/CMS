import { readFileSync, mkdirSync, existsSync } from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const PUBLIC_DIR = path.resolve(__dirname, '..', 'public')
const ICONS_DIR = path.resolve(PUBLIC_DIR, 'icons')

const REQUIRED_ICONS = ['icon-192x192.png', 'icon-512x512.png', 'icon-192x192-maskable.png', 'icon-512x512-maskable.png', 'apple-touch-icon.png']

function allIconsExist() {
  if (!existsSync(ICONS_DIR)) return false
  return REQUIRED_ICONS.every((name) => existsSync(path.resolve(ICONS_DIR, name)))
}

function getSvg(name) {
  const p = path.resolve(ICONS_DIR, name)
  if (!existsSync(p)) {
    console.error(`  ✗ Missing source: ${name}`)
    process.exit(1)
  }
  return readFileSync(p)
}

async function main() {
  let sharp
  try {
    sharp = (await import('sharp')).default
  } catch {
    console.warn('⚠ sharp not installed. Install it to generate PWA icons:')
    console.warn('  npm install --save-dev sharp')
    console.warn('PNG icons are missing — PWA will not be installable on Android until icons are generated.')
    return
  }

  if (!existsSync(ICONS_DIR)) mkdirSync(ICONS_DIR, { recursive: true })

  const sourceSvg = getSvg('icon-512.svg')
  const sizes = [72, 96, 128, 144, 152, 192, 384, 512]
  const faviconSizes = [16, 32]

  console.log('Generating PNG icons...')
  for (const size of sizes) {
    const out = path.resolve(ICONS_DIR, `icon-${size}x${size}.png`)
    await sharp(sourceSvg).resize(size, size).png().toFile(out)
    console.log(`  ✓ icon-${size}x${size}.png (${size}x${size})`)
  }

  const maskableSvg = getSvg('icon-512-maskable.svg')
  const maskable512 = path.resolve(ICONS_DIR, 'icon-512x512-maskable.png')
  await sharp(maskableSvg).resize(512, 512).png().toFile(maskable512)
  console.log('  ✓ icon-512x512-maskable.png')

  const maskable192 = path.resolve(ICONS_DIR, 'icon-192x192-maskable.png')
  await sharp(maskableSvg).resize(192, 192).png().toFile(maskable192)
  console.log('  ✓ icon-192x192-maskable.png')

  const appleTouchSvg = getSvg('apple-touch-icon.svg')
  const appleTouch = path.resolve(ICONS_DIR, 'apple-touch-icon.png')
  await sharp(appleTouchSvg).resize(180, 180).png().toFile(appleTouch)
  console.log('  ✓ apple-touch-icon.png (180x180)')

  for (const size of faviconSizes) {
    const out = path.resolve(ICONS_DIR, `favicon-${size}.png`)
    await sharp(sourceSvg).resize(size, size).png().toFile(out)
    console.log(`  ✓ favicon-${size}.png (${size}x${size})`)
  }

  console.log('\nAll PWA icons generated successfully!')
}

const isForce = process.argv.includes('--force')

async function run() {
  if (!isForce && allIconsExist()) {
    console.log('PNG icons already exist — skipping generation.')
    console.log('  Run "node scripts/generate-pwa-assets.mjs --force" to regenerate.')
    return
  }
  await main()
}

run().catch((err) => { console.error('Error:', err); process.exit(1) })
