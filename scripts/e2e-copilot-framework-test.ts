/**
 * Copilot Framework frontend contract tests.
 * npx tsx scripts/e2e-copilot-framework-test.ts
 */
import {
  COPILOT_EXPLAINABILITY_CHAIN,
  COPILOT_HUMAN_DISCLAIMER,
  copilotAssistantMessage,
  copilotExplanationIsAdvisoryOnly,
  copilotFrameworkFieldsPresent,
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
  room_id: 'room-cpfw-1',
  actor_id: 'user-1',
  metrics: { metrics: {} },
  signals: { signals: [] },
  contexts: { contexts: [] },
  observations: { observations: [] },
};

const sampleResponse = {
  copilot_version: '1.0',
  framework_version: '1.0',
  endpoint_version: '1.0',
  provider: 'mock',
  validation_status: 'pass',
  validation_reasons: [],
  room_id: 'room-cpfw-1',
  actor_id: 'user-1',
  intent: 'overview',
  observation_id: null,
  explainability_chain: COPILOT_EXPLAINABILITY_CHAIN,
  explanation: 'Bu odada Risk Engine tarafından üretilmiş gözlemler:',
  sources: { observations: [] },
  copilot_context: {
    framework_version: '1.0',
    intent: 'overview',
    explainability_chain: COPILOT_EXPLAINABILITY_CHAIN,
  },
  human_disclaimer: COPILOT_HUMAN_DISCLAIMER,
  risk_engine: sampleEnginePublic,
};

console.log('=== Context / Framework fields ===');
const parsed = parseCopilotResponse(sampleResponse, 'room-cpfw-1');
assertTest('framework_version', parsed.framework_version === '1.0');
assertTest('provider mock', parsed.provider === 'mock');
assertTest('validation pass', parsed.validation_status === 'pass');
assertTest('framework fields present', copilotFrameworkFieldsPresent(parsed));

console.log('=== Public Contract ===');
assertTest('contract preserved', copilotPreservesRiskEngineContract(sampleResponse, parsed));

console.log('=== Explainability ===');
assertTest('chain preserved', parsed.explainability_chain === COPILOT_EXPLAINABILITY_CHAIN);
assertTest('assistant chain', copilotAssistantMessage(parsed).explainabilityChain === COPILOT_EXPLAINABILITY_CHAIN);

console.log('=== Human in Control ===');
assertTest('advisory only', copilotExplanationIsAdvisoryOnly(parsed.explanation));
assertTest('disclaimer', parsed.human_disclaimer.includes('karar'));

console.log('=== Read Only (client) ===');
assertTest('user message', copilotUserMessage('overview', null).role === 'user');

console.log('=== Determinism ===');
const fp1 = copilotResponseFingerprint(parsed);
const fp2 = copilotResponseFingerprint(parseCopilotResponse(sampleResponse, 'room-cpfw-1'));
assertTest('same fingerprint', fp1 === fp2);

console.log('=== Replayability ===');
const replay = parseCopilotResponse(structuredClone(sampleResponse), 'room-cpfw-1');
assertTest('replay fingerprint', copilotResponseFingerprint(replay) === fp1);

console.log('=== Contract immutability ===');
const before = structuredClone(sampleResponse);
parseCopilotResponse(sampleResponse, 'room-cpfw-1');
assertTest('input not mutated', JSON.stringify(before) === JSON.stringify(sampleResponse));

console.log('\n=== SUMMARY ===');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
