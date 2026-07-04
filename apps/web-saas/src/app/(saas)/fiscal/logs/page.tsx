'use client';

import { useEffect, useState } from 'react';
import { formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface WebserviceLog {
  id: string;
  service: string;
  uf: string | null;
  environment: string;
  statusCode: string | null;
  message: string | null;
  durationMs: number | null;
  createdAt: string;
}

export default function LogsPage() {
  const [rows, setRows] = useState<WebserviceLog[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: WebserviceLog[]; total: number }>(`/fiscal/logs?page=${page}`)
      .then((response) => {
        setRows(response.data);
        setTotal(response.total);
      })
      .catch((err) => setError(err.message));
  }, [page]);

  return (
    <div className="page">
      <h1>Logs Fiscais</h1>
      <p className="subtitle">Registro das comunicações com os webservices da SEFAZ</p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Serviço</th><th>UF</th><th>Ambiente</th><th>Status</th><th>Mensagem</th><th>Duração</th><th>Data</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={7} className="muted">Nenhum log registrado.</td></tr>}
            {rows.map((log) => (
              <tr key={log.id}>
                <td className="mono">{log.service}</td>
                <td>{log.uf ?? '—'}</td>
                <td>{log.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</td>
                <td className="mono">{log.statusCode ?? '—'}</td>
                <td>{log.message ?? '—'}</td>
                <td>{log.durationMs !== null ? `${log.durationMs} ms` : '—'}</td>
                <td className="muted">{formatDateTime(log.createdAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 16 }}>
          <button className="btn btn-sm btn-secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Anterior</button>
          <span className="muted">Página {page} — {total} registro(s)</span>
          <button className="btn btn-sm btn-secondary" disabled={page * 20 >= total} onClick={() => setPage((p) => p + 1)}>Próxima</button>
        </div>
      </div>
    </div>
  );
}
