import { defineConfig } from 'hardhat/config';

export default defineConfig({
  solidity: {
    version: '0.8.30',
    settings: {
      optimizer: { enabled: true, runs: 200 },
      evmVersion: 'shanghai',
    },
  },
});
