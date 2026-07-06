'use client';

import { useEffect, useState } from 'react';
import { formatCurrency } from '@integra/shared';
import { api } from '@/lib/api';
import type { ReportsSummaryDto } from '@integra/types';

export default function RelatoriosPage() {
  const [data, setData] = useState<ReportsSummaryDto | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<ReportsSummaryDto>('/erp/reports/summary')
      .then(setData)
      .catch((err) => setError(err.message));
  }, []);

  if (error) {
    return (
      <div className="page">
        <h1>Relatórios</h1>
        <div className="alert alert-error">{error}</div>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="page">
        <h1>Relatórios</h1>
        <p className="muted">Carregando…</p>
      </div>
    );
  }

  return (
    <div className="page">
      <h1>Relatórios</h1>
      <p className="subtitle">Resumo comercial dos últimos 30 dias</p>

      <div className="grid grid-2">
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Vendas por dia</h3>
          <table>
            <thead><tr><th>Data</th><th>Qtd</th><th>Total</th></tr></thead>
            <tbody>
              {data.salesByDay.length === 0 ? (
                <tr><td colSpan={3} className="muted">Sem vendas no período.</td></tr>
              ) : (
                data.salesByDay.map((row) => (
                  <tr key={row.date}>
                    <td>{row.date}</td>
                    <td>{row.count}</td>
                    <td>{formatCurrency(row.total)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Mix de pagamento</h3>
          <table>
            <thead><tr><th>Forma</th><th>Valor</th></tr></thead>
            <tbody>
              {data.paymentMix.length === 0 ? (
                <tr><td colSpan={2} className="muted">Sem pagamentos no período.</td></tr>
              ) : (
                data.paymentMix.map((row) => (
                  <tr key={row.method}>
                    <td>{row.method}</td>
                    <td>{formatCurrency(row.amount)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Top produtos (faturamento)</h3>
        <table>
          <thead><tr><th>Código</th><th>Produto</th><th>Faturamento</th></tr></thead>
          <tbody>
            {data.topProducts.length === 0 ? (
              <tr><td colSpan={3} className="muted">Sem itens faturados no período.</td></tr>
            ) : (
              data.topProducts.map((row) => (
                <tr key={row.code}>
                  <td className="mono">{row.code}</td>
                  <td>{row.description}</td>
                  <td>{formatCurrency(row.revenue)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
