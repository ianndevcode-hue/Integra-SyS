'use client';

import { useCallback, useEffect, useState } from 'react';
import { formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface CertificateInfo {
  id: string;
  subjectCnpj: string | null;
  serialNumber: string | null;
  validFrom: string | null;
  validUntil: string | null;
  status: string;
}

export default function CertificadoPage() {
  const [certificate, setCertificate] = useState<CertificateInfo | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ certificate: CertificateInfo | null }>('/fiscal/config')
      .then((data) => setCertificate(data.certificate))
      .catch((err) => setError(err.message));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function handleUpload(event: React.FormEvent) {
    event.preventDefault();
    if (!file) return;
    setLoading(true);
    setError(null);
    setSuccess(null);

    try {
      const buffer = await file.arrayBuffer();
      const bytes = new Uint8Array(buffer);
      let binary = '';
      for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
      const fileBase64 = btoa(binary);

      const result = await api<CertificateInfo>('/fiscal/certificates', {
        method: 'POST',
        body: { fileBase64, fileName: file.name, password },
      });

      setSuccess(`Certificado válido até ${result.validUntil ? formatDateTime(result.validUntil) : '—'}.`);
      setPassword('');
      setFile(null);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha no upload');
    } finally {
      setLoading(false);
    }
  }

  async function handleTest() {
    if (!certificate) return;
    setLoading(true);
    setError(null);
    setSuccess(null);
    try {
      const result = await api<{ success: boolean; validUntil?: string; daysToExpire?: number; status?: string }>(
        `/fiscal/certificates/${certificate.id}/test`,
        { method: 'POST' },
      );
      setSuccess(
        `Certificado OK — status ${result.status}, expira em ${result.daysToExpire} dia(s) (${result.validUntil ? formatDateTime(result.validUntil) : '—'}).`,
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha no teste do certificado');
    } finally {
      setLoading(false);
    }
  }

  async function handleRemove() {
    if (!certificate) return;
    if (!window.confirm('Remover o certificado? A emissão ficará bloqueada até novo upload.')) return;
    setLoading(true);
    setError(null);
    try {
      await api(`/fiscal/certificates/${certificate.id}`, { method: 'DELETE' });
      setSuccess('Certificado removido.');
      setCertificate(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao remover');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="page">
      <h1>Certificado Digital A1</h1>
      <p className="subtitle">
        Upload do arquivo .pfx/.p12 — a senha é criptografada (AES-256-GCM) e nunca é salva em texto puro
      </p>

      {error && <div className="alert alert-error">{error}</div>}
      {success && <div className="alert alert-success">{success}</div>}

      {certificate ? (
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Certificado atual</h3>
          <table>
            <tbody>
              <tr><td>CNPJ do certificado</td><td className="mono">{certificate.subjectCnpj ?? '—'}</td></tr>
              <tr><td>Número de série</td><td className="mono">{certificate.serialNumber ?? '—'}</td></tr>
              <tr><td>Válido de</td><td>{certificate.validFrom ? formatDateTime(certificate.validFrom) : '—'}</td></tr>
              <tr><td>Válido até</td><td>{certificate.validUntil ? formatDateTime(certificate.validUntil) : '—'}</td></tr>
              <tr><td>Status</td><td><strong>{certificate.status}</strong></td></tr>
            </tbody>
          </table>
          <div className="actions" style={{ marginTop: 16 }}>
            <button className="btn btn-secondary" onClick={handleTest} disabled={loading}>Testar certificado</button>
            <button className="btn btn-danger" onClick={handleRemove} disabled={loading}>Remover certificado</button>
          </div>
        </div>
      ) : (
        <div className="alert alert-warning">Nenhum certificado ativo. Faça o upload abaixo para habilitar a emissão.</div>
      )}

      <form onSubmit={handleUpload} className="card">
        <h3 style={{ marginBottom: 12 }}>Enviar novo certificado</h3>
        <div className="field">
          <label>Arquivo A1 (.pfx ou .p12)</label>
          <input
            type="file"
            accept=".pfx,.p12"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            required
          />
        </div>
        <div className="field">
          <label>Senha do certificado</label>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </div>
        <button className="btn" disabled={loading || !file}>
          {loading ? 'Validando…' : 'Enviar e validar certificado'}
        </button>
      </form>
    </div>
  );
}
