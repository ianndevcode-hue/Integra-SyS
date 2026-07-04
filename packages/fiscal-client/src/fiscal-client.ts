import type {
  CancelInvoicePayload,
  CancelInvoiceResult,
  CertificateInfoResult,
  CorrectionLetterPayload,
  CorrectionLetterResult,
  EmitInvoicePayload,
  EmitInvoiceResult,
  FiscalCompanyContext,
  InutilizePayload,
  InutilizeResult,
  PdfResult,
  QueryInvoiceResult,
  QueryReceiptResult,
  SefazStatusResult,
  XmlDownloadResult,
} from '@integra/types';
import { FiscalServiceHttpError, FiscalServiceUnavailableError } from './errors';

export interface FiscalClientOptions {
  /** URL base do fiscal-service, ex: http://fiscal-service:3334 */
  baseUrl: string;
  /** Chave interna enviada no header X-INTEGRA-INTERNAL-KEY */
  internalKey: string;
  /** Timeout em ms (padrão 60s — SEFAZ pode ser lenta) */
  timeoutMs?: number;
}

/**
 * Client TypeScript para o fiscal-service (PHP + NFePHP/sped-nfe).
 *
 * A API principal NÃO monta XML fiscal: ela valida dados, persiste
 * registros e delega toda a parte técnica (XML, assinatura, envio,
 * eventos e DANFE/DANFCE) a este client.
 */
export class FiscalClient {
  private readonly baseUrl: string;
  private readonly internalKey: string;
  private readonly timeoutMs: number;

