import { writeFileSync, existsSync, mkdirSync } from 'fs'
import { deflateSync } from 'zlib'
import { join, dirname } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = join(__dirname, '..')
const ICONS_DIR = join(ROOT, 'public', 'icons')

const SIGNATURE = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])

function crc32(data) {
  let crc = 0xFFFFFFFF
  for (let i = 0; i < data.length; i++) {
    crc ^= data[i]
    for (let j = 0; j < 8; j++) {
      crc = crc & 1 ? (crc >>> 1) ^ 0xEDB88320 : crc >>> 1
    }
  }
  return (crc ^ 0xFFFFFFFF) >>> 0
}

function makeChunk(type, data) {
  const len = Buffer.alloc(4)
  len.writeUInt32BE(data.length)
  const typeB = Buffer.from(type, 'ascii')
  const combined = Buffer.concat([typeB, data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(combined))
  return Buffer.concat([len, typeB, data, crc])
}

function createPNG(width, height, pixels) {
  const raw = Buffer.alloc(width * height * 4 + height)
  for (let y = 0; y < height; y++) {
    raw[y * (width * 4 + 1)] = 0
    const rowStart = y * width * 4
    raw.set(pixels.subarray(rowStart, rowStart + width * 4), y * (width * 4 + 1) + 1)
  }
  const compressed = deflateSync(raw)
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(width, 0)
  ihdr.writeUInt32BE(height, 4)
  ihdr[8] = 8
  ihdr[9] = 6
  ihdr[10] = 0
  ihdr[11] = 0
  ihdr[12] = 0
  return Buffer.concat([
    SIGNATURE,
    makeChunk('IHDR', ihdr),
    makeChunk('IDAT', compressed),
    makeChunk('IEND', Buffer.alloc(0)),
  ])
}

const NAVY = [15, 23, 42, 255]
const GOLD = [212, 175, 55, 255]
const LIGHTER_GOLD = [230, 195, 85, 255]

function drawIcon(size, isMaskable) {
  const pixels = new Uint8Array(size * size * 4)
  const cx = size / 2
  const cy = size / 2

  const scale = isMaskable ? 0.8 : 1.0
  const crossW = size * 0.1 * scale
  const crossH = size * 0.5 * scale
  const barW = size * 0.35 * scale
  const barH = size * 0.1 * scale

  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const idx = (y * size + x) * 4
      const inVert = x >= cx - crossW / 2 && x < cx + crossW / 2 && y >= cy - crossH / 2 && y < cy + crossH / 2
      const inHoriz = x >= cx - barW / 2 && x < cx + barW / 2 && y >= cy - barH / 2 && y < cy + barH / 2
      if (inVert || inHoriz) {
        const isEdge = (inVert && (x < cx - crossW / 2 + 1 || x >= cx + crossW / 2 - 1)) || (inHoriz && (y < cy - barH / 2 + 1 || y >= cy + barH / 2 - 1))
        pixels[idx] = isEdge ? LIGHTER_GOLD[0] : GOLD[0]
        pixels[idx + 1] = isEdge ? LIGHTER_GOLD[1] : GOLD[1]
        pixels[idx + 2] = isEdge ? LIGHTER_GOLD[2] : GOLD[2]
        pixels[idx + 3] = GOLD[3]
      } else {
        pixels[idx] = NAVY[0]
        pixels[idx + 1] = NAVY[1]
        pixels[idx + 2] = NAVY[2]
        pixels[idx + 3] = NAVY[3]
      }
    }
  }
  return pixels
}

// Splash screen: navy background with smaller centered cross icon
function drawSplash(width, height) {
  const pixels = new Uint8Array(width * height * 4)

  // Fill with navy
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const idx = (y * width + x) * 4
      pixels[idx] = NAVY[0]
      pixels[idx + 1] = NAVY[1]
      pixels[idx + 2] = NAVY[2]
      pixels[idx + 3] = NAVY[3]
    }
  }

  const iconSize = Math.min(width, height) * 0.2
  const cx = width / 2
  const cy = height / 2
  const crossW = iconSize * 0.15
  const crossH = iconSize * 0.6
  const barW = iconSize * 0.5
  const barH = iconSize * 0.15

  for (let y = Math.max(0, Math.floor(cy - crossH / 2)); y < Math.min(height, Math.ceil(cy + crossH / 2)); y++) {
    for (let x = Math.max(0, Math.floor(cx - barW / 2)); x < Math.min(width, Math.ceil(cx + barW / 2)); x++) {
      const idx = (y * width + x) * 4
      const inVert = x >= cx - crossW / 2 && x < cx + crossW / 2 && y >= cy - crossH / 2 && y < cy + crossH / 2
      const inHoriz = x >= cx - barW / 2 && x < cx + barW / 2 && y >= cy - barH / 2 && y < cy + barH / 2
      if (inVert || inHoriz) {
        const isEdge = (inVert && (x < cx - crossW / 2 + 1 || x >= cx + crossW / 2 - 1)) || (inHoriz && (y < cy - barH / 2 + 1 || y >= cy + barH / 2 - 1))
        if (!isEdge) {
          pixels[idx] = GOLD[0]
          pixels[idx + 1] = GOLD[1]
          pixels[idx + 2] = GOLD[2]
          pixels[idx + 3] = GOLD[3]
        }
      }
    }
  }

  return pixels
}

function generate() {
  if (!existsSync(ICONS_DIR)) mkdirSync(ICONS_DIR, { recursive: true })

  const sizes = [
    { file: 'icon-192x192.png', size: 192, maskable: false },
    { file: 'icon-512x512.png', size: 512, maskable: false },
    { file: 'icon-192x192-maskable.png', size: 192, maskable: true },
    { file: 'icon-512x512-maskable.png', size: 512, maskable: true },
    { file: 'apple-touch-icon.png', size: 180, maskable: false },
    { file: 'favicon-16.png', size: 16, maskable: false },
    { file: 'favicon-32.png', size: 32, maskable: false },
  ]

  for (const { file, size, maskable } of sizes) {
    const outPath = join(ICONS_DIR, file)
    if (existsSync(outPath)) {
      console.log(`  ✓ ${file} (already exists, skipping)`)
      continue
    }
    const pixels = drawIcon(size, maskable)
    const png = createPNG(size, size, pixels)
    writeFileSync(outPath, png)
    console.log(`  ✓ ${file} (${size}x${size})`)
  }

  const splashSizes = [
    { file: 'apple-splash-640x1136.png', w: 640, h: 1136 },
    { file: 'apple-splash-750x1334.png', w: 750, h: 1334 },
    { file: 'apple-splash-1242x2208.png', w: 1242, h: 2208 },
    { file: 'apple-splash-1125x2436.png', w: 1125, h: 2436 },
    { file: 'apple-splash-1170x2532.png', w: 1170, h: 2532 },
    { file: 'apple-splash-1290x2796.png', w: 1290, h: 2796 },
  ]

  for (const { file, w, h } of splashSizes) {
    const outPath = join(ICONS_DIR, file)
    if (existsSync(outPath)) {
      console.log(`  ✓ ${file} (already exists, skipping)`)
      continue
    }
    const pixels = drawSplash(w, h)
    const png = createPNG(w, h, pixels)
    writeFileSync(outPath, png)
    console.log(`  ✓ ${file} (${w}x${h})`)
  }
}

generate()
console.log('\nPWA icons generated successfully (pure JS, no native dependencies)')
