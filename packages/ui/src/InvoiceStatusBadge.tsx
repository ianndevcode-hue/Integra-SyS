import type { CSSProperties } from 'react';
import type { InvoiceStatus } from '@integra/types';
import { INVOICE_STATUS_LABELS } from '@integra/types';
import { INVOICE_STATUS_COLORS } from './theme';

export interface InvoiceStatusBadgeProps {
  status: InvoiceStatus;
  style?: CSSProperties;
}

export function InvoiceStatusBadge({ status, style }: InvoiceStatusBadgeProps) {
  const palette = INVOICE_STATUS_COLORS[status] ?? INVOICE_STATUS_COLORS.DRAFT;
  return (
    <span
      style={{
        display: 'inline-block',
        padding: '2px 10px',
        borderRadius: 999,
        fontSize: 12,
        fontWeight: 600,
        backgroundColor: palette.bg,
        color: palette.fg,
        whiteSpace: 'nowrap',
        ...style,
      }}
    >
      {INVOICE_STATUS_LABELS[status] ?? status}
    </span>
  );
}
