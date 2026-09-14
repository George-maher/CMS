import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const en = JSON.parse(fs.readFileSync(path.join(root, 'src/i18n/en.json'), 'utf8'))
const ar = JSON.parse(fs.readFileSync(path.join(root, 'src/i18n/ar.json'), 'utf8'))

const flatten = (o, p = '', out = {}) => {
  for (const k of Object.keys(o)) {
    const v = o[k]
    const np = p ? p + '.' + k : k
    if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, np, out)
    else out[np] = v
  }
  return out
}
const EN = flatten(en)
const AR = flatten(ar)
const enKeys = new Set(Object.keys(EN))
const arKeys = new Set(Object.keys(AR))

function walk(dir, out = []) {
  for (const f of fs.readdirSync(dir)) {
    const p = path.join(dir, f)
    if (fs.statSync(p).isDirectory()) {
      if (f === 'i18n' || f === 'node_modules' || f === 'dist' || f === '.git') continue
      walk(p, out)
    } else if (/\.(tsx|ts)$/.test(f) && !/\.d\.ts$/.test(f)) {
      out.push(p)
    }
  }
  return out
}

const files = walk(path.join(root, 'src'))
const tKeys = new Set()
const dynamic = []

const literalRe = /(?:^|[^A-Za-z0-9_$.])\bt\((?:\s*)?[\`"']([^`"'${]+)[\`"']\s*(?:,|\))/g
const templateRe = /\bt\((?:\s*)?\`([^`]+)\`/g

for (const file of files) {
  const src = fs.readFileSync(file, 'utf8')
  let m
  literalRe.lastIndex = 0
  while ((m = literalRe.exec(src)) !== null) tKeys.add(m[1])
  templateRe.lastIndex = 0
  while ((m = templateRe.exec(src)) !== null) {
    const body = m[1]
    if (/\$\{/.test(body)) dynamic.push(file.replace(/\\/g, '/'))
    else tKeys.add(body)
  }
}

let failed = false

const enOnly = [...enKeys].filter((k) => !arKeys.has(k))
const arOnly = [...arKeys].filter((k) => !enKeys.has(k))
console.log(`EN keys: ${enKeys.size} | AR keys: ${arKeys.size}`)
if (enOnly.length || arOnly.length) {
  failed = true
  if (enOnly.length) console.log(`\n[FAIL] EN keys missing from AR (${enOnly.length}):\n  ${enOnly.join('\n  ')}`)
  if (arOnly.length) console.log(`\n[FAIL] AR keys missing from EN (${arOnly.length}):\n  ${arOnly.join('\n  ')}`)
} else {
  console.log('[PASS] en/ar key parity exact')
}

const missing = [...tKeys].filter((k) => !(k in EN) || !(k in AR))
console.log(`\nStatic keys used in code: ${tKeys.size} | dynamic templates: ${dynamic.length}`)
if (missing.length) {
  failed = true
  console.log(`\n[FAIL] Used keys missing in translations (${missing.length}):`)
  for (const k of missing) console.log(`  ${k}  en=${k in EN ? 'OK' : 'MISSING'} ar=${k in AR ? 'OK' : 'MISSING'}`)
} else {
  console.log('[PASS] every used static key exists in both en and ar')
}

if (dynamic.length) {
  console.log('\n[INFO] dynamic t(...) templates present — these are audited manually in CI PRs:')
  console.log('  ' + [...new Set(dynamic)].join('\n  '))
}

if (failed) {
  console.log('\ncheck-i18n: FAILED')
  process.exit(1)
}
console.log('\ncheck-i18n: PASS')