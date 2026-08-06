#!/usr/bin/env node
/**
 * Kasa cüzdanlarının on-chain USDT + gas bakiyelerini döner.
 * stdin: { tronAddress, evmAddress, trongridApiKey? }
 */
import { ethers } from 'ethers';
import { TronWeb } from 'tronweb';

function readStdin() {
  return new Promise((resolve) => {
    let d = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (c) => (d += c));
    process.stdin.on('end', () => resolve(d || '{}'));
  });
}

async function main() {
  const input = JSON.parse(await readStdin());

  const USDT_TRON = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
  const USDT_ARB = '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9';
  const USDT_ETH = '0xdAC17F958D2ee523a2206206994597C13D831ec7';
  const ERC20_ABI = ['function balanceOf(address) view returns (uint256)'];

  const out = { ok: true, tron: {}, evm: {}, warnings: [] };
  let sections = 0;

  if (input.tronAddress) {
    try {
      const headers = input.trongridApiKey ? { 'TRON-PRO-API-KEY': input.trongridApiKey } : {};
      const tw = new TronWeb({ fullHost: 'https://api.trongrid.io', headers });
      tw.setAddress(input.tronAddress);
      const usdt = await tw.contract().at(USDT_TRON);
      const sun = await usdt.balanceOf(input.tronAddress).call({ from: input.tronAddress });
      const trxSun = await tw.trx.getBalance(input.tronAddress);
      out.tron = {
        address: input.tronAddress,
        usdt: Number(sun) / 1e6,
        trx: Number(trxSun) / 1e6,
      };
      sections += 1;
    } catch (err) {
      out.warnings.push('tron: ' + (err && err.message ? err.message : String(err)));
    }
  }

  if (input.evmAddress) {
    const addr = input.evmAddress;
    out.evm = {
      address: addr,
      arbitrum: { usdt: 0, eth: 0 },
      ethereum: { usdt: 0, eth: 0 },
    };

    try {
      const arb = new ethers.JsonRpcProvider('https://arb1.arbitrum.io/rpc', 42161, { staticNetwork: true });
      const arbUsdt = new ethers.Contract(USDT_ARB, ERC20_ABI, arb);
      const [arbBal, arbGas] = await Promise.all([arbUsdt.balanceOf(addr), arb.provider.getBalance(addr)]);
      out.evm.arbitrum = {
        usdt: Number(ethers.formatUnits(arbBal, 6)),
        eth: Number(ethers.formatEther(arbGas)),
      };
      sections += 1;
    } catch (err) {
      out.warnings.push('arbitrum: ' + (err && err.message ? err.message : String(err)));
    }

    try {
      const eth = new ethers.JsonRpcProvider('https://ethereum.publicnode.com', 1, { staticNetwork: true });
      const ethUsdt = new ethers.Contract(USDT_ETH, ERC20_ABI, eth);
      const [ethBal, ethGas] = await Promise.all([ethUsdt.balanceOf(addr), eth.provider.getBalance(addr)]);
      out.evm.ethereum = {
        usdt: Number(ethers.formatUnits(ethBal, 6)),
        eth: Number(ethers.formatEther(ethGas)),
      };
      sections += 1;
    } catch (err) {
      out.warnings.push('ethereum: ' + (err && err.message ? err.message : String(err)));
    }
  }

  if (sections === 0) {
    out.ok = false;
    out.error = out.warnings.join(' | ') || 'Zincir bakiyesi okunamadı';
  }

  console.log(JSON.stringify(out));
}

main().catch((err) => {
  const msg = err && err.message ? err.message : String(err);
  console.log(JSON.stringify({ ok: false, error: msg }));
  process.exit(1);
});
