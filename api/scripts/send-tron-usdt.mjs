#!/usr/bin/env node
/**
 * TRON TRC20 USDT çekimi.
 * stdin: { privateKey, toAddress, amount, apiKey? }
 * stdout: { ok, txId? , error? }
 */
import { TronWeb } from 'tronweb';

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (c) => (data += c));
    process.stdin.on('end', () => {
      try {
        resolve(JSON.parse(data || '{}'));
      } catch (e) {
        reject(e);
      }
    });
  });
}

async function main() {
  const input = await readStdin();
  const USDT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

  if (!input.privateKey || !input.toAddress || !input.amount) {
    throw new Error('privateKey, toAddress, amount gerekli');
  }

  const headers = input.apiKey ? { 'TRON-PRO-API-KEY': input.apiKey } : {};
  const tronWeb = new TronWeb({
    fullHost: 'https://api.trongrid.io',
    headers,
    privateKey: input.privateKey,
  });

  const amountSun = Math.round(Number(input.amount) * 1_000_000);
  if (amountSun < 1) throw new Error('Tutar çok küçük');

  const contract = await tronWeb.contract().at(USDT);
  const txId = await contract.transfer(input.toAddress, amountSun).send({
    feeLimit: 100_000_000,
    callValue: 0,
  });

  console.log(JSON.stringify({ ok: true, txId }));
}

main().catch((err) => {
  const msg = err && err.message ? err.message : String(err);
  console.log(JSON.stringify({ ok: false, error: msg }));
  process.exit(1);
});
