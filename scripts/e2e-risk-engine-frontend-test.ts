/**
 * Risk Engine frontend integration tests (pure logic + contract parsing).
 * npx tsx scripts/e2e-risk-engine-frontend-test.ts
 */
import {
  RiskEngineHttpError,
  extractObservationRows,
  parseRiskEngineResponse,
  riskConfidenceLabel,
  riskConfidenceTone,
  riskEngineErrorKind,
  riskEngineErrorMessage,
} from '../src/lib/riskEngineLogic';

let passed = 0;
let failed = 0;

function assertTest(label: string, cond: boolean): void {
  if (cond) {
    passed += 1;
    console.log(`  PASS: ${label}`);
  } else {
    failed += 1;
    console.log(`  FAIL: ${label}`);
  }
}

const sampleObservation = {
  observation_id: 'OBS-NEG-001',
  title: 'Örnek gözlem',
  description: 'Test açıklaması',
  confidence: 'high',
  contexts_used: [{ context_id: 'CTX-NEG-001', title: 'Negatif bağlam' }],
  signals_used: [
    {
      signal_id: 'SIG-NEG-001',
      title: 'Sinyal',
      description: 'Sinyal açıklaması',
      event_sources: [{ event_type: 'terms_proposed', id: 'evt-1' }],
    },
  ],
  metrics_used: { counter_offer_count: 3 },
  explainability: {
    chain: 'risk_observation → context → signal → metric → event',
    observation_id: 'OBS-NEG-001',
  },
  evidence_chain: {
    chain: 'risk_observation → context → signal → metric → event',
  },
};

const samplePublicResponse = {
  endpoint_version: '1.0',
  risk_engine_version: '0.1',
  room_id: 'room-test-1',
  actor_id: 'user-1',
  metrics: { metrics: { negotiation: { counter_offer_count: 3 } } },
  signals: { signals: [{ signal_id: 'SIG-NEG-001' }] },
  contexts: { contexts: [{ context_id: 'CTX-NEG-001' }] },
  observations: {
    observations: [sampleObservation],
    risk_observation_runtime_version: '0.1',
  },
};

console.log('=== başarılı yükleme ===');
const parsed = parseRiskEngineResponse(samplePublicResponse, 'room-test-1');
assertTest('endpoint_version', parsed.endpoint_version === '1.0');
assertTest('risk_engine_version', parsed.risk_engine_version === '0.1');
assertTest('room_id', parsed.room_id === 'room-test-1');
assertTest(
  'runtime metrics package preserved',
  parsed.metrics.metrics != null && typeof parsed.metrics.metrics === 'object',
);
assertTest('runtime signals package preserved', Array.isArray(parsed.signals.signals));
assertTest('runtime contexts package preserved', Array.isArray(parsed.contexts.contexts));
assertTest('runtime observations package preserved', Array.isArray(parsed.observations.observations));

console.log('=== observation listesi ===');
assertTest('observation rows count', parsed.observationRows.length === 1);
assertTest('observation id', parsed.observationRows[0]?.observation_id === 'OBS-NEG-001');
assertTest('observation title', parsed.observationRows[0]?.title === 'Örnek gözlem');

console.log('=== explainability korunuyor ===');
assertTest(
  'explainability chain',
  parsed.observationRows[0]?.explainability?.chain ===
    'risk_observation → context → signal → metric → event',
);
assertTest(
  'evidence chain',
  parsed.observationRows[0]?.evidence_chain?.chain ===
    'risk_observation → context → signal → metric → event',
);

console.log('=== confidence gösterimi ===');
assertTest('confidence label high', riskConfidenceLabel('high') === 'Yüksek');
assertTest('confidence label medium', riskConfidenceLabel('medium') === 'Orta');
assertTest('confidence label low', riskConfidenceLabel('low') === 'Düşük');
assertTest('confidence tone high', riskConfidenceTone('high').badge.includes('emerald'));
assertTest('confidence tone medium', riskConfidenceTone('medium').badge.includes('amber'));

console.log('=== boş observation ===');
assertTest('empty observations package', extractObservationRows({ observations: [] }).length === 0);
assertTest('missing observations package', extractObservationRows(undefined).length === 0);
const emptyParsed = parseRiskEngineResponse(
  { ...samplePublicResponse, observations: { observations: [] } },
  'room-test-1',
);
assertTest('empty parsed rows', emptyParsed.observationRows.length === 0);

console.log('=== 401 / 403 / 404 / 500 ===');
assertTest('401 kind', riskEngineErrorKind(new RiskEngineHttpError('', 401)) === 'unauthorized');
assertTest('403 kind', riskEngineErrorKind(new RiskEngineHttpError('', 403)) === 'forbidden');
assertTest('404 kind', riskEngineErrorKind(new RiskEngineHttpError('', 404)) === 'not_found');
assertTest('500 kind', riskEngineErrorKind(new RiskEngineHttpError('', 500)) === 'server');
assertTest('401 message', riskEngineErrorMessage('unauthorized').includes('giriş'));
assertTest('403 message', riskEngineErrorMessage('forbidden').includes('yetkiniz'));
assertTest('404 message', riskEngineErrorMessage('not_found').includes('bulunamadı'));

console.log('=== runtime değişmezliği ===');
const before = structuredClone(samplePublicResponse);
parseRiskEngineResponse(samplePublicResponse, 'room-test-1');
assertTest('input response not mutated', JSON.stringify(before) === JSON.stringify(samplePublicResponse));
assertTest(
  'metrics package unchanged in output',
  JSON.stringify(parsed.metrics) === JSON.stringify(samplePublicResponse.metrics),
);
assertTest(
  'observations package unchanged in output',
  JSON.stringify(parsed.observations) === JSON.stringify(samplePublicResponse.observations),
);

console.log('=== retry error kind network ===');
assertTest('network kind', riskEngineErrorKind(new TypeError('fetch failed')) === 'network');

console.log('\n=== SUMMARY ===');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
