const dns = require('node:dns').promises
const { isIP } = require('node:net')
const { chromium } = require('playwright')

function isPublicAddress(address) {
  if (isIP(address) === 4) {
    const [a, b] = address.split('.').map(Number)
    return !(
      a === 0 || a === 10 || a === 127 || a >= 224 ||
      (a === 169 && b === 254) ||
      (a === 172 && b >= 16 && b <= 31) ||
      (a === 192 && b === 168) ||
      (a === 192 && b === 0) ||
      (a === 100 && b >= 64 && b <= 127) ||
      (a === 198 && (b === 18 || b === 19 || b === 51)) ||
      (a === 203 && b === 0)
    )
  }

  if (isIP(address) === 6) {
    const normalized = address.toLowerCase()
    return (normalized.startsWith('2') || normalized.startsWith('3')) &&
      !/^2001:(?:0|1|2|db8):/.test(normalized)
  }

  return false
}

async function isPublicUrl(value) {
  let parsed
  try {
    parsed = new URL(value)
  } catch {
    return false
  }
  if (!['http:', 'https:'].includes(parsed.protocol)) return false

  const host = parsed.hostname.toLowerCase().replace(/\.$/, '')
  if (!host.includes('.') || /(^|\.)((localhost)|(local)|(internal)|(test))$/.test(host)) return false
  try {
    const addresses = isIP(host) ? [{ address: host }] : await dns.lookup(host, { all: true })
    return addresses.length > 0 && addresses.every(({ address }) => isPublicAddress(address))
  } catch {
    return false
  }
}

async function main() {
  const input = JSON.parse(await new Promise((resolve, reject) => {
    let body = ''
    process.stdin.setEncoding('utf8')
    process.stdin.on('data', (chunk) => { body += chunk })
    process.stdin.on('end', () => resolve(body))
    process.stdin.on('error', reject)
  }))

  if (!await isPublicUrl(input.url)) throw new Error('Blocked non-public browser URL')

  const headless = Boolean(input.headless)
  const args = ['--no-sandbox']
  if (!headless) args.push('--start-minimized')

  let browser
  if (input.channel === 'chrome') {
    try {
      browser = await chromium.launch({ headless, channel: 'chrome', args })
    } catch (error) {
      if (!/executable|chrome.*not found|browser.*not found/i.test(error.message)) throw error
      process.stderr.write('Chrome no está disponible; se usará Chromium de Playwright.\n')
    }
  }
  if (!browser) browser = await chromium.launch({ headless, args })

  try {
    const context = await browser.newContext({
      locale: 'es-MX',
      timezoneId: 'America/Merida',
      viewport: { width: 1440, height: 1000 },
      userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36',
    })
    const page = await context.newPage()
    let mainFrameRedirects = 0
    await page.route('**/*', async (route) => {
      const request = route.request()
      if (request.isNavigationRequest() && request.frame() === page.mainFrame() && request.redirectedFrom()) {
        mainFrameRedirects += 1
        if (mainFrameRedirects > 5) {
          await route.abort()
          return
        }
      }
      if (['image', 'font', 'media'].includes(request.resourceType())) {
        await route.abort()
        return
      }
      if (['http:', 'https:'].includes(new URL(request.url()).protocol) && !await isPublicUrl(request.url())) {
        await route.abort()
        return
      }
      await route.continue()
    })

    let response
    try {
      response = await page.goto(input.url, { waitUntil: 'domcontentloaded', timeout: 20000 })
    } catch (error) {
      if (page.url() === 'about:blank') throw error
    }
    await page.waitForTimeout(3000)
    const result = {
      html: await page.content(),
      url: page.url() || input.url,
      status: response ? response.status() : 200,
    }
    if (input.capture) {
      result.screenshot = (await page.screenshot({ type: 'png' })).toString('base64')
    }
    process.stdout.write(JSON.stringify(result))
  } finally {
    await browser.close()
  }
}

main().catch((error) => {
  process.stderr.write(`${error.message}\n`)
  process.exitCode = 1
})
