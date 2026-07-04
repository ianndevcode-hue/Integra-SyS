export interface CartItem {
  productId: string;
  code: string;
  description: string;
  ncm: string;
  cfop: string;
  unit: string;
  quantity: number;
  unitPrice: number;
  totalPrice: number;
  discount: number;
  icmsCsosn?: string;
  icmsCst?: string;
}

export interface SalePayment {
  method: string;
  amount: number;
}

export interface PendingSale {
  localSaleId: string;
  soldAt: string;
  customerDocument?: string;
  customerName?: string;
  items: CartItem[];
  payments: SalePayment[];
  discount: number;
}

export type PdvFiscalStatusLocal =
  | 'NO_INVOICE'
  | 'PENDING_EMISSION'
  | 'EMITTING'
  | 'AUTHORIZED'
  | 'REJECTED'
  | 'CANCELED';

export interface EmissionOutcome {
  invoiceId?: string;
  saleNumber?: string;
  status: PdvFiscalStatusLocal;
  accessKey?: string;
  protocol?: string;
  rejectionCode?: string;
  rejectionMessage?: string;
}
