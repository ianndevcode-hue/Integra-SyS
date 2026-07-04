'use client';

import { useEffect, useState } from 'react';
import { formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface FailureRow {
  id: string;
  code: string;
  message: string;
  source: string;
  createdAt: string;
  company: { corporateName: string; cnpj: string } | null;
  invoice: { model: string; series: number; number: number } | null;
}

export default function FalhasPage() {
  const [rows, setRows] = useState<FailureRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: FailureRow[]; total: number }>(`/admin/fiscal/failures?page=${page}`)
      .then((response) => {
        setRows(response.data);
        setTotal(response.total);
      })
      .catch((err) => setError(err.message));
  }, [page]);

  return (
    <div className="page" style={{ maxWidth: 1400 }}>
      <h1>Falhas fiscais recentes</h1>
      <p className="subtitle">Rejeições e erros de todas as empresas, para suporte e diagnóstico</p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Empresa</th><th>Código</th><th>Mensagem</th><th>Nota</th><th>Origem</th><th>Data</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={6} className="muted">Nenhuma falha registrada.</td></tr>}
            {rows.map((failure) => (
              <tr key={failure.id}>
                <td>{failure.company?.corporateName ?? '—'}</td>
                <td className="mono"><strong>{failure.code}</strong></td>
                <td>{failure.message}</td>
                <td>
                  {failure.invoice
                    ? `${failure.invoice.model === 'NFE_55' ? 'NF-e' : 'NFC-e'} ${failure.invoice.series}/${failure.invoice.number}`
                    : '—'}
                </td>
                <td>{failure.source}</td>
                <td className="muted">{formatDateTime(failure.createdAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 16 }}>
          <button className="btn btn-sm btn-secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Anterior</button>
          <span className="muted">Página {page} — {total} falha(s)</span>
          <button className="btn btn-sm btn-secondary" disabled={page * 50 >= total} onClick={() => setPage((p) => p + 1)}>Próxima</button>
        </div>
      </div>
    </div>
  );
}