  constructor(options: FiscalClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/$/, '');
    this.internalKey = options.internalKey;
    this.timeoutMs = options.timeoutMs ?? 60_000;
  }

  // ----------------------------------------------------------
  // Emissão
  // ----------------------------------------------------------

  /** Emite NF-e (modelo 55): monta, assina, valida e envia via SEFAZ. */
  async emitirNFe(payload: EmitInvoicePayload): Promise<EmitInvoiceResult> {
    return this.post<EmitInvoiceResult>('/internal/fiscal/nfe/emit', payload);
  }

  /** Emite NFC-e (modelo 65) com CSC/QRCode. */
  async emitirNFCe(payload: EmitInvoicePayload): Promise<EmitInvoiceResult> {
    return this.post<EmitInvoiceResult>('/internal/fiscal/nfce/emit', payload);
  }

  // ----------------------------------------------------------
  // Eventos
  // ----------------------------------------------------------

  /** Cancela NF-e/NFC-e autorizada (evento 110111). */
  async cancelarNota(invoiceId: string, payload: CancelInvoicePayload): Promise<CancelInvoiceResult> {
    return this.post<CancelInvoiceResult>(`/internal/fiscal/invoices/${encodeURIComponent(invoiceId)}/cancel`, payload);
  }

  /** Inutiliza faixa de numeração por modelo/série. */
  async inutilizarNumeracao(payload: InutilizePayload): Promise<InutilizeResult> {
    return this.post<InutilizeResult>('/internal/fiscal/invoices/inutilize', payload);
  }

  /** Registra Carta de Correção Eletrônica (evento 110110, apenas NF-e). */
  async gerarCartaCorrecao(invoiceId: string, payload: CorrectionLetterPayload): Promise<CorrectionLetterResult> {
    return this.post<CorrectionLetterResult>(
      `/internal/fiscal/invoices/${encodeURIComponent(invoiceId)}/correction-letter`,
      payload,
    );
  }

  // ----------------------------------------------------------
  // Consultas
  // ----------------------------------------------------------

  /** Consulta status do serviço SEFAZ para uma UF. */
  async consultarStatusServico(uf: string, company: FiscalCompanyContext): Promise<SefazStatusResult> {
    return this.post<SefazStatusResult>(`/internal/fiscal/status/${encodeURIComponent(uf)}`, { company });
  }

  /** Consulta situação da nota pela chave de acesso (protocolo/eventos). */
  async consultarNota(invoiceId: string, company: FiscalCompanyContext, accessKey: string): Promise<QueryInvoiceResult> {
    return this.post<QueryInvoiceResult>(`/internal/fiscal/invoices/${encodeURIComponent(invoiceId)}/query`, {
      company,
      accessKey,
    });
  }

  /** Consulta processamento de recibo de lote. */
  async consultarRecibo(receiptId: string, company: FiscalCompanyContext): Promise<QueryReceiptResult> {
    return this.post<QueryReceiptResult>(`/internal/fiscal/receipts/${encodeURIComponent(receiptId)}/query`, { company });
  }

  // ----------------------------------------------------------
  // Arquivos (XML / DANFE / DANFCE)
  // ----------------------------------------------------------

  /** Baixa XML autorizado/cancelado da nota. */
  async baixarXml(invoiceId: string, accessKey: string): Promise<XmlDownloadResult> {
    return this.get<XmlDownloadResult>(
      `/internal/fiscal/invoices/${encodeURIComponent(invoiceId)}/xml?accessKey=${encodeURIComponent(accessKey)}`,
    );
  }

  /** Gera/recupera DANFE (PDF, modelo 55) em base64. */
  async obterDanfe(invoiceId: string, accessKey: string): Promise<PdfResult> {
    return this.get<PdfResult>(
      `/internal/fiscal/invoices/${encodeURIComponent(invoiceId)}/danfe?accessKey=${encodeURIComponent(accessKey)}`,
    );
  }

  /** Gera/recupera DANFCE (PDF cupom, modelo 65) em base64. */
  async obterDanfce(invoiceId: string, accessKey: string): Promise<PdfResult> {
    return this.get<PdfResult>(
      `/internal/fiscal/invoices/${encodeURIComponent(invoiceId)}/danfce?accessKey=${encodeURIComponent(accessKey)}`,
    );
  }

  // ----------------------------------------------------------
  // Certificado
  // ----------------------------------------------------------

  /** Envia certificado A1 (.pfx base64) para o storage do fiscal-service e valida. */
  async enviarCertificado(payload: {
    tenantId: string;
    companyId: string;
    branchId?: string | null;
    fileBase64: string;
    encryptedPassword: string;
  }): Promise<CertificateInfoResult & { certificatePath?: string }> {
    return this.post('/internal/fiscal/certificates/upload', payload);
  }

  /** Testa certificado A1 já armazenado. */
  async testarCertificado(payload: { certificatePath: string; encryptedPassword: string }): Promise<CertificateInfoResult> {
    return this.post<CertificateInfoResult>('/internal/fiscal/certificates/test', payload);
  }

  /** Remove certificado do storage. */
  async removerCertificado(payload: { certificatePath: string }): Promise<{ success: boolean }> {
    return this.post<{ success: boolean }>('/internal/fiscal/certificates/remove', payload);
  }

  /** Healthcheck do fiscal-service. */
  async health(): Promise<{ status: string; service: string; time: string }> {
    return this.get('/internal/fiscal/health');
  }

  // ----------------------------------------------------------
  // HTTP
  // ----------------------------------------------------------

  private async post<T>(path: string, body: unknown): Promise<T> {
    return this.request<T>('POST', path, body);
  }

  private async get<T>(path: string): Promise<T> {
    return this.request<T>('GET', path);
  }

  private async request<T>(method: string, path: string, body?: unknown): Promise<T> {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.timeoutMs);

    let response: Response;
    try {
      response = await fetch(`${this.baseUrl}${path}`, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'X-INTEGRA-INTERNAL-KEY': this.internalKey,
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });
    } catch (error) {
      throw new FiscalServiceUnavailableError(error);
    } finally {
      clearTimeout(timeout);
    }

    const text = await response.text();
    let parsed: unknown;
    try {
      parsed = text ? JSON.parse(text) : {};
    } catch {
      parsed = { raw: text };
    }

    if (!response.ok) {
      throw new FiscalServiceHttpError(response.status, parsed);
    }

    return parsed as T;
  }
}
