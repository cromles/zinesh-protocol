/**
 * send-evm-usdt.mjs stdout protokol testleri.
 *
 * Tamamen izole: 'ethers' bir stub ile değiştirilir, gerçek RPC'ye bağlanılmaz,
 * gerçek özel anahtar kullanılmaz, zincire hiçbir işlem gönderilmez.
 *
 * Çalıştırma: node --test api/scripts/__tests__/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const SCRIPT = path.join(here, '..', 'send-evm-usdt.mjs');
const REGISTER = pathToFileURL(path.join(here, 'register-ethers-stub.mjs')).href;

const PAYLOAD = {
  privateKey: '0xtest-not-a-real-key',
  toAddress: '0x000000000000000000000000000000000000dEaD',
  amount: 12.5,
  network: 'arbitrum',
  rpcUrl: 'http://127.0.0.1:1/mock-rpc-never-contacted',
  usdtContract: '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9',
};

const EXPECTED_HASH = '0x' + 'ab'.repeat(32);

/**
 * Script'i alt süreç olarak çalıştırır ve stdout'u zaman damgalı satırlar hâlinde toplar.
 * onLine, kill senaryoları için satır geldiği anda çağrılır.
 */
function runScript({ scenario = 'ok', protocol = null, waitMs = 0, onLine = null } = {}) {
  return new Promise((resolve) => {
    const env = {
      ...process.env,
      MOCK_SCENARIO: scenario,
      MOCK_WAIT_MS: String(waitMs),
    };
    if (protocol) {
      env.ZINESH_SEND_PROTOCOL = protocol;
    } else {
      delete env.ZINESH_SEND_PROTOCOL;
    }

    const child = spawn(process.execPath, ['--import', REGISTER, SCRIPT], { env });

    const startedAt = Date.now();
    const lines = [];
    let raw = '';
    let stderr = '';
    let pending = '';
    let killed = false;

    child.stdout.on('data', (chunk) => {
      const text = chunk.toString('utf8');
      raw += text;
      pending += text;
      let idx;
      while ((idx = pending.indexOf('\n')) !== -1) {
        const line = pending.slice(0, idx);
        pending = pending.slice(idx + 1);
        if (line.trim() === '') continue;
        const entry = { line, at: Date.now() - startedAt };
        lines.push(entry);
        if (onLine) {
          onLine(entry, () => {
            killed = true;
            child.kill('SIGKILL');
          });
        }
      }
    });

    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString('utf8');
    });

    child.on('close', (code, signal) => {
      resolve({
        raw,
        stderr,
        exitCode: code,
        signal,
        killed,
        lines,
        events: lines.map((entry) => ({ ...JSON.parse(entry.line), at: entry.at })),
      });
    });

    child.stdin.write(JSON.stringify(PAYLOAD));
    child.stdin.end();
  });
}

test('TEST 1 — broadcast event confirmation event\'inden önce gelir', async () => {
  const res = await runScript({ scenario: 'ok', protocol: 'v2' });

  assert.equal(res.events.length, 2, 'iki event beklenir');

  const [first, second] = res.events;
  assert.equal(first.stage, 'broadcast');
  assert.equal(first.txHash, EXPECTED_HASH, 'broadcast event hash taşımalı');
  assert.equal(first.ok, undefined, 'broadcast event başarı iddia etmemeli');

  assert.equal(second.stage, 'confirmed');
  assert.equal(second.ok, true);
  assert.equal(second.txHash, EXPECTED_HASH);
  assert.equal(second.receiptStatus, 1);

  assert.ok(res.events.indexOf(first) < res.events.indexOf(second), 'sıra: broadcast → confirmed');
  assert.equal(res.exitCode, 0);
});

