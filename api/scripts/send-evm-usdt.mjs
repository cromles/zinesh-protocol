#!/usr/bin/env node
/**
 * EVM USDT çekimi (Arbitrum / Ethereum).
 * stdin: { privateKey, toAddress, amount, network, rpcUrl, usdtContract }
 */
import { ethers } from 'ethers';

const ERC20_ABI = [
  'function transfer(address to, uint256 amount) returns (bool)',
  'function decimals() view returns (uint8)',
];

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

  if (!input.privateKey || !input.toAddress || !input.amount || !input.rpcUrl || !input.usdtContract) {
    throw new Error('Eksik parametre');
  }

  const provider = new ethers.JsonRpcProvider(input.rpcUrl);
  const wallet = new ethers.Wallet(input.privateKey, provider);
  const usdt = new ethers.Contract(input.usdtContract, ERC20_ABI, wallet);
  const decimals = await usdt.decimals();
  const value = ethers.parseUnits(String(input.amount), decimals);
  const tx = await usdt.transfer(input.toAddress, value);
  const receipt = await tx.wait(1);

  console.log(JSON.stringify({ ok: true, txHash: receipt.hash }));
}

main().catch((err) => {
  const msg = err && err.message ? err.message : String(err);
  console.log(JSON.stringify({ ok: false, error: msg }));
  process.exit(1);
});
