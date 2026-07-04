'use client';

import { useEffect, useState } from 'react';
import { UF_LIST } from '@integra/shared';
import { api } from '@/lib/api';

interface ConfigResponse {
  config: {
    id: string;
    uf: string;
    cityCode: string;
    cityName: string;
    stateRegistration: string | null;
    municipalRegistration: string | null;
    taxRegime: string;
    crt: number;
    environment: 'HOMOLOGATION' | 'PRODUCTION';
    defaultModel: string;
    defaultNfeSeries: number;
    defaultNfceSeries: number;
    nfeEnabled: boolean;
    nfceEnabled: boolean;
    technician: { name: string; email: string; cnpj: string; phone: string } | null;
  } | null;
  sequences: Array<{ model: string; series: number; nextNumber: number; environment: string }>;
}

export default function ConfiguracoesFiscaisPage() {
  const [form, setForm] = useState({
    taxRegime: 'SIMPLES_NACIONAL',
    crt: 1,
    uf: 'SP',
    cityName: '',
    cityCode: '',
    stateRegistration: '',
    municipalRegistration: '',
    environment: 'homologation',
    defaultNfeSeries: 1,
    defaultNfceSeries: 1,
    nextNfeNumber: '' as string | number,
    nextNfceNumber: '' as string | number,
    nfeEnabled: false,
    nfceEnabled: false,
    technicianName: '',
    technicianEmail: '',
    technicianCnpj: '',
    technicianPhone: '',
  });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  useEffect(() => {
    api<ConfigResponse>('/fiscal/config')
      .then((data) => {
        if (!data.config) return;
        const c = data.config;
        const nfeSeq = data.sequences.find((s) => s.model === 'NFE_55' && s.series === c.defaultNfeSeries && s.environment === c.environment);
        const nfceSeq = data.sequences.find((s) => s.model === 'NFCE_65' && s.series === c.defaultNfceSeries && s.environment === c.environment);
        setForm((prev) => ({
          ...prev,
          taxRegime: c.taxRegime,
          crt: c.crt,
          uf: c.uf,
          cityName: c.cityName,
          cityCode: c.cityCode,
          stateRegistration: c.stateRegistration ?? '',
          municipalRegistration: c.municipalRegistration ?? '',
          environment: c.environment === 'PRODUCTION' ? 'production' : 'homologation',
          defaultNfeSeries: c.defaultNfeSeries,
          defaultNfceSeries: c.defaultNfceSeries,
          nextNfeNumber: nfeSeq?.nextNumber ?? '',
          nextNfceNumber: nfceSeq?.nextNumber ?? '',
          nfeEnabled: c.nfeEnabled,
          nfceEnabled: c.nfceEnabled,
          technicianName: c.technician?.name ?? '',
          technicianEmail: c.technician?.email ?? '',
          technicianCnpj: c.technician?.cnpj ?? '',
          technicianPhone: c.technician?.phone ?? '',
        }));
      })
      .catch((err) => setError(err.message));
  }, []);

  function set<K extends keyof typeof form>(key: K, value: (typeof form)[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    setSuccess(null);
    try {
      await api('/fiscal/config', {
        method: 'PATCH',
        body: {
          taxRegime: form.taxRegime,
          crt: Number(form.crt),
          uf: form.uf,
          cityName: form.cityName,
          cityCode: form.cityCode,
          stateRegistration: form.stateRegistration || undefined,
          municipalRegistration: form.municipalRegistration || undefined,
          environment: form.environment,
          defaultNfeSeries: Number(form.defaultNfeSeries),
          defaultNfceSeries: Number(form.defaultNfceSeries),
          nextNfeNumber: form.nextNfeNumber ? Number(form.nextNfeNumber) : undefined,
          nextNfceNumber: form.nextNfceNumber ? Number(form.nextNfceNumber) : undefined,
          nfeEnabled: form.nfeEnabled,
          nfceEnabled: form.nfceEnabled,
          technicianName: form.technicianName || undefined,
          technicianEmail: form.technicianEmail || undefined,
          technicianCnpj: form.technicianCnpj || undefined,
          technicianPhone: form.technicianPhone || undefined,
        },
      });
      setSuccess('Configurações fiscais salvas.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao salvar');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="page">
      <h1>Configurações Fiscais</h1>
      <p className="subtitle">Regime, ambiente, séries, numeração e responsável técnico</p>

      {error && <div className="alert alert-error">{error}</div>}
      {success && <div className="alert alert-success">{success}</div>}
      {form.environment === 'production' && (
        <div className="alert alert-warning">
          Ambiente de <strong>produção</strong>: as notas emitidas têm valor fiscal. Não use dados fictícios.
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Tributação e localização</h3>
          <div className="form-grid">
            <div>
              <label>Regime tributário</label>
              <select value={form.taxRegime} onChange={(e) => set('taxRegime', e.target.value)}>
                <option value="SIMPLES_NACIONAL">Simples Nacional</option>
                <option value="SIMPLES_NACIONAL_EXCESSO">Simples Nacional — excesso de sublimite</option>
                <option value="REGIME_NORMAL">Regime Normal</option>
                <option value="MEI">MEI</option>
              </select>
            </div>
            <div>
              <label>CRT</label>
              <select value={form.crt} onChange={(e) => set('crt', Number(e.target.value))}>
                <option value={1}>1 — Simples Nacional</option>
                <option value={2}>2 — Simples Nacional (excesso)</option>
                <option value={3}>3 — Regime Normal</option>
                <option value={4}>4 — MEI</option>
              </select>
            </div>
            <div>
              <label>UF</label>
              <select value={form.uf} onChange={(e) => set('uf', e.target.value)}>
                {UF_LIST.map((uf) => <option key={uf} value={uf}>{uf}</option>)}
              </select>
            </div>
            <div>
              <label>Município</label>
              <input value={form.cityName} onChange={(e) => set('cityName', e.target.value)} />
            </div>
            <div>
              <label>Código IBGE</label>
              <input value={form.cityCode} onChange={(e) => set('cityCode', e.target.value)} maxLength={7} placeholder="7 dígitos" />
            </div>
            <div>
              <label>Inscrição Estadual</label>
              <input value={form.stateRegistration} onChange={(e) => set('stateRegistration', e.target.value)} placeholder="ou ISENTO" />
            </div>
            <div>
              <label>Inscrição Municipal</label>
              <input value={form.municipalRegistration} onChange={(e) => set('municipalRegistration', e.target.value)} />
            </div>
          </div>
        </div>

        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Ambiente, modelos e numeração</h3>
          <div className="form-grid">
            <div>
              <label>Ambiente</label>
              <select value={form.environment} onChange={(e) => set('environment', e.target.value)}>
                <option value="homologation">Homologação</option>
                <option value="production">Produção</option>
              </select>
            </div>
            <div>
              <label>NF-e habilitada</label>
              <select value={form.nfeEnabled ? '1' : '0'} onChange={(e) => set('nfeEnabled', e.target.value === '1')}>
                <option value="1">Sim</option>
                <option value="0">Não</option>
              </select>
            </div>
            <div>
              <label>NFC-e habilitada</label>
              <select value={form.nfceEnabled ? '1' : '0'} onChange={(e) => set('nfceEnabled', e.target.value === '1')}>
                <option value="1">Sim</option>
                <option value="0">Não</option>
              </select>
            </div>
            <div>
              <label>Série NF-e</label>
              <input type="number" min={1} value={form.defaultNfeSeries} onChange={(e) => set('defaultNfeSeries', Number(e.target.value))} />
            </div>
            <div>
              <label>Série NFC-e</label>
              <input type="number" min={1} value={form.defaultNfceSeries} onChange={(e) => set('defaultNfceSeries', Number(e.target.value))} />
            </div>
            <div>
              <label>Próximo número NF-e</label>
              <input type="number" min={1} value={form.nextNfeNumber} onChange={(e) => set('nextNfeNumber', e.target.value)} />
            </div>
            <div>
              <label>Próximo número NFC-e</label>
              <input type="number" min={1} value={form.nextNfceNumber} onChange={(e) => set('nextNfceNumber', e.target.value)} />
            </div>
          </div>
          <p className="muted" style={{ marginTop: 10 }}>
            Homologação e produção mantêm numeração separada, controlada por empresa, modelo e série.
          </p>
        </div>

        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Responsável técnico</h3>
          <div className="form-grid">
            <div>
              <label>Nome do responsável</label>
              <input value={form.technicianName} onChange={(e) => set('technicianName', e.target.value)} />
            </div>
            <div>
              <label>E-mail do responsável</label>
              <input type="email" value={form.technicianEmail} onChange={(e) => set('technicianEmail', e.target.value)} />
            </div>
            <div>
              <label>CNPJ do responsável técnico</label>
              <input value={form.technicianCnpj} onChange={(e) => set('technicianCnpj', e.target.value)} />
            </div>
            <div>
              <label>Telefone do responsável</label>
              <input value={form.technicianPhone} onChange={(e) => set('technicianPhone', e.target.value)} />
            </div>
          </div>
        </div>

        <button className="btn" disabled={loading}>{loading ? 'Salvando…' : 'Salvar configurações'}</button>
      </form>
    </div>
  );
}
