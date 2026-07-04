/**
 * Tipos do domínio fiscal (NF-e modelo 55 / NFC-e modelo 65).
 * Compartilhados entre API, fiscal-client, Web SaaS, Admin Master e Mobile/PDV.
 */

export type FiscalEnvironment = 'homologation' | 'production';

/** Modelo do documento fiscal: 55 = NF-e, 65 = NFC-e */
export type FiscalModel = '55' | '65';

export type InvoiceStatus =
  | 'DRAFT' // Digitando
  | 'PENDING' // Pendente
  | 'SENDING' // Enviando
  | 'AUTHORIZED' // Autorizada
  | 'REJECTED' // Rejeitada
  | 'DENIED' // Denegada
  | 'CANCELED' // Cancelada
  | 'INUTILIZED' // Inutilizada
  | 'CONTINGENCY' // Contingência
  | 'ERROR'; // Erro

export const INVOICE_STATUS_LABELS: Record<InvoiceStatus, string> = {
  DRAFT: 'Digitando',
  PENDING: 'Pendente',
  SENDING: 'Enviando',
  AUTHORIZED: 'Autorizada',
  REJECTED: 'Rejeitada',
  DENIED: 'Denegada',
  CANCELED: 'Cancelada',
  INUTILIZED: 'Inutilizada',
  CONTINGENCY: 'Contingência',
  ERROR: 'Erro',
};

export type InvoiceEventType =
  | 'AUTHORIZATION'
  | 'CANCELLATION'
  | 'CORRECTION_LETTER'
  | 'INUTILIZATION'
  | 'DENIAL'
  | 'REJECTION';

export type CertificateStatus = 'ACTIVE' | 'EXPIRED' | 'EXPIRING' | 'INVALID' | 'REMOVED';

export type TaxRegime = 'SIMPLES_NACIONAL' | 'SIMPLES_NACIONAL_EXCESSO' | 'REGIME_NORMAL' | 'MEI';

// ------------------------------------------------------------------
// Payloads de emissão (API -> fiscal-service)
// ------------------------------------------------------------------

export interface FiscalIssuer {
  cnpj: string;
  corporateName: string;
  tradeName?: string;
  stateRegistration: string;
  municipalRegistration?: string;
  crt: number; // 1=Simples, 2=Simples excesso, 3=Regime normal, 4=MEI
  address: FiscalAddress;
}

export interface FiscalAddress {
  street: string;
  number: string;
  complement?: string;
  district: string;
  cityCode: string; // código IBGE
  cityName: string;
  uf: string;
  zipCode: string;
  countryCode?: string; // 1058
  countryName?: string; // BRASIL
  phone?: string;
}

export interface FiscalRecipient {
  document?: string; // CPF ou CNPJ (opcional na NFC-e)
  name?: string;
  email?: string;
  stateRegistrationIndicator?: number; // 1, 2, 9
  stateRegistration?: string;
  address?: FiscalAddress;
}

export interface FiscalInvoiceItemPayload {
  itemNumber: number;
  productCode: string;
  ean?: string;
  description: string;
  ncm: string;
  cest?: string;
  cfop: string;
  unit: string;
  quantity: number;
  unitPrice: number;
  totalPrice: number;
  discount?: number;
  origin?: number; // 0-8
  icmsCst?: string;
  icmsCsosn?: string;
  icmsRate?: number;
  icmsBase?: number;
  icmsValue?: number;
  pisCst?: string;
  pisRate?: number;
  pisValue?: number;
  cofinsCst?: string;
  cofinsRate?: number;
  cofinsValue?: number;
}

/** Códigos da tabela tPag da SEFAZ */
export type SefazPaymentCode =
  | '01' // Dinheiro
  | '02' // Cheque
  | '03' // Cartão de crédito
  | '04' // Cartão de débito
  | '05' // Crédito loja
  | '15' // Boleto
  | '17' // PIX
  | '90' // Sem pagamento
  | '99'; // Outros

export interface FiscalInvoicePaymentPayload {
  method: SefazPaymentCode;
  amount: number;
  cardBrand?: string;
  authorizationCode?: string;
  integrationType?: number; // 1=integrado, 2=não integrado
}

