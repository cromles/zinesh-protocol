import type { EscrowRoom, EscrowRoomStatus } from './escrowRoomApi';

const TERMINAL_STATUSES: EscrowRoomStatus[] = ['completed', 'cancelled', 'resolved'];

export function isActiveEscrowRoom(room: EscrowRoom): boolean {
  return !TERMINAL_STATUSES.includes(room.status);
}

export function isCompletedEscrowRoom(room: EscrowRoom): boolean {
  return room.status === 'completed' || room.status === 'resolved';
}

export type ConsoleDashboardMetrics = {
  totalAgreements: number;
  activeAgreements: number;
  completedAgreements: number;
  totalVolumeTry: number;
};

export function computeDashboardMetrics(rooms: EscrowRoom[]): ConsoleDashboardMetrics {
  const totalAgreements = rooms.length;
  const activeAgreements = rooms.filter(isActiveEscrowRoom).length;
  const completedAgreements = rooms.filter(isCompletedEscrowRoom).length;
  const totalVolumeTry = rooms
    .filter(isCompletedEscrowRoom)
    .reduce((sum, room) => sum + Math.max(0, room.agreedAmountTry || 0), 0);

  return {
    totalAgreements,
    activeAgreements,
    completedAgreements,
    totalVolumeTry,
  };
}

export function sortActiveEscrowRooms(rooms: EscrowRoom[]): EscrowRoom[] {
  return rooms
    .filter(isActiveEscrowRoom)
    .sort((a, b) => {
      const aTime = Date.parse(a.termsProposedAt || a.createdAt || '') || 0;
      const bTime = Date.parse(b.termsProposedAt || b.createdAt || '') || 0;
      return bTime - aTime;
    });
}

export function roomLastUpdatedIso(room: EscrowRoom): string {
  return (
    room.completedAt ||
    room.lockedAt ||
    room.termsProposedAt ||
    room.createdAt ||
    ''
  );
}
