/**
 * Trust Timeline frontend tests (pure logic).
 * npx tsx scripts/e2e-trust-timeline-frontend-test.ts
 */
import type { RiskObservation } from '../src/lib/riskEngineLogic';
import {
  RiskEngineHttpError,
  parseRiskEngineResponse,
  riskEngineErrorKind,
  riskEngineErrorMessage,
} from '../src/lib/riskEngineLogic';
import {
  TRUST_TIMELINE_LAYER_ORDER,
  buildTrustTimelineJourney,
  buildTrustTimelineJourneys,
  filterTrustTimelineJourneys,
  sortTrustTimelineJourneys,
  trustTimelineEventLabel,
  trustTimelineExplainabilityKinds,
  trustTimelineIsEmpty,
  trustTimelineJourneyFingerprint,
  validateTrustTimelineLayerOrder,
} from '../src/lib/trustTimelineLogic';

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

const sampleObservation: RiskObservation = {
  observation_id: 'OBS-NEG-001',
  title: 'Müzakere gerginliği',
  description: 'Çoklu karşı teklif gözlemi',
  confidence: 'medium',
  contexts_used: [{ context_id: 'CTX-SW', title: 'Scope widening' }],
  signals_used: [
    {
      signal_id: 'SIG-NEG-002',
      title: 'Karşı teklif sinyali',
      event_sources: [
        { event_type: 'terms_proposed', created_at: '2026-01-01T10:00:00+03:00' },
        { event_type: 'changes_requested', created_at: '2026-01-05T10:00:00+03:00' },
        { event_type: 'counter_offer_created', created_at: '2026-01-10T10:00:00+03:00' },
      ],
    },
  ],
  metrics_used: { counter_offer_count: 3 },
  explainability: {
    chain: 'risk_observation → context → signal → metric → event',
    observation_id: 'OBS-NEG-001',
  },
};

const laterObservation: RiskObservation = {
  observation_id: 'OBS-HIS-002',
  title: 'İlk işlem',
  description: 'Geçmiş gözlemi',
  confidence: 'low',
  signals_used: [
    {
      signal_id: 'SIG-HIS-001',
      event_sources: [
        { event_type: 'escrow_locked', created_at: '2026-02-01T10:00:00+03:00' },
      ],
    },
  ],
  metrics_used: { actor_transactions: 1 },
};

console.log('=== Timeline oluşuyor ===');
const journey = buildTrustTimelineJourney(sampleObservation);
assertTest('journey has nodes', journey.nodes.length > 0);
assertTest('journey observation id', journey.observationId === 'OBS-NEG-001');

console.log('=== Event sırası doğru ===');
const eventNodes = journey.nodes.filter((node) => node.kind === 'event');
assertTest('three events', eventNodes.length === 3);
assertTest(
  'events chronological',
  eventNodes[0].label === trustTimelineEventLabel('terms_proposed') &&
    eventNodes[1].label === trustTimelineEventLabel('changes_requested') &&
    eventNodes[2].label === trustTimelineEventLabel('counter_offer_created'),
);

console.log('=== Explainability zinciri korunuyor ===');
const kinds = trustTimelineExplainabilityKinds(journey.nodes);
assertTest(
  'explainability chain kinds',
  JSON.stringify(kinds) ===
    JSON.stringify(['event', 'event', 'event', 'metric', 'signal', 'context', 'observation']),
);
assertTest('ends with observation', journey.nodes[journey.nodes.length - 1].kind === 'observation');
assertTest('runtime whyText on signal', journey.nodes.some((node) => node.kind === 'signal' && node.whyText.includes('SIG-NEG-002')));

console.log('=== Journey sırası değişmiyor ===');
assertTest('layer order valid', validateTrustTimelineLayerOrder(journey.nodes));
assertTest(
  'charter layer order',
  JSON.stringify(TRUST_TIMELINE_LAYER_ORDER) ===
    JSON.stringify(['event', 'metric', 'signal', 'context', 'observation']),
);

console.log('=== Timeline sıralama eskiden yeniye ===');
const journeys = sortTrustTimelineJourneys(
  buildTrustTimelineJourneys([laterObservation, sampleObservation]),
);
assertTest('older journey first', journeys[0].observationId === 'OBS-NEG-001');

console.log('=== Observation filtreleme ===');
const filtered = filterTrustTimelineJourneys(journeys, 'OBS-HIS');
assertTest('filter matches one', filtered.length === 1);

console.log('=== Collapse / Expand (logic) ===');
assertTest('journey nodes present when built', journey.nodes.length === 7);

console.log('=== Empty timeline ===');
assertTest('empty observations', trustTimelineIsEmpty(buildTrustTimelineJourneys([])));

console.log('=== Runtime immutability ===');
const observationClone = structuredClone(sampleObservation);
buildTrustTimelineJourney(sampleObservation);
assertTest('observation not mutated', JSON.stringify(observationClone) === JSON.stringify(sampleObservation));

console.log('=== Public Response Contract korunuyor ===');
const publicResponse = {
  endpoint_version: '1.0',
  risk_engine_version: '0.1',
  room_id: 'room-1',
  actor_id: 'user-1',
  metrics: { metrics: { negotiation: { counter_offer_count: 3 } } },
  signals: { signals: [] },
  contexts: { contexts: [] },
  observations: { observations: [sampleObservation] },
};
const before = structuredClone(publicResponse);
const parsed = parseRiskEngineResponse(publicResponse, 'room-1');
buildTrustTimelineJourneys(parsed.observationRows);
assertTest('public response not mutated', JSON.stringify(before) === JSON.stringify(publicResponse));

console.log('=== Network error ===');
assertTest('network kind', riskEngineErrorKind(new TypeError('fetch failed')) === 'network');
assertTest('network message', riskEngineErrorMessage('network').includes('Bağlantı'));

console.log('=== Retry error mapping ===');
assertTest('401 kind', riskEngineErrorKind(new RiskEngineHttpError('', 401)) === 'unauthorized');
assertTest('500 kind', riskEngineErrorKind(new RiskEngineHttpError('', 500)) === 'server');

console.log('=== Determinism ===');
const fp1 = trustTimelineJourneyFingerprint(buildTrustTimelineJourney(sampleObservation));
const fp2 = trustTimelineJourneyFingerprint(buildTrustTimelineJourney(sampleObservation));
assertTest('same fingerprint', fp1 === fp2);

console.log('=== Replayability ===');
const replay1 = buildTrustTimelineJourney(structuredClone(sampleObservation));
const replay2 = buildTrustTimelineJourney(structuredClone(sampleObservation));
assertTest(
  'replay same fingerprint',
  trustTimelineJourneyFingerprint(replay1) === trustTimelineJourneyFingerprint(replay2),
);

console.log('\n=== SUMMARY ===');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
