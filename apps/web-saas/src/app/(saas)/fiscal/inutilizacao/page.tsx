'use client';

import { useState } from 'react';
import { api } from '@/lib/api';

export default function InutilizacaoPage() {
  const [model, setModel] = useState<'55' | '65'>('55');
  const [series, setSeries] = useState(1);
  const [startNumber, setStartNumber] = useState(1);
  const [endNumber, setEndNumber] = useState(1);
  const [justification, setJustification] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ success: boolean; sefazCode?: string; sefazMessage?: string; protocol?: string } | null>(null);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!window.confirm(`Inutilizar a faixa ${startNumber}-${endNumber} da série ${series} (modelo ${model})? Esta ação é irreversível.`)) {
      return;
    }
    setLoading(true);
    setError(null);
    setResult(null);
    try {
      const response = await api<typeof result>('/fiscal/invoices/inutilize', {
        method: 'POST',
        body: { model, series: Number(series), startNumber: Number(startNumber), endNumber: Number(endNumber), justification },
      });
      setResult(response);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha na inutilização');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="page">
      <h1>Inutilização de Numeração</h1>
      <p className="subtitle">Inutiliza faixas de numeração não utilizadas junto à SEFAZ. Exige justificativa.</p>

      {error && <div className="alert alert-error">{error}</div>}
      {result && (
        <div className={`alert ${result.success ? 'alert-success' : 'alert-error'}`}>
          {result.success ? 'Inutilização homologada.' : 'Inutilização rejeitada.'}{' '}
          {result.sefazCode}: {result.sefazMessage} {result.protocol ? `— Protocolo ${result.protocol}` : ''}
        </div>
      )}

      <form onSubmit={handleSubmit} className="card">
        <div className="form-grid">
          <div>
            <label>Modelo</label>
            <select value={model} onChange={(e) => setModel(e.target.value as '55' | '65')}>
              <option value="55">NF-e (55)</option>
              <option value="65">NFC-e (65)</option>
            </select>
          </div>
          <div>
            <label>Série</label>
            <input type="number" min={0} value={series} onChange={(e) => setSeries(Number(e.target.value))} required />
          </div>
          <div>
            <label>Número inicial</label>
            <input type="number" min={1} value={startNumber} onChange={(e) => setStartNumber(Number(e.target.value))} required />
          </div>
          <div>
            <label>Número final</label>
            <input type="number" min={1} value={endNumber} onChange={(e) => setEndNumber(Number(e.target.value))} required />
          </div>
        </div>
        <div className="field" style={{ marginTop: 12 }}>
          <label>Justificativa (mínimo 15 caracteres)</label>
          <textarea rows={3} value={justification} onChange={(e) => setJustification(e.target.value)} required minLength={15} />
        </div>
        <button className="btn btn-danger" disabled={loading}>
          {loading ? 'Enviando…' : 'Inutilizar numeração'}
        </button>
      </form>
    </div>
  );
}
