'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';

interface CscInfo {
  id: string;
  cscId: string;
  environment: string;
  active: boolean;
}

export default function CscPage() {
  const [current, setCurrent] = useState<CscInfo | null>(null);
  const [cscId, setCscId] = useState('');
  const [cscToken, setCscToken] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  useEffect(() => {
    api<{ csc: CscInfo | null }>('/fiscal/config')
      .then((data) => setCurrent(data.csc))
      .catch((err) => setError(err.message));
  }, []);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    setSuccess(null);
    try {
      await api('/fiscal/config', { method: 'PATCH', body: { cscId, cscToken } });
      setSuccess('CSC/Token salvo com criptografia. NFC-e liberada para o ambiente atual.');
      setCscToken('');
      const data = await api<{ csc: CscInfo | null }>('/fiscal/config');
      setCurrent(data.csc);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao salvar CSC');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="page">
      <h1>CSC / Token NFC-e</h1>
      <p className="subtitle">
        Código de Segurança do Contribuinte, obrigatório para emissão de NFC-e (modelo 65).
        O token é criptografado — nunca fica em texto puro.
      </p>

      {error && <div className="alert alert-error">{error}</div>}
      {success && <div className="alert alert-success">{success}</div>}

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>CSC atual</h3>
        {current ? (
          <table>
            <tbody>
              <tr><td>CSC ID</td><td className="mono">{current.cscId}</td></tr>
              <tr><td>Token</td><td className="mono">•••••••••••••••• (criptografado)</td></tr>
              <tr><td>Ambiente</td><td>{current.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</td></tr>
              <tr><td>Ativo</td><td>{current.active ? 'Sim' : 'Não'}</td></tr>
            </tbody>
          </table>
        ) : (
          <p className="muted">Nenhum CSC cadastrado para o ambiente atual. NFC-e bloqueada até o cadastro.</p>
        )}
      </div>

      <form onSubmit={handleSubmit} className="card">
        <h3 style={{ marginBottom: 12 }}>Cadastrar/atualizar CSC</h3>
        <div className="form-grid">
          <div>
            <label>CSC ID</label>
            <input value={cscId} onChange={(e) => setCscId(e.target.value)} required placeholder="Ex: 000001" />
          </div>
          <div>
            <label>CSC Token</label>
            <input value={cscToken} onChange={(e) => setCscToken(e.target.value)} required type="password" placeholder="Token fornecido pela SEFAZ" />
          </div>
        </div>
        <button className="btn" disabled={loading} style={{ marginTop: 8 }}>
          {loading ? 'Salvando…' : 'Salvar CSC'}
        </button>
      </form>
    </div>
  );
}
