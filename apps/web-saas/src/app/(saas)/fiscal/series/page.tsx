'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';

interface Sequence {
  id: string;
  model: 'NFE_55' | 'NFCE_65';
  series: number;
  currentNumber: number;
  nextNumber: number;
  environment: 'HOMOLOGATION' | 'PRODUCTION';
  status: string;
}

export default function SeriesPage() {
  const [sequences, setSequences] = useState<Sequence[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<Sequence[]>('/fiscal/sequences')
      .then(setSequences)
      .catch((err) => setError(err.message));
  }, []);

  return (
    <div className="page">
      <h1>Série e Numeração</h1>
      <p className="subtitle">
        Numeração controlada por empresa, modelo, série e ambiente — homologação e produção nunca se misturam
      </p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr>
              <th>Modelo</th>
              <th>Série</th>
              <th>Ambiente</th>
              <th>Número atual</th>
              <th>Próximo número</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {sequences.length === 0 && (
              <tr><td colSpan={6} className="muted">Nenhuma sequência criada ainda. Elas são criadas automaticamente na primeira emissão.</td></tr>
            )}
            {sequences.map((sequence) => (
              <tr key={sequence.id}>
                <td>{sequence.model === 'NFE_55' ? 'NF-e (55)' : 'NFC-e (65)'}</td>
                <td>{sequence.series}</td>
                <td>{sequence.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</td>
                <td>{sequence.currentNumber}</td>
                <td><strong>{sequence.nextNumber}</strong></td>
                <td>{sequence.status}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <p className="muted" style={{ marginTop: 12 }}>
          Para ajustar o próximo número, use a tela de Configurações Fiscais. Números já utilizados não podem se repetir.
        </p>
      </div>
    </div>
  );
}
