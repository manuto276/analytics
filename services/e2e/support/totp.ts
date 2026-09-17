import { createHmac } from 'node:crypto'

/**
 * Minimal RFC 6238 TOTP (SHA-1, 6 digits, 30 s), enough to log in as a user who
 * enrolled an authenticator app during the suite. Implemented here instead of
 * pulling in a dependency: the algorithm is ten lines and stays deterministic.
 */
export function base32Decode(secret: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const clean = secret.replace(/[\s=]/g, '').toUpperCase()
  let bits = 0
  let value = 0
  const out: number[] = []
  for (const char of clean) {
    const index = alphabet.indexOf(char)
    if (index === -1) throw new Error(`invalid base32 character: ${char}`)
    value = (value << 5) | index
    bits += 5
    if (bits >= 8) {
      bits -= 8
      out.push((value >>> bits) & 0xff)
    }
  }
  return Buffer.from(out)
}

export function totpCode(secret: string, at: Date = new Date(), period = 30, digits = 6): string {
  const counter = Math.floor(at.getTime() / 1000 / period)
  const buffer = Buffer.alloc(8)
  buffer.writeUInt32BE(Math.floor(counter / 2 ** 32), 0)
  buffer.writeUInt32BE(counter >>> 0, 4)

  const digest = createHmac('sha1', base32Decode(secret)).update(buffer).digest()
  const offset = digest[digest.length - 1]! & 0x0f
  const binary = ((digest[offset]! & 0x7f) << 24)
    | ((digest[offset + 1]! & 0xff) << 16)
    | ((digest[offset + 2]! & 0xff) << 8)
    | (digest[offset + 3]! & 0xff)

  return String(binary % 10 ** digits).padStart(digits, '0')
}

let lastStep = -1

/**
 * A code the backend will accept: valid for at least `minSeconds` (so a slow
 * form fill cannot land in the next time step) and never from a step this
 * process already used, because TOTP verification is replay-protected
 * (`last_used_step`) — enrolling and signing in within the same 30 s window
 * would otherwise be rejected.
 */
export async function freshTotpCode(secret: string, minSeconds = 5): Promise<string> {
  for (;;) {
    const now = Math.floor(Date.now() / 1000)
    const step = Math.floor(now / 30)
    const secondsLeft = 30 - (now % 30)

    if (step !== lastStep && secondsLeft >= minSeconds) {
      lastStep = step
      return totpCode(secret)
    }
    // The server accepts ±1 step, so the next step's code is valid right away
    // and counts as a different step: no need to wait out the window.
    if (step + 1 !== lastStep) {
      lastStep = step + 1
      return totpCode(secret, new Date((step + 1) * 30_000))
    }
    await new Promise(resolve => setTimeout(resolve, (secondsLeft + 1) * 1000))
  }
}
