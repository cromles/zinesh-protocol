/** Oturum / API kullanıcı profili — Firebase'den bağımsız (landing bundle için). */
export interface UserProfile {
  uid: string;
  name: string;
  email: string;
  role: 'web3' | 'real' | 'dual';
  ticketNumber: string;
  trustScore: number;
  fiziBalance: number;
  usdtBalance?: number;
  escrowBalance?: number;
  walletAddress: string;
  sessionToken?: string;
  foundingMember?: boolean;
  foundingMemberNumber?: number;
  referralCode?: string;
  campaign?: unknown;
  emailVerified?: boolean;
  emailVerificationPending?: boolean;
  phoneVerified?: boolean;
  phoneMasked?: string;
  kycVerified?: boolean;
  contractVerificationReady?: boolean;
  signupRewardPending?: boolean;
  signupRewardAmount?: number;
  kycStatus?: 'none' | 'pending' | 'approved' | 'rejected';
  isFounder?: boolean;
  jobHistoryPublic?: boolean;
  googleLinked?: boolean;
  totpEnabled?: boolean;
  createdAt?: string;
}