export interface FiscalCompanyContext {
  tenantId: string;
  companyId: string;
  branchId?: string | null;
  environment: FiscalEnvironment;
  uf: string;
  /** Certificado A1: caminho no storage do fiscal-service + senha cifrada */
  certificate: {
    path: string;
    /** senha cifrada com AES-256-GCM (FISCAL_ENCRYPTION_KEY) */
    encryptedPassword: string;
  };
  /** Necessário apenas para NFC-e (modelo 65) */
  csc?: {
    id: string;
    encryptedToken: string;
  };
  issuer: FiscalIssuer;
}

export interface EmitInvoicePayload {
  /** id da invoice no banco da API principal (usado como referência/idempotência) */
  invoiceId: string;
  model: FiscalModel;
  series: number;
  number: number;
  operationNature: string;
  issueDate: string; // ISO-8601
  /** 1=operação interna, 2=interestadual, 3=exterior */
  destinationIndicator?: number;
  /** 0=não presencial, 1=presencial, 4=entrega domicílio, 9=não presencial outros */
  presenceIndicator?: number;
  /** 0=entrada, 1=saída */
  operationType?: number;
  /** 1=normal, 9=contingência off-line (NFC-e) */
  emissionType?: number;
  /** 0=sem intermediador, 1=marketplace */
  intermediaryIndicator?: number;
  company: FiscalCompanyContext;
  recipient?: FiscalRecipient;
  items: FiscalInvoiceItemPayload[];
  payments: FiscalInvoicePaymentPayload[];
  freight?: {
    modality: number; // 9 = sem frete
    value?: number;
  };
  additionalInformation?: string;
  totals?: {
    products: number;
    discount: number;
    invoice: number;
  };
}

export interface CancelInvoicePayload {
  company: FiscalCompanyContext;
  accessKey: string;
  protocol: string;
  justification: string; // min 15 chars
}

export interface InutilizePayload {
  company: FiscalCompanyContext;
  model: FiscalModel;
  series: number;
  startNumber: number;
  endNumber: number;
  justification: string; // min 15 chars
}

export interface CorrectionLetterPayload {
  company: FiscalCompanyContext;
  accessKey: string;
  correctionText: string; // min 15 chars
  sequence: number;
}

export interface QueryInvoicePayload {
  company: FiscalCompanyContext;
  accessKey: string;
}

// ------------------------------------------------------------------
// Respostas do fiscal-service
// ------------------------------------------------------------------

export interface FiscalServiceError {
  code: string;
  message: string;
  details?: unknown;
}

export interface EmitInvoiceResult {
  success: boolean;
  invoiceId: string;
  status: InvoiceStatus;
  accessKey?: string;
  protocol?: string;
  receiptNumber?: string;
  authorizedAt?: string;
  /** cStat retornado pela SEFAZ */
  sefazCode?: string;
  /** xMotivo retornado pela SEFAZ */
  sefazMessage?: string;
  rejectionCode?: string;
  rejectionMessage?: string;
  xmlPath?: string;
  /** XML autorizado (protNFe + NFe) em base64 */
  xmlBase64?: string;
  error?: FiscalServiceError;
}

export interface CancelInvoiceResult {
  success: boolean;
  status: InvoiceStatus;
  protocol?: string;
  sefazCode?: string;
  sefazMessage?: string;
  xmlPath?: string;
  xmlBase64?: string;
  error?: FiscalServiceError;
}

export interface InutilizeResult {
  success: boolean;
  protocol?: string;
  sefazCode?: string;
  sefazMessage?: string;
  xmlPath?: string;
  xmlBase64?: string;
  error?: FiscalServiceError;
}

export interface CorrectionLetterResult {
  success: boolean;
  protocol?: string;
  sequence: number;
  sefazCode?: string;
  sefazMessage?: string;
  xmlPath?: string;
  xmlBase64?: string;
  error?: FiscalServiceError;
}

