export interface ProductDto {
  id: string;
  code: string;
  ean: string | null;
  description: string;
  ncm: string | null;
  cest: string | null;
  cfop: string | null;
  unit: string;
  price: number;
  stockQuantity: number;
  icmsCsosn: string | null;
  active: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface CustomerDto {
  id: string;
  name: string;
  document: string | null;
  email: string | null;
  phone: string | null;
  cityName: string | null;
  uf: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface SaleDto {
  id: string;
  localSaleId: string | null;
  status: string;
  total: number;
  discount: number;
  soldAt: string;
  customerName: string | null;
  invoiceStatus: string | null;
  invoiceNumber: string | null;
}

export interface ErpDashboardDto {
  products: number;
  customers: number;
  salesThisMonth: number;
  revenueThisMonth: number;
  lowStockProducts: number;
  pendingInvoices: number;
  companyName: string | null;
  license: {
    plan: string;
    status: string;
    modules: {
      fiscal: boolean;
      pdv: boolean;
      inventory: boolean;
      reports: boolean;
    };
  } | null;
}

export interface ReportsSummaryDto {
  salesByDay: Array<{ date: string; total: number; count: number }>;
  topProducts: Array<{ code: string; description: string; revenue: number }>;
  paymentMix: Array<{ method: string; amount: number }>;
}
