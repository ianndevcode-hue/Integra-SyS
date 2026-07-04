import type { FiscalModel, InvoiceStatus, PdvFiscalStatus } from '@integra/types';

/** Códigos das UFs conforme tabela do IBGE (usados na chave de acesso). */
export const UF_CODES: Record<string, string> = {
  RO: '11', AC: '12', AM: '13', RR: '14', PA: '15', AP: '16', TO: '17',
  MA: '21', PI: '22', CE: '23', RN: '24', PB: '25', PE: '26', AL: '27', SE: '28', BA: '29',
  MG: '31', ES: '32', RJ: '33', SP: '35',
  PR: '41', SC: '42', RS: '43',
  MS: '50', MT: '51', GO: '52', DF: '53',
};

export const UF_LIST = Object.keys(UF_CODES);

export function isValidAccessKey(accessKey: string): boolean {
  const digits = accessKey.replace(/\D/g, '');
  if (digits.length !== 44) return false;

  // dígito verificador (módulo 11)
  const weights = [2, 3, 4, 5, 6, 7, 8, 9];
  let sum = 0;
  for (let i = 42; i >= 0; i--) {
    sum += parseInt(digits[i], 10) * weights[(42 - i) % 8];
  }
  const rest = sum % 11;
  const dv = rest < 2 ? 0 : 11 - rest;
  return dv === parseInt(digits[43], 10);
}

export function modelLabel(model: FiscalModel): string {
  return model === '55' ? 'NF-e' : 'NFC-e';
}

/** Mapeia status detalhado da invoice para o status simplificado do PDV. */
export function toPdvFiscalStatus(status: InvoiceStatus | null | undefined): PdvFiscalStatus {
  switch (status) {
    case 'AUTHORIZED':
      return 'AUTHORIZED';
    case 'REJECTED':
    case 'DENIED':
    case 'ERROR':
      return 'REJECTED';
    case 'CANCELED':
    case 'INUTILIZED':
      return 'CANCELED';
    case 'SENDING':
      return 'EMITTING';
    case 'PENDING':
    case 'DRAFT':
    case 'CONTINGENCY':
      return 'PENDING_EMISSION';
    default:
      return 'NO_INVOICE';
  }
}

export const MIN_JUSTIFICATION_LENGTH = 15;
export const MIN_CORRECTION_LENGTH = 15;
export const MAX_JUSTIFICATION_LENGTH = 255;
export const MAX_CORRECTION_LENGTH = 1000;
