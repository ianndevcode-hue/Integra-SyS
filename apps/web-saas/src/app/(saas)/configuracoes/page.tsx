'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';

interface Company {
  corporateName: string;
  tradeName: string | null;
  cnpj: string;
  email: string | null;
  phone: string | null;
  uf: string | null;
  cityName: string | null;
  branches: Array<{ id: string; name: string }>;
}

interface UserRow {
  id: string;
  name: string;
  email: string;
  role: string;
  active: boolean;
}

export default function ConfiguracoesPage() {
  const [company, setCompany] = useState<Company | null>(null);
  const [users, setUsers] = useState<UserRow[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([api<Company>('/erp/company'), api<UserRow[]>('/erp/users')])
      .then(([companyData, usersData]) => {
        setCompany(companyData);
        setUsers(usersData);
      })
      .catch((err) => setError(err.message));
  }, []);

  return (
    <div className="page">
      <h1>Configurações</h1>
      <p className="subtitle">Empresa, filiais e usuários do tenant</p>
      {error && <div className="alert alert-error">{error}</div>}

      <div className="grid grid-2">
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Empresa</h3>
          {company ? (
            <table>
              <tbody>
                <tr><td>Razão social</td><td><strong>{company.corporateName}</strong></td></tr>
                <tr><td>Nome fantasia</td><td>{company.tradeName ?? '—'}</td></tr>
                <tr><td>CNPJ</td><td className="mono">{company.cnpj}</td></tr>
                <tr><td>E-mail</td><td>{company.email ?? '—'}</td></tr>
                <tr><td>Telefone</td><td>{company.phone ?? '—'}</td></tr>
                <tr><td>Cidade/UF</td><td>{[company.cityName, company.uf].filter(Boolean).join('/') || '—'}</td></tr>
              </tbody>
            </table>
          ) : (
            <p className="muted">Carregando…</p>
          )}
        </div>

        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Filiais</h3>
          {company?.branches.length ? (
            <ul style={{ paddingLeft: 18 }}>
              {company.branches.map((branch) => (
                <li key={branch.id}>{branch.name}</li>
              ))}
            </ul>
          ) : (
            <p className="muted">Nenhuma filial cadastrada.</p>
          )}
        </div>
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Usuários</h3>
        <table>
          <thead>
            <tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Status</th></tr>
          </thead>
          <tbody>
            {users.map((user) => (
              <tr key={user.id}>
                <td>{user.name}</td>
                <td>{user.email}</td>
                <td>{user.role}</td>
                <td>{user.active ? 'Ativo' : 'Inativo'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
