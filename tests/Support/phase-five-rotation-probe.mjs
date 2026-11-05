import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import https from 'node:https'
import tls from 'node:tls'

assert.equal(process.env.MULTILIDER_TEST_ISOLATED, '1')
assert.equal(process.env.Operations__Dataset, 'isolated-f5')
const probes = JSON.parse(process.env.MULTILIDER_F5_ROTATION_PROBES)
const expectedErrors = new Set(['ECONNRESET', 'ERR_SSL_TLSV13_ALERT_CERTIFICATE_REQUIRED',
  'ERR_SSL_SSLV3_ALERT_BAD_CERTIFICATE', 'ERR_SSL_SSLV3_ALERT_CERTIFICATE_EXPIRED',
  'ERR_SSL_TLSV1_ALERT_UNKNOWN_CA', 'ERR_SSL_TLSV1_ALERT_DECRYPT_ERROR'])

function probe({ target, port, certificate, allowed, operation, serverPin }) {
  assert.match(operation, /^[a-f0-9-]{36}$/)
  assert.match(serverPin, /^[A-F0-9]{64}$/)
  assert.ok(Number.isInteger(port) && port > 1023)
  const paths = {
    seguridad: '/internal/operations/v1/authorize',
    propiedades: '/internal/catalogo/v2/operaciones/' + operation + '/reserva',
    comercial: '/internal/comercial/v1/operaciones/' + operation + '/snapshot',
    finanzas: '/internal/finanzas/v1/operaciones/' + operation,
  }
  assert.ok(Object.hasOwn(paths, target))
  const post = target === 'seguridad'
  const body = post ? JSON.stringify({ operation_id: operation, action: 'read' }) : null
  return new Promise((resolve, reject) => {
    let matchedPeer = false
    const request = https.request({
      hostname: '127.0.0.1', port, path: paths[target], method: post ? 'POST' : 'GET',
      agent: false, rejectUnauthorized: false, pfx: readFileSync(certificate),
      passphrase: process.env.Operations__ClientCertificatePassword,
      headers: post ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) } : {},
    }, response => {
      response.resume()
      if (!matchedPeer) return reject(new Error('Peer fingerprint or SAN mismatch'))
      if (response.statusCode !== (allowed ? 200 : 403)) {
        return reject(new Error(target + ' returned HTTP ' + response.statusCode + ' for ' + (allowed ? 'trusted' : 'denied') + ' fixture'))
      }
      resolve()
    })
    request.on('socket', socket => socket.once('secureConnect', () => {
      const peer = socket.getPeerCertificate()
      matchedPeer = peer.fingerprint256?.replaceAll(':', '') === serverPin && !tls.checkServerIdentity('127.0.0.1', peer)
      if (!matchedPeer) request.destroy(new Error('Peer fingerprint or SAN mismatch'))
    }))
    request.on('error', error => {
      if (!allowed && expectedErrors.has(error.code)) resolve()
      else reject(error)
    })
    request.setTimeout(5000, () => request.destroy(new Error('TLS fixture request timed out')))
    request.end(body)
  })
}

await Promise.all(probes.map(probe))
console.log('F5-A10: ' + probes.length + ' real TLS client/pin probes passed.')