test('TEST 2 — uzun confirmation broadcast event\'ini geciktirmez', async () => {
  const waitMs = 1500;
  const res = await runScript({ scenario: 'ok', protocol: 'v2', waitMs });

  const broadcast = res.events.find((e) => e.stage === 'broadcast');
  const confirmed = res.events.find((e) => e.stage === 'confirmed');

  assert.ok(broadcast, 'broadcast event üretilmeli');
  assert.ok(confirmed, 'confirmed event üretilmeli');
  assert.equal(broadcast.txHash, EXPECTED_HASH);

  assert.ok(
    confirmed.at - broadcast.at >= waitMs - 250,
    `confirmation gecikmesi broadcast'e yansımamalı (broadcast ${broadcast.at}ms, confirmed ${confirmed.at}ms)`
  );
  assert.ok(
    broadcast.at < waitMs,
    `broadcast, onay beklemesi bitmeden çıkmalı (${broadcast.at}ms < ${waitMs}ms)`
  );
});

test('TEST 3 — broadcast sonrası kill: hash zaten stdout\'a ulaşmış olur', async () => {
  const res = await runScript({
    scenario: 'hang',
    protocol: 'v2',
    onLine: (entry, kill) => {
      if (JSON.parse(entry.line).stage === 'broadcast') {
        kill();
      }
    },
  });

  assert.ok(res.killed, 'süreç öldürülmüş olmalı');

  const broadcast = res.events.find((e) => e.stage === 'broadcast');
  assert.ok(broadcast, 'kill öncesi broadcast event alınmış olmalı');
  assert.equal(broadcast.txHash, EXPECTED_HASH, 'hash kill\'den önce okunabilir olmalı');
  assert.ok(
    !res.events.some((e) => e.stage === 'confirmed'),
    'onay alınmadan öldürüldüğü için confirmed event olmamalı'
  );
});

test('TEST 4 — broadcast başarısız: broadcast event üretilmez', async () => {
  const res = await runScript({ scenario: 'broadcast_fail', protocol: 'v2' });

  assert.ok(
    !res.events.some((e) => e.stage === 'broadcast'),
    'zincire hiçbir şey gitmediyse broadcast event olmamalı'
  );
  assert.ok(!res.events.some((e) => e.stage === 'confirmed'));

  assert.equal(res.events.length, 1);
  assert.equal(res.events[0].stage, 'broadcast_failed');
  assert.equal(res.events[0].ok, false);
  assert.equal(res.events[0].txHash, undefined, 'hash yokken hash bildirilmemeli');
  assert.equal(res.exitCode, 1, 'açık failure exit kodu');
});

test('TEST 5 — receipt revert: broadcast korunur, başarı raporlanmaz', async () => {
  const res = await runScript({ scenario: 'revert', protocol: 'v2' });

  const broadcast = res.events.find((e) => e.stage === 'broadcast');
  assert.ok(broadcast, 'broadcast event mevcut olmalı');
  assert.equal(broadcast.txHash, EXPECTED_HASH);

  const final = res.events[res.events.length - 1];
  assert.equal(final.stage, 'confirm_failed');
  assert.equal(final.ok, false, 'revert asla success raporlanmamalı');
  assert.equal(final.receiptStatus, 0);
  assert.equal(final.txHash, EXPECTED_HASH, 'hata çıktısı broadcast hash\'ini yok etmemeli');
  assert.equal(res.exitCode, 1);
});

test('TEST 5b — wait() throw ederse de hash korunur', async () => {
  const res = await runScript({ scenario: 'wait_throw', protocol: 'v2' });

  assert.ok(res.events.some((e) => e.stage === 'broadcast'));

  const final = res.events[res.events.length - 1];
  assert.equal(final.stage, 'confirm_failed');
  assert.equal(final.ok, false);
  assert.equal(final.txHash, EXPECTED_HASH);
  assert.equal(res.exitCode, 1);
});

test('TEST 5c — receipt status okunamıyorsa ethers throw davranışına güvenilmez', async () => {
  const res = await runScript({ scenario: 'receipt_null_status', protocol: 'v2' });

  const final = res.events[res.events.length - 1];
  assert.equal(final.stage, 'confirmed');
  assert.equal(final.receiptStatus, null, 'bilinmeyen status açıkça null olarak bildirilmeli');
});

