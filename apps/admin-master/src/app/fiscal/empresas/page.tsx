'use client';

import { useEffect, useState } from 'react';
import { formatCnpj } from '@integra/shared';
import { api } from '@/lib/api';

interface CompanyRow {
  companyId: string;
  tenantId: string;
  cnpj: string;
  corporateName: string;
  uf: string;
  environment: string;
  nfeEnabled: boolean;
  nfceEnabled: boolean;
  invoiceCount: number;
}

interface InvoiceRow {
  id: string;
  model: string;
  series: number;
  number: number;
  status: string;
  issueDate: string;
  totalInvoice: string;
}

export default function EmpresasFiscalPage() {
  const [rows, setRows] = useState<CompanyRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<CompanyRow | null>(null);
  const [invoices, setInvoices] = useState<InvoiceRow[]>([]);

  useEffect(() => {
    api<CompanyRow[]>('/admin/fiscal/companies')
      .then(setRows)
      .catch((err) => setError(err.message));
  }, []);

  async function selectCompany(company: CompanyRow) {
    setSelected(company);
    try {
      const result = await api<{ data: InvoiceRow[] }>(`/admin/fiscal/companies/${company.companyId}/invoices`);
      setInvoices(result.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao carregar notas');
    }
  }

  return (
    <div className="page" style={{ maxWidth: 1400 }}>
      <h1>Empresas com fiscal ativo</h1>
      <p className="subtitle">Empresas com NF-e ou NFC-e habilitada e volume de notas emitidas</p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Empresa</th><th>CNPJ</th><th>UF</th><th>Ambiente</th><th>NF-e</th><th>NFC-e</th><th>Notas</th><th></th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={8} className="muted">Nenhuma empresa com fiscal ativo.</td></tr>}
            {rows.map((company) => (
              <tr key={company.companyId}>
                <td>{company.corporateName}</td>
                <td className="mono">{formatCnpj(company.cnpj)}</td>
                <td>{company.uf}</td>
                <td>{company.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</td>
                <td>{company.nfeEnabled ? 'Sim' : '—'}</td>
                <td>{company.nfceEnabled ? 'Sim' : '—'}</td>
                <td><strong>{company.invoiceCount}</strong></td>
                <td>
                  <button className="btn btn-sm btn-secondary" onClick={() => selectCompany(company)}>
                    Ver notas
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {selected && (
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Notas emitidas — {selected.corporateName}</h3>
          <table>
            <thead>
              <tr><th>Modelo</th><th>Série/Número</th><th>Status</th><th>Total</th><th>Emissão</th></tr>
            </thead>
            <tbody>
              {invoices.length === 0 && <tr><td colSpan={5} className="muted">Nenhuma nota emitida.</td></tr>}
              {invoices.map((invoice) => (
                <tr key={invoice.id}>
                  <td>{invoice.model === 'NFE_55' ? 'NF-e' : 'NFC-e'}</td>
                  <td>{invoice.series}/{invoice.number}</td>
                  <td>{invoice.status}</td>
                  <td>R$ {Number(invoice.totalInvoice).toFixed(2)}</td>
                  <td className="muted">{new Date(invoice.issueDate).toLocaleString('pt-BR')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
