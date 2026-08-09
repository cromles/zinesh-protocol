import { formatMoney } from './currencyFormat';
import { displayMemberTicket } from './memberTicket';
import { ESCROW_PARTY } from './plainLanguage';
import {
  escrowRoomNextAction,
  escrowRoomStatusLabel,
  type EscrowRoom,
  type EscrowRoomRole,
  type EscrowRoomStatus,
} from './escrowRoomApi';

export type EscrowDealStageId =
  | 'connect'
  | 'terms'
  | 'locked'
  | 'complete'
  | 'closed';

export type EscrowDealStage = {
  id: EscrowDealStageId;
  label: string;
  description: string;
};

export const ESCROW_DEAL_STAGES: EscrowDealStage[] = [
  { id: 'connect', label: 'Bağlantı', description: 'Taraflar eşleşti' },
  { id: 'terms', label: 'Şartlar', description: 'Teklif ve onay' },
  { id: 'locked', label: 'Emanet', description: 'Tutar kilitli' },
  { id: 'complete', label: 'Teslim / onay', description: 'İş ve onay' },
  { id: 'closed', label: 'Kapanış', description: 'Tamamlandı veya sonuç' },
];

export function escrowDealActiveStage(status: EscrowRoomStatus): EscrowDealStageId {
  switch (status) {
    case 'negotiating':
      return 'connect';
    case 'terms_pending':
    case 'locking':
      return 'terms';
    case 'locked':
      return 'locked';
    case 'completion_pending':
    case 'settling':
      return 'complete';
    case 'completed':
    case 'cancelled':
    case 'disputed':
    case 'resolved':
      return 'closed';
    default:
      return 'connect';
  }
}

export function escrowDealStageIndex(stageId: EscrowDealStageId): number {
  return ESCROW_DEAL_STAGES.findIndex((s) => s.id === stageId);
}

export type EscrowSevenQuestion = {
  id: string;
  question: string;
  answer: string;
  emphasis?: boolean;
};