test('TEST 5d — yayın sonucu belirsizse hash yine de bildirilir', async () => {
  const res = await runScript({ scenario: 'broadcast_uncertain', protocol: 'v2' });

  assert.ok(
    !res.events.some((e) => e.stage === 'broadcast'),
    'yayın doğrulanmadığı için stage=broadcast üretilmemeli'
  );

  const final = res.events[res.events.length - 1];
  assert.equal(final.stage, 'broadcast_uncertain');
  assert.equal(final.ok, false);
  assert.equal(final.txHash, EXPECTED_HASH, 'para gitmiş olabileceği için hash kaybedilmemeli');
  assert.equal(res.exitCode, 1);
});

test('TEST 6 — chunked stdout: satır tabanlı okuyucu event\'leri eksiksiz kurar', async () => {
  const res = await runScript({ scenario: 'ok', protocol: 'v2' });

  for (const { line } of res.lines) {
    assert.ok(!line.includes('\n'), 'event içinde gömülü newline olmamalı');
    assert.doesNotThrow(() => JSON.parse(line), 'her satır tek başına parse edilebilmeli');
  }
  assert.ok(res.raw.endsWith('\n'), 'son event de newline ile sonlanmalı');

  // stdout'u bayt bayt vererek artımlı okuyucuyu simüle et.
  const recovered = [];
  let buffer = '';
  for (const byte of Buffer.from(res.raw, 'utf8')) {
    buffer += String.fromCharCode(byte);
    let idx;
    while ((idx = buffer.indexOf('\n')) !== -1) {
      const line = buffer.slice(0, idx);
      buffer = buffer.slice(idx + 1);
      if (line.trim() !== '') recovered.push(JSON.parse(line));
    }
  }

  assert.equal(recovered.length, 2, 'parçalı okumada da iki event kurulmalı');
  assert.equal(recovered[0].stage, 'broadcast');
  assert.equal(recovered[0].txHash, EXPECTED_HASH);
  assert.equal(recovered[1].stage, 'confirmed');
  assert.equal(recovered[1].ok, true);
});

test('REGRESYON — legacy mod PHP json_decode($stdout) ile uyumlu kalır', async () => {
  const res = await runScript({ scenario: 'ok' });

  // zinesh_run_node_script() tüm stdout'u tek JSON olarak decode eder.
  const decoded = JSON.parse(res.raw);
  assert.equal(decoded.ok, true);
  assert.equal(decoded.txHash, EXPECTED_HASH);
  assert.equal(decoded.stage, undefined, 'legacy çıktı yeni alan sızdırmamalı');
  assert.equal(res.lines.length, 1, 'legacy modda tek satır çıkmalı');
  assert.equal(res.exitCode, 0);
});

test('REGRESYON — legacy mod hata çıktısı değişmez', async () => {
  const res = await runScript({ scenario: 'broadcast_fail' });

  const decoded = JSON.parse(res.raw);
  assert.equal(decoded.ok, false);
  assert.equal(typeof decoded.error, 'string');
  assert.equal(res.lines.length, 1);
  assert.equal(res.exitCode, 1);
});

test('KORUMA — v2 çıktısı legacy PHP parser\'ı ile okunamaz, bu yüzden env kapısı şarttır', async () => {
  const res = await runScript({ scenario: 'ok', protocol: 'v2' });

  // zinesh_run_node_script() json_decode($stdout) yapar; NDJSON bunu null'a düşürür
  // ve başarılı bir gönderim "başarısız" sayılarak cron tarafından tekrar denenir.
  // v2, PHP okuyucusu satır tabanlına geçmeden varsayılan olmamalıdır.
  assert.throws(() => JSON.parse(res.raw), 'v2 çıktısı tek JSON olarak decode EDİLEMEZ');
});

test('REGRESYON — legacy modda revert başarı olarak raporlanmaz', async () => {
  const res = await runScript({ scenario: 'revert' });

  const decoded = JSON.parse(res.raw);
  assert.equal(decoded.ok, false, 'receipt status 0 legacy modda da başarı sayılmamalı');
  assert.equal(res.exitCode, 1);
});
