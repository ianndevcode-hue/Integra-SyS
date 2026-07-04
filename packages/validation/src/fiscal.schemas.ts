import { z } from 'zod';
import { isValidCpfOrCnpj } from '@integra/shared';
import { UF_LIST, MIN_JUSTIFICATION_LENGTH, MIN_CORRECTION_LENGTH } from '@integra/shared';

export const ufSchema = z
  .string()
  .length(2)
  .transform((v) => v.toUpperCase())
  .refine((v) => UF_LIST.includes(v), 'UF inválida');

export const documentSchema = z
  .string()
  .refine((v) => isValidCpfOrCnpj(v), 'CPF/CNPJ inválido');

export const invoiceItemSchema = z.object({
  productId: z.string().min(1, 'Produto obrigatório'),
  code: z.string().min(1, 'Código do produto obrigatório'),
  description: z.string().min(1, 'Descrição obrigatória'),
  ncm: z.string().regex(/^\d{8}$/, 'Produto precisa ter NCM (8 dígitos)'),
  cest: z.string().regex(/^\d{7}$/).optional(),
  cfop: z.string().regex(/^\d{4}$/, 'Produto precisa ter CFOP (4 dígitos)'),
  unit: z.string().min(1, 'Produto precisa ter unidade'),
  quantity: z.number().positive('Quantidade deve ser maior que zero'),
  unitPrice: z.number().nonnegative(),
  totalPrice: z.number().nonnegative(),
  discount: z.number().nonnegative().default(0),
  icmsCst: z.string().regex(/^\d{2,3}$/).optional(),
  icmsCsosn: z.string().regex(/^\d{3,4}$/).optional(),
  icmsRate: z.number().min(0).max(100).optional(),
  pisCst: z.string().optional(),
  pisRate: z.number().min(0).max(100).optional(),
  cofinsCst: z.string().optional(),
  cofinsRate: z.number().min(0).max(100).optional(),
}).refine((item) => item.icmsCst || item.icmsCsosn, {
  message: 'Produto precisa ter CST ou CSOSN',
  path: ['icmsCst'],
});

export const invoicePaymentSchema = z.object({
  method: z.enum(['01', '02', '03', '04', '05', '15', '17', '90', '99']),
  amount: z.number().positive('Valor do pagamento deve ser maior que zero'),
  cardBrand: z.string().optional(),
  authorizationCode: z.string().optional(),
});

export const emitNfeSchema = z.object({
  customerId: z.string().min(1, 'Cliente obrigatório para NF-e'),
  operationNature: z.string().min(1, 'Natureza da operação obrigatória'),
  series: z.number().int().positive().optional(),
  items: z.array(invoiceItemSchema).min(1, 'Nota precisa ter ao menos um item'),
  payments: z.array(invoicePaymentSchema).min(1, 'Venda precisa ter forma de pagamento'),
  additionalInformation: z.string().max(5000).optional(),
  saleId: z.string().optional(),
});

export const emitNfceSchema = z.object({
  customerId: z.string().optional(),
  customerDocument: documentSchema.optional(),
  customerName: z.string().optional(),
  series: z.number().int().positive().optional(),
  items: z.array(invoiceItemSchema).min(1, 'Nota precisa ter ao menos um item'),
  payments: z.array(invoicePaymentSchema).min(1, 'Venda precisa ter forma de pagamento'),
  saleId: z.string().optional(),
});

export const cancelInvoiceSchema = z.object({
  justification: z
    .string()
    .min(MIN_JUSTIFICATION_LENGTH, `Cancelamento exige justificativa com no mínimo ${MIN_JUSTIFICATION_LENGTH} caracteres`)
    .max(255),
});

export const inutilizeSchema = z.object({
  model: z.enum(['55', '65']),
  series: z.number().int().nonnegative(),
  startNumber: z.number().int().positive(),
  endNumber: z.number().int().positive(),
  justification: z
    .string()
    .min(MIN_JUSTIFICATION_LENGTH, `Inutilização exige justificativa com no mínimo ${MIN_JUSTIFICATION_LENGTH} caracteres`)
    .max(255),
}).refine((v) => v.endNumber >= v.startNumber, {
  message: 'Número final deve ser maior ou igual ao inicial',
  path: ['endNumber'],
});

export const correctionLetterSchema = z.object({
  correctionText: z
    .string()
    .min(MIN_CORRECTION_LENGTH, `Carta de correção exige texto com no mínimo ${MIN_CORRECTION_LENGTH} caracteres`)
    .max(1000),
});

export const fiscalConfigUpdateSchema = z.object({
  uf: ufSchema.optional(),
  cityCode: z.string().regex(/^\d{7}$/, 'Código IBGE deve ter 7 dígitos').optional(),
  cityName: z.string().min(1).optional(),
  stateRegistration: z.string().optional(),
  municipalRegistration: z.string().optional(),
  taxRegime: z.enum(['SIMPLES_NACIONAL', 'SIMPLES_NACIONAL_EXCESSO', 'REGIME_NORMAL', 'MEI']).optional(),
  crt: z.number().int().min(1).max(4).optional(),
  environment: z.enum(['homologation', 'production']).optional(),
  defaultNfeSeries: z.number().int().positive().optional(),
  defaultNfceSeries: z.number().int().positive().optional(),
  nextNfeNumber: z.number().int().positive().optional(),
  nextNfceNumber: z.number().int().positive().optional(),
  nfeEnabled: z.boolean().optional(),
  nfceEnabled: z.boolean().optional(),
  cscId: z.string().optional(),
  cscToken: z.string().optional(),
  technicianName: z.string().optional(),
  technicianEmail: z.string().email().optional(),
  technicianCnpj: z.string().optional(),
  technicianPhone: z.string().optional(),
});

export const certificateUploadSchema = z.object({
  fileBase64: z.string().min(1, 'Arquivo do certificado (.pfx/.p12) obrigatório'),
  fileName: z.string().regex(/\.(pfx|p12)$/i, 'Certificado deve ser .pfx ou .p12'),
  password: z.string().min(1, 'Senha do certificado obrigatória'),
});

export type EmitNfeInput = z.infer<typeof emitNfeSchema>;
export type EmitNfceInput = z.infer<typeof emitNfceSchema>;
export type CancelInvoiceInput = z.infer<typeof cancelInvoiceSchema>;
export type InutilizeInput = z.infer<typeof inutilizeSchema>;
export type CorrectionLetterInput = z.infer<typeof correctionLetterSchema>;
export type FiscalConfigUpdateInput = z.infer<typeof fiscalConfigUpdateSchema>;
export type CertificateUploadInput = z.infer<typeof certificateUploadSchema>;
