'use client';

import { useEffect, useState } from 'react';
import { formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface StatusRow {
  id: string;
  uf: string;
  environment: string;
  online: boolean;
  sefazCode: string | null;
  message: string | null;
  responseMs: number | null;
  checkedAt: string;
}

export default function SefazStatusPage() {
  const [rows, setRows] = useState<StatusRow[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<StatusRow[]>('/admin/fiscal/sefaz-status')
      .then(setRows)
      .catch((err) => setError(err.message));
  }, []);

  return (
    <div className="page">
      <h1>Status dos serviços SEFAZ</h1>
      <p className="subtitle">Histórico das consultas de status por UF e ambiente</p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>UF</th><th>Ambiente</th><th>Status</th><th>cStat</th><th>Mensagem</th><th>Resposta</th><th>Verificado</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr><td colSpan={7} className="muted">Nenhuma consulta registrada. As consultas são feitas pelas empresas na tela fiscal.</td></tr>
            )}
            {rows.map((status) => (
              <tr key={status.id}>
                <td><strong>{status.uf}</strong></td>
                <td>{status.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</td>
                <td style={{ color: status.online ? '#16a34a' : '#dc2626', fontWeight: 600 }}>
                  {status.online ? 'Online' : 'Offline'}
                </td>
                <td className="mono">{status.sefazCode ?? '—'}</td>
                <td>{status.message ?? '—'}</td>
                <td>{status.responseMs !== null ? `${status.responseMs} ms` : '—'}</td>
                <td className="muted">{formatDateTime(status.checkedAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
