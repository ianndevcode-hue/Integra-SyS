/**
 * Tipos centrais do SaaS: tenant, empresa, usuário, licença, vendas.
 */

export type UserRole = 'MASTER_ADMIN' | 'TENANT_ADMIN' | 'MANAGER' | 'FISCAL' | 'CASHIER' | 'VIEWER';

export type LicenseStatus = 'ACTIVE' | 'TRIAL' | 'SUSPENDED' | 'EXPIRED' | 'CANCELED';

export interface LicenseModules {
  fiscal: boolean;
  pdv: boolean;
  inventory: boolean;
  reports: boolean;
}

export interface AuthUser {
  id: string;
  tenantId: string | null;
  companyId: string | null;
  branchId: string | null;
  name: string;
  email: string;
  role: UserRole;
  permissions: string[];
}

export interface JwtPayload {
  sub: string;
  tenantId: string | null;
  companyId: string | null;
  branchId: string | null;
  role: UserRole;
  permissions: string[];
}

export const PERMISSIONS = {
  FISCAL_EMIT: 'fiscal:emit',
  FISCAL_CANCEL: 'fiscal:cancel',
  FISCAL_INUTILIZE: 'fiscal:inutilize',
  FISCAL_CORRECTION: 'fiscal:correction',
  FISCAL_CONFIG: 'fiscal:config',
  FISCAL_CERTIFICATE: 'fiscal:certificate',
  FISCAL_VIEW: 'fiscal:view',
  PDV_SELL: 'pdv:sell',
  ADMIN_SUPPORT: 'admin:support',
} as const;

export type Permission = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

export type SaleStatus = 'OPEN' | 'FINISHED' | 'CANCELED' | 'PENDING_SYNC';

export interface PaginatedResult<T> {
  data: T[];
  total: number;
  page: number;
  pageSize: number;
}

export interface ApiError {
  statusCode: number;
  message: string;
  error?: string;
}
