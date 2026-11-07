import { createHash, randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { performance } from 'node:perf_hooks'
import { availableParallelism, totalmem } from 'node:os'

let input = ''
for await (const chunk of process.stdin) input += chunk
const config = JSON.parse(input)
const samples = []
const failures = []
const phases = {}
let inFlight = 0
let maxInFlight = 0
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))

class Client {
  constructor(user, index) {
    this.user = user
    this.index = index
    this.cookies = new Map()
    this.csrf = null
    this.ready = false
    this.iteration = 0
    this.transition = 0
  }

  async request(phase, operation, path, method = 'GET', data = undefined, expected = [200], csrf = true) {
    const headers = {
      Accept: 'application/json', Origin: config.base, Referer: `${config.base}/`,
      'X-EA-Load-IP': this.user.ip,
      Cookie: [...this.cookies].map(([name, value]) => `${name}=${value}`).join('; '),
    }
    if (data !== undefined) headers['Content-Type'] = 'application/json'
    if (csrf && this.csrf) headers['X-CSRF-TOKEN'] = this.csrf
    const start = performance.now()
    inFlight++
    maxInFlight = Math.max(maxInFlight, inFlight)
    let status = 0
    let body = ''
    let parsed = null
    try {
      const response = await fetch(`${config.base}${path}`, {
        method, headers, redirect: 'manual', signal: AbortSignal.timeout(20000),
        ...(data === undefined ? {} : { body: JSON.stringify(data) }),
      })
      status = response.status
      for (const cookie of response.headers.getSetCookie()) {
        const pair = cookie.split(';', 1)[0]
        const separator = pair.indexOf('=')
        this.cookies.set(pair.slice(0, separator), pair.slice(separator + 1))
      }
      body = await response.text()
      if (response.headers.get('content-type')?.includes('application/json')) {
        parsed = JSON.parse(body)
        if (parsed.csrf_token) this.csrf = parsed.csrf_token
      } else {
        const token = body.match(/name="csrf-token"\s+content="([^"]+)"/)
        if (token) this.csrf = token[1]
      }
    } catch (error) {
      failures.push({ phase, operation, role: this.user.role, client: this.index, error: error.name })
    } finally {
      inFlight--
    }
    const sample = {
      phase, operation, role: this.user.role, method, status,
      milliseconds: Math.round((performance.now() - start) * 10) / 10,
      bytes: Buffer.byteLength(body), unexpected: !expected.includes(status),
    }
    samples.push(sample)
    if (sample.unexpected) {
      failures.push({ phase, operation, role: this.user.role, client: this.index, status, message: parsed?.message ?? body.slice(0, 80) })
    }
    return { status, data: parsed, body }
  }

  async authenticate() {
    if (this.user.role === 'customer') {
      await this.request('authentication', 'customer.csrf', '/api/v1/customer/csrf')
      const response = await this.request('authentication', 'customer.login', '/api/v1/customer/auth/login', 'POST', {
        email: this.user.email, password: config.password,
      })
      this.ready = response.status === 200
      return
    }
    await this.request('authentication', 'rider.csrf', '/login')
    const response = await this.request('authentication', 'rider.login', '/login', 'POST', {
      email: this.user.email, password: config.password,
    }, [302])
    if (response.status !== 302) return
    await this.request('authentication', 'rider.otpPage', '/verify-email-otp')
    const hash = createHash('sha256').update(this.user.email).digest('hex')
    let code = null
    for (let attempt = 0; attempt < 80; attempt++) {
      try {
        code = JSON.parse(await readFile(`${config.directory}/otp/${hash}.json`, 'utf8')).code
        break
      } catch {
        await sleep(25)
      }
    }
    if (!code) {
      failures.push({ phase: 'authentication', operation: 'rider.otpTransport', client: this.index, error: 'FakeOtpNotCaptured' })
      return
    }
    const verified = await this.request('authentication', 'rider.verifyOtp', '/verify-email-otp/confirm', 'POST', { code }, [302])
    const dashboard = await this.request('authentication', 'rider.dashboard', '/dashboard')
    this.ready = verified.status === 302 && dashboard.status === 200
  }

  async step(phase) {
    const allowed = phase === 'pressure' ? [200, 429] : [200]
    if (this.user.role === 'customer') {
      const order = this.user.orders[0]
      switch (this.iteration++ % 7) {
        case 0: return this.request(phase, 'customer.dashboard', '/api/v1/customer/dashboard', 'GET', undefined, allowed)
        case 1: return this.request(phase, 'customer.orders', '/api/v1/customer/orders', 'GET', undefined, allowed)
        case 2: return this.request(phase, 'customer.quote', '/api/v1/customer/rates/quote?city=Caserta&postal_code=81100', 'GET', undefined, allowed)
        case 3: return this.request(phase, 'customer.statistics', '/api/v1/customer/statistics', 'GET', undefined, allowed)
        case 4: return this.iteration % 2 === 0
          ? this.request(phase, 'customer.messages', `/api/v1/customer/orders/${order}/messages`, 'GET', undefined, allowed)
          : this.request(phase, 'customer.detail', `/api/v1/customer/orders/${order}`, 'GET', undefined, allowed)
        case 5: return this.request(phase, 'customer.notifications', '/api/v1/customer/notifications/feed', 'GET', undefined, allowed)
        default: return this.request(phase, 'customer.sendMessage', `/api/v1/customer/orders/${order}/messages`, 'POST', {
          body: `Messaggio sintetico ${this.index}-${this.iteration}`, submission_key: randomUUID(),
        }, phase === 'pressure' ? [201, 429] : [201])
      }
    }
    const order = this.user.orders[Math.min(Math.floor(this.transition / 5), this.user.orders.length - 1)]
    switch (this.iteration++ % 4) {
      case 0: return this.request(phase, 'rider.dashboard', '/dashboard', 'GET', undefined, allowed)
      case 1: return this.request(phase, 'rider.orders', '/orders/in-progress', 'GET', undefined, allowed)
      case 2: return this.request(phase, 'rider.detail', `/orders/${order}`, 'GET', undefined, allowed)
      default: {
        if (this.transition >= this.user.orders.length * 5) {
          return this.request(phase, 'rider.dashboard', '/dashboard', 'GET', undefined, allowed)
        }
        const next = ['rider_arriving', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered'][this.transition % 5]
        const response = await this.request(phase, 'rider.transition', `/orders/${order}`, 'PATCH', {
          version: this.transition % 5 + 1, status: next,
        }, allowed)
        if (response.status === 200) this.transition++
        return response
      }
    }
  }
}

