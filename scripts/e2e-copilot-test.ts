/**
 * Intelligence Copilot frontend contract tests.
 * npx tsx scripts/e2e-copilot-test.ts
 */
import {
  COPILOT_EXPLAINABILITY_CHAIN,
  COPILOT_HUMAN_DISCLAIMER,
  copilotAssistantMessage,
  copilotExplanationIsAdvisoryOnly,
  copilotIntentLabel,
  copilotPreservesRiskEngineContract,
  copilotResponseFingerprint,
  copilotUserMessage,
  parseCopilotResponse,
} from '../src/lib/copilotLogic';

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

const sampleEnginePublic = {
  endpoint_version: '1.0',
  risk_engine_version: '0.1',
  room_id: 'room-copilot-1',
  actor_id: 'user-1',
  metrics: { metrics: { negotiation: { counter_offer_count: 2 } } },
  signals: { signals: [] },
  contexts: { contexts: [] },
  observations: {
    observations: [
      {
        observation_id: 'OBS-NEG-001',
        title: 'Test gözlem',
        description: 'Açıklama',
        confidence: 'medium',
        contexts_used: [{ context_id: 'CTX-SW', title: 'Scope' }],
        signals_used: [
          {
            signal_id: 'SIG-NEG-002',
            event_sources: [{ event_type: 'terms_proposed', created_at: '2026-01-01T10:00:00+03:00' }],
          },
        ],
        metrics_used: { counter_offer_count: 2 },
        explainability: { chain: COPILOT_EXPLAINABILITY_CHAIN },
      },
    ],
  },
};

const sampleCopilotResponse = {
  copilot_version: '1.0',
  endpoint_version: '1.0',
  room_id: 'room-copilot-1',
  actor_id: 'user-1',
  intent: 'why_observation',
  observation_id: 'OBS-NEG-001',
  explainability_chain: COPILOT_EXPLAINABILITY_CHAIN,
  explanation: 'Risk Observation OBS-NEG-001 şu kanıt zincirine dayanır.',
  sources: { observation: { observation_id: 'OBS-NEG-001' } },
  human_disclaimer: COPILOT_HUMAN_DISCLAIMER,
  risk_engine: sampleEnginePublic,
};

console.log('=== Copilot response ===');
const parsed = parseCopilotResponse(sampleCopilotResponse, 'room-copilot-1');
assertTest('copilot_version', parsed.copilot_version === '1.0');
assertTest('explanation', parsed.explanation.includes('OBS-NEG-001'));
assertTest('intent label', copilotIntentLabel('why_observation').includes('neden'));

console.log('=== Explainability korunumu ===');
assertTest('chain preserved', parsed.explainability_chain === COPILOT_EXPLAINABILITY_CHAIN);
assertTest('assistant message chain', copilotAssistantMessage(parsed).explainabilityChain === COPILOT_EXPLAINABILITY_CHAIN);

console.log('=== Public Contract ===');
assertTest('contract preserved', copilotPreservesRiskEngineContract(sampleCopilotResponse, parsed));

console.log('=== Human in Control ===');
assertTest('advisory only', copilotExplanationIsAdvisoryOnly(parsed.explanation));
assertTest('disclaimer present', parsed.human_disclaimer.includes('karar'));

console.log('=== Read Only (client) ===');
assertTest('user message role', copilotUserMessage('overview', null).role === 'user');

console.log('=== Determinism ===');
const fp1 = copilotResponseFingerprint(parsed);
const fp2 = copilotResponseFingerprint(parseCopilotResponse(sampleCopilotResponse, 'room-copilot-1'));
assertTest('same fingerprint', fp1 === fp2);

console.log('=== Replayability ===');
const replay = parseCopilotResponse(structuredClone(sampleCopilotResponse), 'room-copilot-1');
assertTest('replay fingerprint', copilotResponseFingerprint(replay) === fp1);

console.log('=== Runtime immutability ===');
const before = structuredClone(sampleCopilotResponse);
parseCopilotResponse(sampleCopilotResponse, 'room-copilot-1');
assertTest('input not mutated', JSON.stringify(before) === JSON.stringify(sampleCopilotResponse));

console.log('\n=== SUMMARY ===');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
