#!/usr/bin/env node
/**
 * Bağımlılıksız sahte EVM gönderici (yalnız test).
 * Gerçek RPC'ye bağlanmaz, gerçek anahtar kullanmaz, zincire işlem göndermez.
 *
 * Davranış ortam değişkenleriyle seçilir:
 *   MOCK_CASE          senaryo adı
 *   MOCK_HASH          bildirilecek transaction hash
 *   MOCK_DELAY_MS      broadcast ile confirmation arasındaki gecikme
 *   MOCK_PRE_DELAY_MS  broadcast'ten önceki gecikme (crash penceresi testleri)
 *   MOCK_COUNTER_FILE  her broadcast için bir satır eklenir (gönderim sayısı kanıtı)
 */
import fs from 'node:fs';

const CASE = process.env.MOCK_CASE || 'broadcast_then_confirm';
const HASH = process.env.MOCK_HASH || '0x' + 'ab'.repeat(32);
const DELAY = Number(process.env.MOCK_DELAY_MS || 0);
const COUNTER = process.env.MOCK_COUNTER_FILE || '';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const write = (s) => new Promise((r) => process.stdout.write(s, () => r()));
const emit = (obj) => write(JSON.stringify(obj) + '\n');

async function readStdin() {
  return new Promise((resolve) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (c) => (data += c));
    process.stdin.on('end', () => resolve(data));
  });
}

/** Zincire çıkan her gerçek broadcast burada sayılır. */
function countBroadcast() {
  if (COUNTER) {
    fs.appendFileSync(COUNTER, `${CASE}\n`);
  }
}

async function main() {
  await readStdin();

  const preDelay = Number(process.env.MOCK_PRE_DELAY_MS || 0);
  if (preDelay > 0) {
    await sleep(preDelay);
  }

  if (CASE === 'broadcast_fail') {
    await emit({ ok: false, stage: 'broadcast_failed', error: 'mock: broadcast reddedildi' });
    process.exit(1);
  }

  if (CASE === 'legacy_ok') {
    countBroadcast();
    await emit({ ok: true, txHash: HASH });
    return;
  }

  if (CASE === 'legacy_tron') {
    countBroadcast();
    await emit({ ok: true, txId: HASH });
    return;
  }

  if (CASE === 'chunked') {
    countBroadcast();
    // Satırı bayt bayt yaz: PHP okuyucusu newline görene kadar tamponlamalı.
    const line = JSON.stringify({ stage: 'broadcast', txHash: HASH });
    for (const ch of line) {
      await write(ch);
      await sleep(2);
    }
    await write('\n');
    await sleep(DELAY);
    await emit({ ok: true, stage: 'confirmed', txHash: HASH, receiptStatus: 1, blockNumber: 42 });
    return;
  }

  // Aşağıdaki tüm senaryolar önce broadcast event'i yayımlar.
  countBroadcast();
  await emit({ stage: 'broadcast', txHash: HASH });

  if (CASE === 'broadcast_then_hang') {
    // Bekleyen bir zamanlayıcı gerekir: çözülmeyen Promise event loop'u canlı tutmaz
    // ve süreç kendiliğinden çıkar, timeout dalı hiç denenmezdi.
    // PHP tarafındaki timeout (saniyeler) çok daha kısa; bu süre yalnızca
    // sürecin kendiliğinden çıkmamasını garanti eder.
    await sleep(20_000);
  }

  await sleep(DELAY);

  if (CASE === 'broadcast_then_revert') {
    await emit({
      ok: false,
      stage: 'confirm_failed',
      txHash: HASH,
      receiptStatus: 0,
      error: 'mock: işlem zincirde başarısız',
    });
    process.exit(1);
  }

  if (CASE === 'broadcast_then_unknown_status') {
    await emit({ ok: true, stage: 'confirmed', txHash: HASH, receiptStatus: null, blockNumber: 42 });
    return;
  }

  await emit({ ok: true, stage: 'confirmed', txHash: HASH, receiptStatus: 1, blockNumber: 42 });
}

main();
