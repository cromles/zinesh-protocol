#!/usr/bin/env node
/**
 * EVM USDT çekimi (Arbitrum / Ethereum).
 * stdin: { privateKey, toAddress, amount, network, rpcUrl, usdtContract }
 *
 * stdout protokolü ZINESH_SEND_PROTOCOL ile seçilir:
 *
 *   legacy (varsayılan) — tek satır: {ok,txHash} | {ok:false,error}
 *     PHP zinesh_run_node_script() tüm stdout'u tek JSON olarak decode ettiği için
 *     bu mod bayt düzeyinde mevcut davranışı korur.
 *
 *   v2 — satır başına bir JSON event (NDJSON):
 *     {"stage":"broadcast","txHash":...}        broadcast sonrası, wait() ÖNCESİ
 *     {"ok":true,"stage":"confirmed",...}       onay sonrası
 *     {"ok":false,"stage":"confirm_failed",...} broadcast oldu, onay olmadı
 *     {"ok":false,"stage":"broadcast_uncertain",...} imzalandı, yayın sonucu bilinmiyor
 *     {"ok":false,"stage":"broadcast_failed",...}    zincire hiçbir şey gitmedi
 *
 *     v2'de txHash onay beklenmeden yayımlanır; broadcast ile hash'in okunabilir
 *     olması arasındaki pencere duplicate transfer riskinin kaynağıdır.
 */
import { ethers } from 'ethers';

const ERC20_ABI = [
  'function transfer(address to, uint256 amount) returns (bool)',
  'function decimals() view returns (uint8)',
];

const PROTOCOL_V2 = process.env.ZINESH_SEND_PROTOCOL === 'v2';

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

/**
 * Tek satırlık JSON event yazar ve yazma işletim sistemine teslim edilene kadar bekler.
 * Onay beklemeye geçmeden önce hash'in pipe'a ulaşmış olmasını garanti eder.
 */
function emit(event) {
  return new Promise((resolve) => {
    process.stdout.write(JSON.stringify(event) + '\n', () => resolve());
  });
}

function errorMessage(err) {
  return err && err.message ? err.message : String(err);
}

/** ethers hata nesnesinde imzalanmış işlemin hash'i taşınıyorsa çıkarır. */
function hashFromError(err) {
  const candidates = [err?.transaction?.hash, err?.transactionHash, err?.receipt?.hash, err?.hash];
  for (const value of candidates) {
    if (typeof value === 'string' && /^0x[a-fA-F0-9]{64}$/.test(value)) {
      return value;
    }
  }
  return null;
}

async function main() {
  const input = await readStdin();

  if (!input.privateKey || !input.toAddress || !input.amount || !input.rpcUrl || !input.usdtContract) {
    throw new Error('Eksik parametre');
  }

  const provider = new ethers.JsonRpcProvider(input.rpcUrl);
  const wallet = new ethers.Wallet(input.privateKey, provider);
  const usdt = new ethers.Contract(input.usdtContract, ERC20_ABI, wallet);
  const decimals = await usdt.decimals();
  const value = ethers.parseUnits(String(input.amount), decimals);

  // eth_sendRawTransaction burada gerçekleşir; bu noktadan sonra para zincirde
  // hareket etmiş olabilir.
  let tx;
  try {
    tx = await usdt.transfer(input.toAddress, value);
  } catch (err) {
    const strandedHash = hashFromError(err);
    if (strandedHash) {
      // İşlem imzalanmış ve yayınlanmış olabilir; hash'i bildirmeden ölmek
      // duplicate gönderim riskinin ta kendisidir.
      throw Object.assign(new Error(errorMessage(err)), {
        zineshStage: 'broadcast_uncertain',
        zineshTxHash: strandedHash,
      });
    }
    throw err;
  }

  const txHash = tx?.hash ?? null;

  if (PROTOCOL_V2) {
    await emit({ stage: 'broadcast', txHash });
  }

  let receipt = null;
  let waitError = null;
  try {
    receipt = await tx.wait(1);
  } catch (err) {
    waitError = err;
  }

  // ethers'in revert durumunda throw ettiğine güvenilmez; receipt.status açıkça okunur.
  const receiptStatus =
    receipt && typeof receipt.status === 'number' ? receipt.status : null;
  const confirmedHash = receipt?.hash ?? txHash;

  if (waitError || receiptStatus === 0) {
    const message = waitError
      ? errorMessage(waitError)
      : 'İşlem zincirde başarısız oldu (receipt status 0)';
    if (PROTOCOL_V2) {
      await emit({
        ok: false,
        stage: 'confirm_failed',
        txHash: confirmedHash,
        receiptStatus,
        error: message,
      });
      process.exitCode = 1;
      return;
    }
    throw new Error(message);
  }

  if (PROTOCOL_V2) {
    await emit({
      ok: true,
      stage: 'confirmed',
      txHash: confirmedHash,
      receiptStatus,
      blockNumber: receipt?.blockNumber ?? null,
    });
    return;
  }

  await emit({ ok: true, txHash: confirmedHash });
}

main().catch(async (err) => {
  const msg = errorMessage(err);
  if (PROTOCOL_V2) {
    const stage = err?.zineshStage ?? 'broadcast_failed';
    const event = { ok: false, stage, error: msg };
    if (err?.zineshTxHash) {
      event.txHash = err.zineshTxHash;
    }
    await emit(event);
  } else {
    await emit({ ok: false, error: msg });
  }
  process.exit(1);
});
