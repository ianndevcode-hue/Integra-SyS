'use client';

import { useEffect, useState } from 'react';
import { formatCurrency, formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';
import type { SaleDto } from '@integra/types';

export default function VendasPage() {
  const [sales, setSales] = useState<SaleDto[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<SaleDto[]>('/erp/sales')
      .then(setSales)
      .catch((err) => setError(err.message));
  }, []);

  return (
    <div className="page">
      <h1>Vendas</h1>
      <p className="subtitle">Histórico de vendas do PDV e emissões fiscais</p>
      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr>
              <th>Data</th>
              <th>Cliente</th>
              <th>Total</th>
              <th>Status</th>
              <th>NFC-e/NF-e</th>
              <th>Nota</th>
            </tr>
          </thead>
          <tbody>
            {sales.length === 0 ? (
              <tr><td colSpan={6} className="muted">Nenhuma venda registrada.</td></tr>
            ) : (
              sales.map((sale) => (
                <tr key={sale.id}>
                  <td>{formatDateTime(sale.soldAt)}</td>
                  <td>{sale.customerName ?? 'Consumidor'}</td>
                  <td>{formatCurrency(sale.total)}</td>
                  <td>{sale.status}</td>
                  <td>{sale.invoiceStatus ?? '—'}</td>
                  <td>{sale.invoiceNumber ?? '—'}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