function summarize(items, seconds) {
  const sorted = items.map(sample => sample.milliseconds).sort((a, b) => a - b)
  const quantile = p => sorted.length ? sorted[Math.max(0, Math.ceil(sorted.length * p) - 1)] : null
  const statuses = {}
  for (const sample of items) statuses[sample.status] = (statuses[sample.status] ?? 0) + 1
  return {
    requests: items.length, seconds: Math.round(seconds * 1000) / 1000,
    throughput_per_second: seconds ? Math.round(items.length / seconds * 100) / 100 : null,
    p50_ms: quantile(0.5), p95_ms: quantile(0.95), p99_ms: quantile(0.99), statuses,
    unexpected_errors: items.filter(sample => sample.unexpected).length,
    rate_limited: statuses[429] ?? 0,
    unexpected_error_percent: items.length ? Math.round(items.filter(sample => sample.unexpected).length / items.length * 10000) / 100 : 0,
  }
}

async function timed(name, work) {
  const start = performance.now()
  await work()
  const seconds = (performance.now() - start) / 1000
  const items = samples.filter(sample => sample.phase === name)
  const operations = [...new Set(items.map(sample => sample.operation))]
  phases[name] = {
    ...summarize(items, seconds),
    reads: summarize(items.filter(sample => sample.method === 'GET'), seconds),
    writes: summarize(items.filter(sample => sample.method !== 'GET'), seconds),
    operations: Object.fromEntries(operations.map(operation => [operation, summarize(items.filter(sample => sample.operation === operation), seconds)])),
  }
}

const clients = config.users.map((user, index) => new Client(user, index))
await timed('authentication', () => Promise.all(clients.map(client => client.authenticate())))
const duplicateKey = randomUUID()
if (clients.every(client => client.ready)) {
  await timed('controls', async () => {
    const first = clients[0]
    const order = first.user.orders[0]
    await first.request('controls', 'csrf.rejected', `/api/v1/customer/orders/${order}/messages`, 'POST', { body: 'Richiesta da rifiutare' }, [419], false)
    await first.request('controls', 'ownership.customer', `/api/v1/customer/orders/${clients[1].user.orders[0]}`, 'GET', undefined, [404])
    await clients[40].request('controls', 'ownership.rider', `/orders/${clients[41].user.orders[0]}`, 'PATCH', { status: 'rider_arriving', version: 1 }, [403])
    for (let repeat = 0; repeat < 2; repeat++) {
      await first.request('controls', 'message.idempotence', `/api/v1/customer/orders/${order}/messages`, 'POST', {
        body: 'Messaggio sintetico idempotente', submission_key: duplicateKey,
      }, [201])
    }
  })
  for (const [name, seconds, think] of [
    ['baseline', config.baseline_seconds, config.think_ms], ['pressure', config.pressure_seconds, 0],
  ]) {
    await timed(name, async () => {
      const end = performance.now() + seconds * 1000
      await Promise.all(clients.map(async client => {
        while (performance.now() < end) {
          await client.step(name)
          if (think) await sleep(think)
        }
      }))
    })
  }
  await timed('shared_nat', async () => {
    const nat = new Client({ role: 'customer', ip: '203.0.113.25' }, 45)
    await nat.request('shared_nat', 'nat.csrf', '/api/v1/customer/csrf')
    for (let attempt = 1; attempt <= 21; attempt++) {
      await nat.request('shared_nat', 'nat.failedLogin', '/api/v1/customer/auth/login', 'POST', {
        email: `load-nat-${attempt}@example.test`, password: config.password,
      }, attempt === 21 ? [429] : [422])
    }
  })
}
process.stdout.write(JSON.stringify({
  node_version: process.version, hardware: { logical_cpus: availableParallelism(), ram_bytes: totalmem() },
  authenticated_users: clients.filter(client => client.ready).length,
  maximum_simultaneous_http_requests: maxInFlight,
  baseline_seconds: config.baseline_seconds, pressure_seconds: config.pressure_seconds,
  think_ms: config.think_ms, duplicate_submission_key: duplicateKey,
  phases, failures, samples,
}))
