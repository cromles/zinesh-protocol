import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFile } from 'node:fs/promises';

import solc from 'solc';
import { BYTECODE } from '../web3-test/v32-bytecode.js';

test('V3.2 deploy bytecode matches the reviewed Solidity source', async () => {
  const source = await readFile(new URL('../contracts/ZineshEscrowV3_2.sol', import.meta.url), 'utf8');
  const input = {
    language: 'Solidity',
    sources: { 'ZineshEscrowV3_2.sol': { content: source } },
    settings: {
      optimizer: { enabled: true, runs: 200 },
      outputSelection: { '*': { '*': ['evm.bytecode.object', 'evm.deployedBytecode.object'] } },
    },
  };
  const output = JSON.parse(solc.compile(JSON.stringify(input)));
  const errors = (output.errors ?? []).filter(({ severity }) => severity === 'error');
  assert.deepEqual(errors, [], errors.map(({ formattedMessage }) => formattedMessage).join('\n'));
  const artifact = output.contracts['ZineshEscrowV3_2.sol'].ZineshEscrowV3_2;
  assert.ok(artifact.evm.deployedBytecode.object.length / 2 <= 24_576, 'runtime bytecode exceeds EIP-170');
  assert.equal(BYTECODE, `0x${artifact.evm.bytecode.object}`);
});
