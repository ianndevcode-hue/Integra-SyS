'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';

interface Health {
  status: string;
  service?: string;
  time?: string;
  error?: string;
}

export default function ConfigGlobalPage() {
  const [health, setHealth] = useState<Health | null>(null);

  useEffect(() => {
    api<{ fiscalServiceHealth: Health }>('/admin/fiscal/overview')
      .then((data) => setHealth(data.fiscalServiceHealth))
      .catch((err) => setHealth({ status: 'offline', error: err.message }));
  }, []);

  return (
    <div className="page">
      <h1>Configuração global do fiscal-service</h1>
      <p className="subtitle">
        Parâmetros de infraestrutura do microserviço fiscal — alterados via variáveis de ambiente (.env / docker-compose)
      </p>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Status do serviço</h3>
        {health ? (
          <table>
            <tbody>
              <tr>
                <td>Status</td>
                <td style={{ color: health.status === 'ok' ? '#16a34a' : '#dc2626', fontWeight: 600 }}>
                  {health.status === 'ok' ? 'Online' : `Offline${health.error ? ` — ${health.error}` : ''}`}
                </td>
              </tr>
              {health.service && <tr><td>Serviço</td><td className="mono">{health.service}</td></tr>}
              {health.time && <tr><td>Hora do serviço</td><td className="mono">{health.time}</td></tr>}
            </tbody>
          </table>
        ) : (
          <p className="muted">Verificando…</p>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Variáveis de configuração</h3>
        <table>
          <thead>
            <tr><th>Variável</th><th>Descrição</th></tr>
          </thead>
          <tbody>
            <tr><td className="mono">FISCAL_SERVICE_URL</td><td>URL interna usada pela API Node para chamar o fiscal-service</td></tr>
            <tr><td className="mono">FISCAL_SERVICE_PORT</td><td>Porta HTTP do fiscal-service (padrão 3334)</td></tr>
            <tr><td className="mono">FISCAL_INTERNAL_KEY</td><td>Chave compartilhada exigida no header X-INTEGRA-INTERNAL-KEY</td></tr>
            <tr><td className="mono">FISCAL_STORAGE_PATH</td><td>Diretório de certificados, XMLs, DANFEs e logs</td></tr>
            <tr><td className="mono">FISCAL_DEFAULT_ENVIRONMENT</td><td>Ambiente padrão: homologation ou production</td></tr>
            <tr><td className="mono">FISCAL_ENCRYPTION_KEY</td><td>Chave AES-256-GCM para decifrar senha de certificado e CSC token</td></tr>
          </tbody>
        </table>
        <p className="muted" style={{ marginTop: 12 }}>
          Por segurança, os valores não são exibidos nem editáveis por esta interface. Ajuste no ambiente de implantação
          e reinicie o serviço.
        </p>
      </div>
    </div>
  );
}
