'use client';

import { useEffect, useState } from 'react';
import { formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface LogRow {
  id: string;
  service: string;
  uf: string | null;
  environment: string;
  statusCode: string | null;
  message: string | null;
  durationMs: number | null;
  createdAt: string;
  company: { corporateName: string } | null;
}

export default function AdminLogsPage() {
  const [rows, setRows] = useState<LogRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: LogRow[]; total: number }>(`/admin/fiscal/logs?page=${page}`)
      .then((response) => {
        setRows(response.data);
        setTotal(response.total);
      })
      .catch((err) => setError(err.message));
  }, [page]);

  return (
    <div className="page" style={{ maxWidth: 1400 }}>
      <h1>Logs do fiscal-service</h1>
      <p className="subtitle">Comunicações com os webservices da SEFAZ registradas por empresa</p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Empresa</th><th>Serviço</th><th>UF</th><th>Ambiente</th><th>Status</th><th>Duração</th><th>Data</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={7} className="muted">Nenhum log registrado.</td></tr>}
            {rows.map((log) => (
              <tr key={log.id}>
                <td>{log.company?.corporateName ?? '—'}</td>
                <td className="mono">{log.service}</td>
                <td>{log.uf ?? '—'}</td>
                <td>{log.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</td>
                <td className="mono">{log.statusCode ?? '—'}</td>
                <td>{log.durationMs !== null ? `${log.durationMs} ms` : '—'}</td>
                <td className="muted">{formatDateTime(log.createdAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 16 }}>
          <button className="btn btn-sm btn-secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Anterior</button>
          <span className="muted">Página {page} — {total} registro(s)</span>
          <button className="btn btn-sm btn-secondary" disabled={page * 50 >= total} onClick={() => setPage((p) => p + 1)}>Próxima</button>
        </div>
      </div>
    </div>
  );
}
