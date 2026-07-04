'use client';

import { useEffect, useState } from 'react';
import { formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface Rejection {
  id: string;
  code: string;
  message: string;
  source: string;
  solutionHint: string | null;
  createdAt: string;
  invoice: { model: string; series: number; number: number; status: string } | null;
}

export default function RejeicoesPage() {
  const [rows, setRows] = useState<Rejection[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: Rejection[]; total: number }>(`/fiscal/rejections?page=${page}`)
      .then((response) => {
        setRows(response.data);
        setTotal(response.total);
      })
      .catch((err) => setError(err.message));
  }, [page]);

  return (
    <div className="page">
      <h1>Rejeições</h1>
      <p className="subtitle">Histórico de rejeições da SEFAZ com código e mensagem, para correção e reenvio</p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Código</th><th>Mensagem</th><th>Nota</th><th>Origem</th><th>Data</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={5} className="muted">Nenhuma rejeição registrada.</td></tr>}
            {rows.map((rejection) => (
              <tr key={rejection.id}>
                <td className="mono"><strong>{rejection.code}</strong></td>
                <td>
                  {rejection.message}
                  {rejection.solutionHint && <div className="muted">Dica: {rejection.solutionHint}</div>}
                </td>
                <td>
                  {rejection.invoice
                    ? `${rejection.invoice.model === 'NFE_55' ? 'NF-e' : 'NFC-e'} ${rejection.invoice.series}/${rejection.invoice.number}`
                    : '—'}
                </td>
                <td>{rejection.source}</td>
                <td className="muted">{formatDateTime(rejection.createdAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 16 }}>
          <button className="btn btn-sm btn-secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Anterior</button>
          <span className="muted">Página {page} — {total} rejeição(ões)</span>
          <button className="btn btn-sm btn-secondary" disabled={page * 20 >= total} onClick={() => setPage((p) => p + 1)}>Próxima</button>
        </div>
      </div>
    </div>
  );
}
