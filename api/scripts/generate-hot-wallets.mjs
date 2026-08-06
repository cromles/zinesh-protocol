#!/usr/bin/env node
import { ethers } from 'ethers';
import { TronWeb } from 'tronweb';
import crypto from 'crypto';

const evm = ethers.Wallet.createRandom();
const tronPrivateKey = crypto.randomBytes(32).toString('hex');
const tronWeb = new TronWeb({
  fullHost: 'https://api.trongrid.io',
  privateKey: tronPrivateKey,
});
const tronAddress = tronWeb.defaultAddress.base58;

const out = {
  ok: true,
  tron: {
    address: tronAddress,
    privateKey: tronPrivateKey,
  },
  evm: {
    address: evm.address,
    privateKey: evm.privateKey,
  },
};

process.stdout.write(JSON.stringify(out));