export interface SefazStatusResult {
  success: boolean;
  uf: string;
  environment: FiscalEnvironment;
  online: boolean;
  sefazCode?: string;
  sefazMessage?: string;
  averageResponseTimeMs?: number;
  checkedAt: string;
  error?: FiscalServiceError;
}

export interface QueryInvoiceResult {
  success: boolean;
  accessKey: string;
  status?: InvoiceStatus;
  protocol?: string;
  sefazCode?: string;
  sefazMessage?: string;
  events?: Array<{
    type: string;
    sequence: number;
    protocol: string;
    registeredAt: string;
  }>;
  error?: FiscalServiceError;
}

export interface QueryReceiptResult {
  success: boolean;
  receiptNumber: string;
  processed: boolean;
  sefazCode?: string;
  sefazMessage?: string;
  protocol?: string;
  accessKey?: string;
  error?: FiscalServiceError;
}

export interface XmlDownloadResult {
  success: boolean;
  fileName?: string;
  xmlBase64?: string;
  error?: FiscalServiceError;
}

export interface PdfResult {
  success: boolean;
  fileName?: string;
  pdfBase64?: string;
  error?: FiscalServiceError;
}

export interface CertificateInfoResult {
  success: boolean;
  subjectCnpj?: string;
  serialNumber?: string;
  validFrom?: string;
  validUntil?: string;
  daysToExpire?: number;
  status?: CertificateStatus;
  error?: FiscalServiceError;
}

// ------------------------------------------------------------------
// DTOs consumidos pelos frontends (Web SaaS / Admin / PDV)
// ------------------------------------------------------------------

export interface InvoiceListItemDto {
  id: string;
  model: FiscalModel;
  series: number;
  number: number;
  status: InvoiceStatus;
  environment: FiscalEnvironment;
  customerName?: string;
  customerDocument?: string;
  accessKey?: string;
  protocol?: string;
  totalInvoice: number;
  issueDate: string;
  authorizedAt?: string;
  canceledAt?: string;
  rejectionCode?: string;
  rejectionMessage?: string;
}

export interface FiscalDashboardDto {
  nfeIssuedThisMonth: number;
  nfceIssuedThisMonth: number;
  authorized: number;
  rejected: number;
  canceled: number;
  pending: number;
  lastIssueAt?: string;
  sefazStatus: Array<{ uf: string; online: boolean; checkedAt: string }>;
  certificate?: {
    validUntil: string;
    daysToExpire: number;
    status: CertificateStatus;
  };
  nfeSeries?: number;
  nfceSeries?: number;
  nextNfeNumber?: number;
  nextNfceNumber?: number;
}

export interface FiscalConfigDto {
  id: string;
  uf: string;
  cityCode: string;
  cityName: string;
  stateRegistration?: string;
  municipalRegistration?: string;
  taxRegime: TaxRegime;
  crt: number;
  environment: FiscalEnvironment;
  defaultNfeSeries: number;
  defaultNfceSeries: number;
  nfeEnabled: boolean;
  nfceEnabled: boolean;
  nfseEnabled: boolean;
  technician?: {
    name?: string;
    email?: string;
    cnpj?: string;
    phone?: string;
  };
}

export interface PdvNfceStatusDto {
  invoiceId: string;
  saleId?: string;
  status: InvoiceStatus;
  accessKey?: string;
  protocol?: string;
  rejectionCode?: string;
  rejectionMessage?: string;
  authorizedAt?: string;
}

/** Status fiscal simplificado exibido no PDV */
export type PdvFiscalStatus =
  | 'NO_INVOICE' // Sem nota
  | 'PENDING_EMISSION' // Pendente de emissão
  | 'EMITTING' // Emitindo
  | 'AUTHORIZED' // Autorizada
  | 'REJECTED' // Rejeitada
  | 'CANCELED'; // Cancelada

export const PDV_FISCAL_STATUS_LABELS: Record<PdvFiscalStatus, string> = {
  NO_INVOICE: 'Sem nota',
  PENDING_EMISSION: 'Pendente de emissão',
  EMITTING: 'Emitindo',
  AUTHORIZED: 'Autorizada',
  REJECTED: 'Rejeitada',
  CANCELED: 'Cancelada',
};
