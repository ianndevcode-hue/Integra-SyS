'use client';

import { useEffect, useState } from 'react';
import { formatCurrency } from '@integra/shared';
import { api } from '@/lib/api';

interface UsageRow {
  tenantId: string;
  tenant: { name: string; slug: string } | null;
  invoiceCount: number;
  totalAmount: string | null;
}

export default function UsoFiscalPage() {
  const [rows, setRows] = useState<UsageRow[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<UsageRow[]>('/admin/fiscal/usage')
      .then(setRows)
      .catch((err) => setError(err.message));
  }, []);

  return (
    <div className="page">
      <h1>Uso fiscal por cliente</h1>
      <p className="subtitle">Volume de notas e valor total emitido por tenant</p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Cliente (tenant)</th><th>Slug</th><th>Notas emitidas</th><th>Valor total</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={4} className="muted">Nenhum uso fiscal registrado.</td></tr>}
            {rows.map((usage) => (
              <tr key={usage.tenantId}>
                <td>{usage.tenant?.name ?? usage.tenantId}</td>
                <td className="mono">{usage.tenant?.slug ?? '—'}</td>
                <td><strong>{usage.invoiceCount}</strong></td>
                <td>{usage.totalAmount ? formatCurrency(Number(usage.totalAmount)) : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
