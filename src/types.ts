/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

export interface StepInfo {
  id: string;
  label: string;
  description: string;
  status: 'pending' | 'active' | 'completed';
  iconName: string;
  color: string;
}

export interface ActivitySimulatorState {
  usdtVolume: number;
  verificationLevel: number; // 0 to 100
  contributions: number;
  fiziReputation: number;
  trustScore: number;
  category: string;
}

export interface NewsLetterSubscription {
  email: string;
  success: boolean;
  message: string;
}
