/**
 * İzole test için sahte ethers modülü.
 * Gerçek RPC'ye bağlanmaz, gerçek anahtar kullanmaz, zincire işlem göndermez.
 * Davranış MOCK_SCENARIO ortam değişkeni ile seçilir.
 */

export const MOCK_TX_HASH = '0x' + 'ab'.repeat(32);
export const MOCK_RECEIPT_HASH = MOCK_TX_HASH;

const scenario = () => process.env.MOCK_SCENARIO || 'ok';
const waitMs = () => Number(process.env.MOCK_WAIT_MS || 0);

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function makeReceipt(status) {
  return { hash: MOCK_RECEIPT_HASH, status, blockNumber: 42 };
}

function makeTx() {
  return {
    hash: MOCK_TX_HASH,
    async wait() {
      const mode = scenario();
      if (mode === 'hang') {
        // Zamanlayıcı olmadan event loop boşalır ve süreç kendiliğinden çıkardı.
        await sleep(600_000);
      }
      if (waitMs() > 0) {
        await sleep(waitMs());
      }
      if (mode === 'wait_throw') {
        throw new Error('mock: confirmation RPC hatası');
      }
      if (mode === 'revert') {
        return makeReceipt(0);
      }
      if (mode === 'receipt_null_status') {
        return { hash: MOCK_RECEIPT_HASH, status: null, blockNumber: 42 };
      }
      return makeReceipt(1);
    },
  };
}

class Contract {
  async decimals() {
    if (scenario() === 'decimals_fail') {
      throw new Error('mock: decimals okunamadı');
    }
    return 6;
  }

  async transfer() {
    const mode = scenario();
    if (mode === 'broadcast_fail') {
      throw new Error('mock: broadcast reddedildi');
    }
    if (mode === 'broadcast_uncertain') {
      // ethers, imzalanmış işlemin hash'ini hata nesnesinde taşıyabilir.
      throw Object.assign(new Error('mock: RPC yanıt vermedi'), {
        transaction: { hash: MOCK_TX_HASH },
      });
    }
    return makeTx();
  }
}

class JsonRpcProvider {}
class Wallet {}

export const ethers = {
  JsonRpcProvider,
  Wallet,
  Contract,
  parseUnits: (amount) => BigInt(Math.round(Number(amount) * 1e6)),
};
