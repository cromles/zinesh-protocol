/**
 * Frontend mirror of api/protocol_constants.php job_commission_rate.
 * Single source for landing fee calculator display — update when backend changes.
 */
export const ZINESH_JOB_COMMISSION_RATE = 0.05;

export function formatCommissionPercent(rate = ZINESH_JOB_COMMISSION_RATE): string {
  return `${(rate * 100).toFixed(1).replace('.0', '')}%`;
}

export function calculateEscrowFee(amountTry: number, rate = ZINESH_JOB_COMMISSION_RATE): number {
  return Math.round(amountTry * rate);
}
