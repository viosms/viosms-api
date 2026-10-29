// Buy a virtual number on VioSMS, wait for the SMS verification code and
// cancel (refund) if it doesn't arrive. Node.js 18+, no dependencies.
//
//   export VIOSMS_API_KEY=your_key
//   node viosms.mjs wa 16          # WhatsApp, United Kingdom
//   node viosms.mjs --services
//   node viosms.mjs --countries

const BASE_URL = 'https://vio-sms.com/api/v1';
const POLL_EVERY_MS = 5_000;
const GIVE_UP_AFTER_MS = 5 * 60_000;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export class VioSMSError extends Error {
  constructor(status, body, retryAfter) {
    super(`HTTP ${status}: ${body.error ?? JSON.stringify(body)}`);
    this.status = status;
    this.body = body;
    this.retryAfter = retryAfter;
  }
}

export class VioSMS {
  constructor(apiKey, baseUrl = BASE_URL) {
    this.apiKey = apiKey;
    this.baseUrl = baseUrl.replace(/\/+$/, '');
  }

  async request(method, path, body) {
    const res = await fetch(this.baseUrl + path, {
      method,
      headers: {
        Authorization: `Bearer ${this.apiKey}`,
        ...(body ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(30_000),
    });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch { data = { error: text }; }
    if (!res.ok) {
      throw new VioSMSError(res.status, data, Number(res.headers.get('retry-after') || 0));
    }
    return data;
  }

  async balance() { return (await this.request('GET', '/balance')).balance; }
  async services() { return (await this.request('GET', '/services')).data; }
  async countries() { return (await this.request('GET', '/countries')).data; }

  async buy(service, country, operator = 'any') {
    return (await this.request('POST', '/buy', { service, country: String(country), operator })).data;
  }

  async status(id) {
    return (await this.request('GET', `/status?id=${encodeURIComponent(id)}`)).data;
  }

  cancel(id) { return this.request('POST', '/cancel', { id }); }

  // Polls /status until the activation leaves WAITING. Returns null on timeout.
  async waitForCode(id, { pollEveryMs = POLL_EVERY_MS, giveUpAfterMs = GIVE_UP_AFTER_MS } = {}) {
    const deadline = Date.now() + giveUpAfterMs;
    while (Date.now() < deadline) {
      const activation = await this.status(id);
      if (activation.status !== 'WAITING') return activation;
      await sleep(pollEveryMs);
    }
    return null;
  }

  // Cancelling is allowed 2 minutes after buying; waits out Retry-After if needed.
  async cancelWithRetry(id) {
    for (let attempt = 0; attempt < 5; attempt++) {
      try {
        return await this.cancel(id);
      } catch (err) {
        if (!(err instanceof VioSMSError) || ![400, 503].includes(err.status)) throw err;
        if (err.status === 400 && !err.retryAfter) throw err;
        await sleep((err.retryAfter || 5) * 1000);
      }
    }
    throw new Error(`could not cancel activation ${id}`);
  }
}

async function main() {
  const apiKey = process.env.VIOSMS_API_KEY;
  if (!apiKey) throw new Error('Set VIOSMS_API_KEY first (Profile page on vio-sms.com).');

  const client = new VioSMS(apiKey);
  const [first, second] = process.argv.slice(2);

  if (first === '--services') {
    for (const s of await client.services()) console.log(s.code.padEnd(8), s.name.padEnd(30), s.price);
    return;
  }
  if (first === '--countries') {
    for (const c of await client.countries()) console.log(c.code.padEnd(6), c.name);
    return;
  }
  if (!first || !second) {
    console.log('Usage: node viosms.mjs <service> <country>   e.g. node viosms.mjs wa 16');
    return;
  }

  console.log(`Balance: $${(await client.balance()).toFixed(2)}`);

  let number;
  try {
    number = await client.buy(first, second);
  } catch (err) {
    if (err.status === 402) return console.log(`Not enough balance: need $${err.body.required}, have $${err.body.balance}`);
    if (err.status === 403 && err.body.error === 'service_blocked') return console.log(`Service blocked for ${err.retryAfter}s after repeated failed activations.`);
    if (err.status === 500) return console.log('No numbers in stock for this service/country. Try another country (nothing was charged).');
    throw err;
  }

  console.log(`Number: ${number.phone}  (id ${number.id}, $${number.price}, valid until ${number.expiresAt})`);
  console.log('Enter this number in the app, then wait...');

  const activation = await client.waitForCode(number.id);
  if (activation?.status === 'CANCELLED') return console.log('The activation expired and was refunded.');
  if (activation) {
    console.log(`Code: ${activation.smsCode}`);
    console.log(`Full SMS: ${activation.smsText}`);
    return;
  }

  console.log('No code yet, cancelling for a refund...');
  await client.cancelWithRetry(number.id);
  console.log('Cancelled, the price is back on your balance.');
}

// Run main() only when executed directly, not when imported as a module.
if (process.argv[1]?.endsWith('viosms.mjs')) {
  main().catch((err) => { console.error(err.message); process.exitCode = 1; });
}
