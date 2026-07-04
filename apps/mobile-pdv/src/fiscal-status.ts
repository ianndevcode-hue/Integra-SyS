import type { PdvFiscalStatusLocal } from './types';

export const FISCAL_STATUS_LABELS: Record<PdvFiscalStatusLocal, string> = {
  NO_INVOICE: 'Sem nota',
  PENDING_EMISSION: 'Pendente de emissão',
  EMITTING: 'Emitindo',
  AUTHORIZED: 'Autorizada',
  REJECTED: 'Rejeitada',
  CANCELED: 'Cancelada',
};

export const FISCAL_STATUS_COLORS: Record<PdvFiscalStatusLocal, string> = {
  NO_INVOICE: '#6b7280',
  PENDING_EMISSION: '#d97706',
  EMITTING: '#2563eb',
  AUTHORIZED: '#16a34a',
  REJECTED: '#dc2626',
  CANCELED: '#4b5563',
};
