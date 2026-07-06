'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { StatCard } from '@integra/ui';
import { formatCurrency } from '@integra/shared';
import { api } from '@/lib/api';
import type { ErpDashboardDto } from '@integra/types';

export default function DashboardPage() {
  const [data, setData] = useState<ErpDashboardDto | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<ErpDashboardDto>('/erp/dashboard')
      .then(setData)
      .catch((err) => setError(err.message));
  }, []);

  if (error) {
    return (
      <div className="page">
        <h1>Dashboard</h1>
        <div className="alert alert-error">{error}</div>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="page">
        <h1>Dashboard</h1>
        <p className="muted">Carregando…</p>
      </div>
    );
  }

  return (
    <div className="page">
      <h1>Dashboard</h1>
      <p className="subtitle">
        Visão geral do ERP — {data.companyName ?? 'sua empresa'}
      </p>

      {data.lowStockProducts > 0 && (
        <div className="alert alert-warning">
          {data.lowStockProducts} produto(s) com estoque baixo.{' '}
          <Link href="/estoque" style={{ textDecoration: 'underline' }}>
            Ver estoque
          </Link>
        </div>
      )}

      <div className="grid grid-4" style={{ marginBottom: 20 }}>
        <StatCard title="Produtos ativos" value={data.products} />
        <StatCard title="Clientes" value={data.customers} accent="#0891b2" />
        <StatCard title="Vendas no mês" value={data.salesThisMonth} accent="#16a34a" />
        <StatCard title="Faturamento no mês" value={formatCurrency(data.revenueThisMonth)} accent="#7c3aed" />
        <StatCard title="Notas pendentes" value={data.pendingInvoices} accent="#d97706" />
        <StatCard title="Estoque baixo" value={data.lowStockProducts} accent="#dc2626" />
      </div>

      <div className="grid grid-2">
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Acesso rápido</h3>
          <div className="actions">
            <Link className="btn" href="/produtos">Produtos</Link>
            <Link className="btn" href="/clientes">Clientes</Link>
            <Link className="btn" href="/vendas">Vendas</Link>
            <Link className="btn" href="/pdv">PDV</Link>
            <Link className="btn btn-secondary" href="/fiscal">Fiscal</Link>
            <Link className="btn btn-secondary" href="/relatorios">Relatórios</Link>
          </div>
        </div>

        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Licença</h3>
          {data.license ? (
            <table>
              <tbody>
                <tr><td>Plano</td><td><strong>{data.license.plan}</strong></td></tr>
                <tr><td>Status</td><td><strong>{data.license.status}</strong></td></tr>
                <tr><td>Fiscal</td><td>{data.license.modules.fiscal ? 'Ativo' : 'Inativo'}</td></tr>
                <tr><td>PDV</td><td>{data.license.modules.pdv ? 'Ativo' : 'Inativo'}</td></tr>
                <tr><td>Estoque</td><td>{data.license.modules.inventory ? 'Ativo' : 'Inativo'}</td></tr>
                <tr><td>Relatórios</td><td>{data.license.modules.reports ? 'Ativo' : 'Inativo'}</td></tr>
              </tbody>
            </table>
          ) : (
            <p className="muted">Sem licença ativa.</p>
          )}
        </div>
      </div>
    </div>
  );
}
