import type { InvoiceStatus } from '@integra/types';

export const colors = {
  primary: '#1d4ed8',
  primaryDark: '#1e3a8a',
  success: '#16a34a',
  warning: '#d97706',
  danger: '#dc2626',
  muted: '#6b7280',
  surface: '#ffffff',
  background: '#f3f4f6',
};

export const INVOICE_STATUS_COLORS: Record<InvoiceStatus, { bg: string; fg: string }> = {
  DRAFT: { bg: '#e5e7eb', fg: '#374151' },
  PENDING: { bg: '#fef3c7', fg: '#92400e' },
  SENDING: { bg: '#dbeafe', fg: '#1e40af' },
  AUTHORIZED: { bg: '#dcfce7', fg: '#166534' },
  REJECTED: { bg: '#fee2e2', fg: '#991b1b' },
  DENIED: { bg: '#fee2e2', fg: '#7f1d1d' },
  CANCELED: { bg: '#f3f4f6', fg: '#4b5563' },
  INUTILIZED: { bg: '#ede9fe', fg: '#5b21b6' },
  CONTINGENCY: { bg: '#ffedd5', fg: '#9a3412' },
  ERROR: { bg: '#fee2e2', fg: '#b91c1c' },
};
