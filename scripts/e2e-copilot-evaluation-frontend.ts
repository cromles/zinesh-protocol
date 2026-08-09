/**
 * Copilot Evaluation frontend mirror (report contract + regression checks).
 * npx tsx scripts/e2e-copilot-evaluation-frontend.ts
 */

const EVAL_VERSION = '1.0';
const EXPLAINABILITY_CHAIN = 'risk_observation → context → signal → metric → event';

const COVERAGE_INTENTS = [
  'overview',
  'why_observation',
  'evidence',
  'context_chain',
  'signal_chain',
  'event_chain',
];

const HUMAN_CONTROL_PHRASES = [
  'dolandırıcı',
  'garanti',
  'kesin',
  'cezalandır',
  'hesabı kapat',
  'ödemeyi durdur',
  'riskli kullanıcı',
  'iptal et',
];

const VALIDATOR_REGRESSION_PHRASES = [
  'risk score',
  'prediction',
  'fraud',
  'settlement öner',
  'yeni signal',
  'yeni context',
  'yeni observation',
  'yeni event',
];

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

function humanControlCheck(text: string): boolean {
  const lower = text.toLowerCase();
  return !HUMAN_CONTROL_PHRASES.some((p) => lower.includes(p));
}

function validatorRegressionCheck(text: string): boolean {
  const lower = text.toLowerCase();
  return !VALIDATOR_REGRESSION_PHRASES.some((p) => lower.includes(p));
}

function explainabilityCheck(text: string, chain: string): boolean {
  const lower = text.toLowerCase();
  const links = ['risk observation', 'risk_observation', 'context', 'signal', 'metric', 'event'];
  const hasRisk = lower.includes('risk observation') || lower.includes('risk_observation');
  return text.includes(chain) && hasRisk && ['context', 'signal', 'metric', 'event'].every((l) => lower.includes(l));
}

function reportFingerprint(report: Record<string, unknown>): string {
  const scenarios = Array.isArray(report.scenarios) ? report.scenarios : [];
  const payload = {
    evaluation_version: report.evaluation_version,
    prompt_version: report.prompt_version,
    provider: report.provider,
    provider_model: report.provider_model,
    dataset_fingerprint: report.dataset_fingerprint,
    total: report.total,
    pass: report.pass,
    fail: report.fail,
    overall: report.overall,
    coverage: report.coverage,
    scenario_statuses: scenarios.map(
      (row) => `${(row as Record<string, unknown>).scenario_id}:${(row as Record<string, unknown>).status}`,
    ),
  };
  return JSON.stringify(payload);
}

const sampleReport = {
  evaluation_version: EVAL_VERSION,
  prompt_version: 'a'.repeat(64),
  provider: 'mock',
  provider_model: null,
  dataset_version: '1.0',
  dataset_fingerprint: 'b'.repeat(64),
  report_fingerprint: 'c'.repeat(64),
  execution_ms: 281,
  total: 7,
  pass: 7,
  fail: 0,
  statistics: {
    overview: 1,
    why_observation: 1,
    evidence: 1,
    context_chain: 1,
    signal_chain: 1,
    event_chain: 1,
    validator_fixture: 1,
  },
  scenario_metrics: [
    { scenario_id: '001', duration_ms: 17, status: 'PASS' },
    { scenario_id: '002', duration_ms: 18, status: 'PASS' },
  ],
  prompt_regression: 'PASS',
  validator_regression: 'PASS',
  explainability_regression: 'PASS',
  human_control_regression: 'PASS',
  overall: 'PASS',
  coverage: Object.fromEntries(
    COVERAGE_INTENTS.map((intent) => [intent, { total: 1, pass: 1, fail: 0, status: 'PASS' }]),
  ),
  scenarios: ['001', '002', '003', '004', '005', '006', '007'].map((id) => ({
    scenario_id: id,
    status: 'PASS',
  })),
};

const goodExplanation =
  'Risk Observation OBS-1 şu kanıt zincirine dayanır:\nrisk_observation → context → signal → metric → event\nContext katmanı:\n- CTX-1\nSignal katmanı:\n- SIG-1';

console.log('=== Human Control ===');
assertTest('detects dolandırıcı', !humanControlCheck('kullanıcı dolandırıcı'));
assertTest('passes good text', humanControlCheck(goodExplanation));

console.log('=== Validator Regression ===');
assertTest('detects risk score', !validatorRegressionCheck('risk score yüksek'));
assertTest('passes good text', validatorRegressionCheck(goodExplanation));

console.log('=== Explainability ===');
assertTest('chain present', explainabilityCheck(goodExplanation, EXPLAINABILITY_CHAIN));

console.log('=== Report Contract ===');
assertTest('evaluation version', sampleReport.evaluation_version === EVAL_VERSION);
assertTest('prompt version', sampleReport.prompt_version.length === 64);
assertTest('provider mock', sampleReport.provider === 'mock');
assertTest('dataset version', sampleReport.dataset_version === '1.0');
assertTest('dataset fingerprint', sampleReport.dataset_fingerprint.length === 64);
assertTest('execution ms', sampleReport.execution_ms > 0);
assertTest('statistics', sampleReport.statistics.validator_fixture === 1);
assertTest('scenario metrics', sampleReport.scenario_metrics.length >= 2);
assertTest('report fingerprint', sampleReport.report_fingerprint.length === 64);
assertTest('overall pass', sampleReport.overall === 'PASS');
assertTest('coverage all intents', COVERAGE_INTENTS.every((i) => sampleReport.coverage[i]?.status === 'PASS'));

console.log('=== Determinism ===');
const fp1 = reportFingerprint(sampleReport);
const fp2 = reportFingerprint(structuredClone(sampleReport));
assertTest('same fingerprint', fp1 === fp2);

console.log('\n=== SUMMARY ===');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