export function buildEscrowSevenQuestions(
  room: EscrowRoom,
  myName: string,
  availableBalance: number,
): EscrowSevenQuestion[] {
  const role = room.myRole;
  const isEmployer = role === 'employer';
  const peerName = isEmployer ? room.workerName : room.employerName;
  const peerTicket = displayMemberTicket(isEmployer ? room.workerTicket : room.employerTicket);
  const myRoleLabel = isEmployer ? ESCROW_PARTY.employer : ESCROW_PARTY.worker;
  const next = escrowRoomNextAction(room);

  const moneyAnswer = (() => {
    if (room.status === 'negotiating' || room.status === 'terms_pending') {
      if (room.agreedAmountTry > 0 && room.status === 'terms_pending') {
        return `Henüz kilitlenmedi. Teklif tutarı: ${formatMoney(room.agreedAmountTry)}. Onay sonrası işveren bakiyesinden kilitlenir.`;
      }
      return 'Para henüz emanet sürecine alınmadı.';
    }
    if (room.status === 'locking') {
      return 'Tutar kilitleniyor — işlem tamamlanana kadar bekleyin.';
    }
    if (room.status === 'locked' || room.status === 'completion_pending') {
      const locked = room.employerLockedTry > 0 ? room.employerLockedTry : room.agreedAmountTry;
      return `Emanet sürecinde kilitli: ${formatMoney(locked)}. Onay öncesi serbest bırakılmaz.`;
    }
    if (room.status === 'settling') {
      return 'Ödeme işleniyor — backend onayı bekleniyor.';
    }
    if (room.status === 'completed') {
      return 'İş tamamlandı; ödeme backend kayıtlarına göre sonuçlandı.';
    }
    if (room.status === 'disputed') {
      const locked = room.employerLockedTry > 0 ? room.employerLockedTry : room.agreedAmountTry;
      return `Anlaşmazlık açık. Kilitli tutar: ${formatMoney(locked)}. İnceleme süreci devam ediyor.`;
    }
    if (room.status === 'resolved') {
      return 'Anlaşmazlık sonuçlandı. Dağıtım backend kayıtlarına göre uygulandı.';
    }
    if (room.status === 'cancelled') {
      return 'İş iptal edildi.';
    }
    return '—';
  })();

  const stageAnswer = escrowRoomStatusLabel(room.status, role);

  const nextAnswer =
    next?.hint ??
    (room.status === 'completed'
      ? 'Bu anlaşma kapandı.'
      : room.status === 'disputed'
        ? 'Anlaşmazlık kayıtları inceleniyor; yeni işlem backend izin vermedikçe yapılamaz.'
        : 'Şu an beklenen bir işlem yok.');

  const disputeAnswer = (() => {
    if (room.dispute) {
      const parts = [
        `Durum: ${room.dispute.status || 'açık'}.`,
        room.dispute.reason ? `Gerekçe: ${room.dispute.reason}` : '',
        room.dispute.evidence ? `Kanıt notu kayıtlı.` : '',
        room.dispute.resolvedAt ? `Sonuçlandı: ${room.dispute.resolvedAt}` : '',
      ].filter(Boolean);
      return parts.join(' ');
    }
    if (['locked', 'completion_pending'].includes(room.status)) {
      return 'Sorun bildirimi açılabilir; süreç kayıtlı sözleşmeye göre ilerler. Garanti veya %100 iade iddiası yoktur.';
    }
    return 'Anlaşmazlık, kilitli emanet aşamasında veya sonrasında kayıt altına alınır.';
  })();

  const subjectAnswer =
    room.title?.trim() ||
    (room.description?.trim()
      ? room.description.trim().slice(0, 120) + (room.description.length > 120 ? '…' : '')
      : 'Henüz yazılı sözleşme metni yok.');

  const balanceNote =
    isEmployer && ['negotiating', 'terms_pending'].includes(room.status)
      ? ` Kullanılabilir bakiyeniz: ${formatMoney(availableBalance)}.`
      : '';

  return [
    {
      id: 'q1',
      question: '1. Ben kimim?',
      answer: `${myRoleLabel}${myName ? ` (${myName})` : ''}.`,
    },
    {
      id: 'q2',
      question: '2. Kiminle anlaşma yapıyorum?',
      answer: peerName
        ? `${peerName}${peerTicket ? ` · ZN-${peerTicket}` : ''}`
        : 'Karşı taraf bilgisi yükleniyor.',
    },
    {
      id: 'q3',
      question: '3. Ne üzerinde anlaşıyoruz?',
      answer: subjectAnswer,
    },
    {
      id: 'q4',
      question: '4. Para nerede?',
      answer: moneyAnswer + balanceNote,
      emphasis: room.status === 'terms_pending' || room.status === 'locked',
    },
    {
      id: 'q5',
      question: '5. Anlaşma hangi aşamada?',
      answer: stageAnswer,
      emphasis: room.status === 'terms_pending',
    },
    {
      id: 'q6',
      question: '6. Sıradaki işlemim ne?',
      answer: nextAnswer,
      emphasis: Boolean(next?.urgent),
    },
    {
      id: 'q7',
      question: '7. Sorun çıkarsa ne olacak?',
      answer: disputeAnswer,
      emphasis: room.status === 'disputed',
    },
  ];
}

export function escrowTermsPendingBanner(room: EscrowRoom): string | null {
  if (room.status !== 'terms_pending') return null;
  const role = room.myRole;
  if (role === 'employer' && room.termsProposedBy === 'employer') {
    return 'Şartlar henüz kesinleşmedi. Karşı tarafın (iş alanın) onayı bekleniyor. Para henüz kilitlenmedi.';
  }
  if (role === 'worker' && room.termsProposedBy === 'employer' && room.agreedAmountTry > 0) {
    return 'İşveren teklif gönderdi. Onaylamadan para kilitlenmez; reddedebilir, değişiklik isteyebilir veya karşı teklif verebilirsiniz.';
  }
  if (role === 'employer' && room.termsProposedBy === 'worker') {
    return 'Karşı teklif geldi. Onaylamadan para kilitlenmez.';
  }
  if (role === 'worker' && room.termsProposedBy === 'worker') {
    return 'Karşı teklifiniz işveren onayında. Para henüz kilitlenmedi.';
  }
  return 'Şartlar henüz kesinleşmedi. Para henüz kilitlenmedi.';
}

export function escrowPeerCard(room: EscrowRoom): {
  name: string;
  ticket: string;
  roleLabel: string;
} {
  const isEmployer = room.myRole === 'employer';
  return {
    name: (isEmployer ? room.workerName : room.employerName) || 'Karşı taraf',
    ticket: displayMemberTicket(isEmployer ? room.workerTicket : room.employerTicket),
    roleLabel: isEmployer ? ESCROW_PARTY.worker : ESCROW_PARTY.employer,
  };
}

export function roleLabel(role: EscrowRoomRole | null | undefined): string {
  if (role === 'employer') return ESCROW_PARTY.employer;
  if (role === 'worker') return ESCROW_PARTY.worker;
  return '—';
}
