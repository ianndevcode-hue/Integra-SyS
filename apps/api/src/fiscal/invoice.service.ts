import {
  Inject,
  Injectable,
  NotFoundException,
  UnprocessableEntityException,
} from '@nestjs/common';
import type { FiscalClient } from '@integra/fiscal-client';
import type {
  EmitInvoicePayload,
  EmitInvoiceResult,
  FiscalModel,
  JwtPayload,
} from '@integra/types';
import type {
  CancelInvoiceInput,
  CorrectionLetterInput,
  EmitNfceInput,
  EmitNfeInput,
  InutilizeInput,
} from '@integra/validation';
import { createHash } from 'crypto';
import { FISCAL_CLIENT } from '../core/fiscal-client.provider';
import { PrismaService } from '../core/prisma.service';
import { AuditService } from '../core/audit.service';
import { LicenseService } from '../auth/license.service';
import { FiscalContextService } from './fiscal-context.service';
import { FiscalSequenceService } from './fiscal-sequence.service';
import { FiscalValidationService } from './fiscal-validation.service';

interface UserScope {
  tenantId: string;
  companyId: string;
  userId: string;
}

@Injectable()
export class InvoiceService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
    private readonly license: LicenseService,
    private readonly contextService: FiscalContextService,
    private readonly sequenceService: FiscalSequenceService,
    private readonly validation: FiscalValidationService,
    @Inject(FISCAL_CLIENT) private readonly fiscalClient: FiscalClient,
  ) {}

  private scope(user: JwtPayload): UserScope {
    if (!user.tenantId || !user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa/tenant associado');
    }
    return { tenantId: user.tenantId, companyId: user.companyId, userId: user.sub };
  }

  // ------------------------------------------------------------------
  // Emissão NF-e (55)
  // ------------------------------------------------------------------

  async emitNfe(user: JwtPayload, input: EmitNfeInput) {
    const { tenantId, companyId, userId } = this.scope(user);

    await this.license.assertFiscalLicense(tenantId);

    const config = await this.requireFiscalConfig(companyId, tenantId);
    if (!config.nfeEnabled) {
      throw new UnprocessableEntityException('Emissão de NF-e não habilitada na configuração fiscal');
    }

    const customer = await this.prisma.customer.findFirst({ where: { id: input.customerId, companyId } });
    if (!customer) {
      throw new NotFoundException('Cliente não encontrado');
    }
    this.validation.validateNfeRecipient(customer.document);
    this.validation.validateItems(input.items);
    const totals = this.validation.computeTotals(input);
    this.validation.validatePayments(input.payments, totals.invoice);

    const context = await this.contextService.build(tenantId, companyId);
    const series = input.series ?? config.defaultNfeSeries;
    const number = await this.sequenceService.allocateNumber({
      tenantId,
      companyId,
      model: '55',
      series,
      environment: config.environment,
    });

    const invoice = await this.createInvoiceRecord({
      tenantId,
      companyId,
      model: 'NFE_55',
      series,
      number,
      environment: config.environment,
      operationNature: input.operationNature,
      customerId: customer.id,
      saleId: input.saleId,
      totals,
      items: input.items,
      payments: input.payments,
    });

    await this.audit.log({
      tenantId,
      companyId,
      userId,
      action: 'fiscal.nfe.emit',
      entity: 'Invoice',
      entityId: invoice.id,
      metadata: { series, number, environment: config.environment },
    });

    const payload: EmitInvoicePayload = {
      invoiceId: invoice.id,
      model: '55',
      series,
      number,
      operationNature: input.operationNature,
      issueDate: new Date().toISOString(),
      company: context,
      recipient: {
        document: customer.document ?? undefined,
        name: customer.name,
        email: customer.email ?? undefined,
        stateRegistrationIndicator: customer.stateRegistrationIndicator,
        stateRegistration: customer.stateRegistration ?? undefined,
        address: customer.street
          ? {
              street: customer.street,
              number: customer.number ?? 'S/N',
              complement: customer.complement ?? undefined,
              district: customer.district ?? '',
              cityCode: customer.cityCode ?? '',
              cityName: customer.cityName ?? '',
              uf: customer.uf ?? '',
              zipCode: customer.zipCode ?? '',
            }
          : undefined,
      },
      items: this.mapItems(input.items),
      payments: input.payments.map((p) => ({
        method: p.method,
        amount: p.amount,
        cardBrand: p.cardBrand,
        authorizationCode: p.authorizationCode,
      })),
      additionalInformation: input.additionalInformation,
      presenceIndicator: 9,
      totals,
    };

    return this.dispatchEmission(invoice.id, tenantId, companyId, payload, (p) => this.fiscalClient.emitirNFe(p));
  }

  // ------------------------------------------------------------------
  // Emissão NFC-e (65)
  // ------------------------------------------------------------------

  async emitNfce(user: JwtPayload, input: EmitNfceInput, options?: { saleId?: string }) {
    const { tenantId, companyId, userId } = this.scope(user);

    await this.license.assertFiscalLicense(tenantId);

    const config = await this.requireFiscalConfig(companyId, tenantId);
    if (!config.nfceEnabled) {
      throw new UnprocessableEntityException('Emissão de NFC-e não habilitada na configuração fiscal');
    }

    this.validation.validateItems(input.items);
    const totals = this.validation.computeTotals(input);
    this.validation.validatePayments(input.payments, totals.invoice);
    this.validation.validateNfceRecipientDocument(input.customerDocument, totals.invoice);

    const context = await this.contextService.build(tenantId, companyId, { requireCsc: true });
    const series = input.series ?? config.defaultNfceSeries;
    const number = await this.sequenceService.allocateNumber({
      tenantId,
      companyId,
      model: '65',
      series,
      environment: config.environment,
    });

    const invoice = await this.createInvoiceRecord({
      tenantId,
      companyId,
      model: 'NFCE_65',
      series,
      number,
      environment: config.environment,
      operationNature: 'VENDA AO CONSUMIDOR',
      customerId: input.customerId,
      saleId: options?.saleId ?? input.saleId,
      totals,
      items: input.items,
      payments: input.payments,
    });

    await this.audit.log({
      tenantId,
      companyId,
      userId,
      action: 'fiscal.nfce.emit',
      entity: 'Invoice',
      entityId: invoice.id,
      metadata: { series, number, environment: config.environment, saleId: options?.saleId ?? input.saleId },
    });

    const payload: EmitInvoicePayload = {
      invoiceId: invoice.id,
      model: '65',
      series,
      number,
      operationNature: 'VENDA AO CONSUMIDOR',
      issueDate: new Date().toISOString(),
      company: context,
      recipient: input.customerDocument
        ? { document: input.customerDocument, name: input.customerName ?? 'CONSUMIDOR' }
        : undefined,
      items: this.mapItems(input.items),
      payments: input.payments.map((p) => ({
        method: p.method,
        amount: p.amount,
        cardBrand: p.cardBrand,
        authorizationCode: p.authorizationCode,
      })),
      presenceIndicator: 1,
      totals,
    };

    return this.dispatchEmission(invoice.id, tenantId, companyId, payload, (p) => this.fiscalClient.emitirNFCe(p));
  }

  // ------------------------------------------------------------------
  // Cancelamento
  // ------------------------------------------------------------------

  async cancel(user: JwtPayload, invoiceId: string, input: CancelInvoiceInput) {
    const { tenantId, companyId, userId } = this.scope(user);
    await this.license.assertFiscalLicense(tenantId);

    const invoice = await this.requireInvoice(invoiceId, companyId);
    if (invoice.status !== 'AUTHORIZED') {
      throw new UnprocessableEntityException('Apenas notas autorizadas podem ser canceladas');
    }
    if (!invoice.accessKey || !invoice.protocol) {
      throw new UnprocessableEntityException('Nota sem chave de acesso/protocolo');
    }

    const context = await this.contextService.build(tenantId, companyId);
    const result = await this.fiscalClient.cancelarNota(invoice.id, {
      company: context,
      accessKey: invoice.accessKey,
      protocol: invoice.protocol,
      justification: input.justification,
    });

    if (result.success) {
      await this.prisma.$transaction([
        this.prisma.invoice.update({
          where: { id: invoice.id },
          data: { status: 'CANCELED', canceledAt: new Date() },
        }),
        this.prisma.invoiceEvent.create({
          data: {
            invoiceId: invoice.id,
            eventType: 'CANCELLATION',
            protocol: result.protocol,
            justification: input.justification,
            status: 'ACCEPTED',
            xmlPath: result.xmlPath,
          },
        }),
        ...(result.xmlPath
          ? [
              this.prisma.invoiceXml.create({
                data: {
                  invoiceId: invoice.id,
                  type: 'CANCELLATION',
                  path: result.xmlPath,
                  sha256: result.xmlBase64 ? sha256OfBase64(result.xmlBase64) : undefined,
                },
              }),
            ]
          : []),
      ]);
    } else {
      await this.prisma.invoiceEvent.create({
        data: {
          invoiceId: invoice.id,
          eventType: 'CANCELLATION',
          justification: input.justification,
          status: 'REJECTED',
        },
      });
      await this.registerRejection(tenantId, companyId, invoice.id, result.sefazCode, result.sefazMessage, 'CANCELLATION');
    }

    await this.audit.log({
      tenantId,
      companyId,
      userId,
      action: 'fiscal.invoice.cancel',
      entity: 'Invoice',
      entityId: invoice.id,
      metadata: { success: result.success, sefazCode: result.sefazCode },
    });

    return result;
  }

  // ------------------------------------------------------------------
  // Inutilização
  // ------------------------------------------------------------------

  async inutilize(user: JwtPayload, input: InutilizeInput) {
    const { tenantId, companyId, userId } = this.scope(user);
    await this.license.assertFiscalLicense(tenantId);

    const context = await this.contextService.build(tenantId, companyId);
    const result = await this.fiscalClient.inutilizarNumeracao({
      company: context,
      model: input.model,
      series: input.series,
      startNumber: input.startNumber,
      endNumber: input.endNumber,
      justification: input.justification,
    });

    if (result.success) {
      // Registra a faixa inutilizada como invoices INUTILIZED para bloquear reuso
      const dbModel = input.model === '55' ? 'NFE_55' : 'NFCE_65';
      const config = await this.requireFiscalConfig(companyId, tenantId);
      for (let number = input.startNumber; number <= input.endNumber; number++) {
        await this.prisma.invoice
          .create({
            data: {
              tenantId,
              companyId,
              model: dbModel,
              series: input.series,
              number,
              environment: config.environment,
              status: 'INUTILIZED',
              operationNature: 'INUTILIZACAO',
              totalInvoice: 0,
            },
          })
          .catch(() => undefined); // número já existente permanece como está
      }
    }

    await this.audit.log({
      tenantId,
      companyId,
      userId,
      action: 'fiscal.numbering.inutilize',
      metadata: {
        model: input.model,
        series: input.series,
        range: `${input.startNumber}-${input.endNumber}`,
        success: result.success,
        sefazCode: result.sefazCode,
      },
    });

    return result;
  }

  // ------------------------------------------------------------------
  // Carta de correção
  // ------------------------------------------------------------------

  async correctionLetter(user: JwtPayload, invoiceId: string, input: CorrectionLetterInput) {
    const { tenantId, companyId, userId } = this.scope(user);
    await this.license.assertFiscalLicense(tenantId);

    const invoice = await this.requireInvoice(invoiceId, companyId);
    if (invoice.model !== 'NFE_55') {
      throw new UnprocessableEntityException('Carta de correção é permitida apenas para NF-e (modelo 55)');
    }
    if (invoice.status !== 'AUTHORIZED') {
      throw new UnprocessableEntityException('Apenas notas autorizadas aceitam carta de correção');
    }
    if (!invoice.accessKey) {
      throw new UnprocessableEntityException('Nota sem chave de acesso');
    }

    const previousCount = await this.prisma.invoiceEvent.count({
      where: { invoiceId: invoice.id, eventType: 'CORRECTION_LETTER', status: 'ACCEPTED' },
    });
    const sequence = previousCount + 1;

    const context = await this.contextService.build(tenantId, companyId);
    const result = await this.fiscalClient.gerarCartaCorrecao(invoice.id, {
      company: context,
      accessKey: invoice.accessKey,
      correctionText: input.correctionText,
      sequence,
    });

    await this.prisma.invoiceEvent.create({
      data: {
        invoiceId: invoice.id,
        eventType: 'CORRECTION_LETTER',
        sequence,
        protocol: result.protocol,
        correctionText: input.correctionText,
        status: result.success ? 'ACCEPTED' : 'REJECTED',
        xmlPath: result.xmlPath,
      },
    });

    if (!result.success) {
      await this.registerRejection(tenantId, companyId, invoice.id, result.sefazCode, result.sefazMessage, 'CORRECTION_LETTER');
    }

    await this.audit.log({
      tenantId,
      companyId,
      userId,
      action: 'fiscal.invoice.correction-letter',
      entity: 'Invoice',
      entityId: invoice.id,
      metadata: { sequence, success: result.success },
    });

    return result;
  }

  // ------------------------------------------------------------------
  // Consultas / arquivos
  // ------------------------------------------------------------------

  async sefazStatus(user: JwtPayload, uf: string) {
    const { tenantId, companyId } = this.scope(user);
    const context = await this.contextService.build(tenantId, companyId);
    const result = await this.fiscalClient.consultarStatusServico(uf, context);

    await this.prisma.fiscalServiceStatus.create({
      data: {
        uf: uf.toUpperCase(),
        environment: context.environment === 'production' ? 'PRODUCTION' : 'HOMOLOGATION',
        online: result.online,
        sefazCode: result.sefazCode,
        message: result.sefazMessage,
        responseMs: result.averageResponseTimeMs,
      },
    });

    return result;
  }

  async list(user: JwtPayload, filters: {
    model?: string;
    status?: string;
    customerId?: string;
    startDate?: string;
    endDate?: string;
    number?: string;
    series?: string;
    page?: number;
    pageSize?: number;
  }) {
    const { companyId } = this.scope(user);
    const page = Math.max(1, filters.page ?? 1);
    const pageSize = Math.min(100, Math.max(1, filters.pageSize ?? 20));

    const where: Record<string, unknown> = { companyId };
    if (filters.model === '55') where.model = 'NFE_55';
    if (filters.model === '65') where.model = 'NFCE_65';
    if (filters.status) where.status = filters.status;
    if (filters.customerId) where.customerId = filters.customerId;
    if (filters.number) where.number = parseInt(filters.number, 10);
    if (filters.series) where.series = parseInt(filters.series, 10);
    if (filters.startDate || filters.endDate) {
      where.issueDate = {
        ...(filters.startDate ? { gte: new Date(filters.startDate) } : {}),
        ...(filters.endDate ? { lte: new Date(filters.endDate + 'T23:59:59') } : {}),
      };
    }

    const [data, total] = await Promise.all([
      this.prisma.invoice.findMany({
        where,
        include: { customer: { select: { name: true, document: true } } },
        orderBy: { issueDate: 'desc' },
        skip: (page - 1) * pageSize,
        take: pageSize,
      }),
      this.prisma.invoice.count({ where }),
    ]);

    return { data, total, page, pageSize };
  }

  async findOne(user: JwtPayload, invoiceId: string) {
    const { companyId } = this.scope(user);
    const invoice = await this.prisma.invoice.findFirst({
      where: { id: invoiceId, companyId },
      include: {
        items: true,
        payments: true,
        events: { orderBy: { createdAt: 'desc' } },
        xmls: true,
        customer: { select: { name: true, document: true } },
      },
    });

    if (!invoice) {
      throw new NotFoundException('Nota fiscal não encontrada');
    }

    return invoice;
  }

  async queryAtSefaz(user: JwtPayload, invoiceId: string) {
    const { tenantId, companyId } = this.scope(user);
    const invoice = await this.requireInvoice(invoiceId, companyId);
    if (!invoice.accessKey) {
      throw new UnprocessableEntityException('Nota sem chave de acesso para consulta');
    }

    const context = await this.contextService.build(tenantId, companyId);
    return this.fiscalClient.consultarNota(invoice.id, context, invoice.accessKey);
  }

  async downloadXml(user: JwtPayload, invoiceId: string) {
    const { companyId } = this.scope(user);
    const invoice = await this.requireInvoice(invoiceId, companyId);
    if (!invoice.accessKey) {
      throw new UnprocessableEntityException('Nota sem XML disponível');
    }

    return this.fiscalClient.baixarXml(invoice.id, invoice.accessKey);
  }

  async danfe(user: JwtPayload, invoiceId: string) {
    const { companyId } = this.scope(user);
    const invoice = await this.requireInvoice(invoiceId, companyId);
    if (invoice.model !== 'NFE_55') {
      throw new UnprocessableEntityException('DANFE disponível apenas para NF-e (modelo 55)');
    }
    if (!invoice.accessKey) {
      throw new UnprocessableEntityException('Nota sem chave de acesso');
    }

    return this.fiscalClient.obterDanfe(invoice.id, invoice.accessKey);
  }

  async danfce(user: JwtPayload, invoiceId: string) {
    const { companyId } = this.scope(user);
    const invoice = await this.requireInvoice(invoiceId, companyId);
    if (invoice.model !== 'NFCE_65') {
      throw new UnprocessableEntityException('DANFCE disponível apenas para NFC-e (modelo 65)');
    }
    if (!invoice.accessKey) {
      throw new UnprocessableEntityException('Nota sem chave de acesso');
    }

    return this.fiscalClient.obterDanfce(invoice.id, invoice.accessKey);
  }

  // ------------------------------------------------------------------
  // Internos
  // ------------------------------------------------------------------

  private async requireFiscalConfig(companyId: string, tenantId: string) {
    const config = await this.prisma.fiscalConfig.findFirst({ where: { companyId, tenantId } });
    if (!config) {
      throw new UnprocessableEntityException('Empresa precisa ter configuração fiscal');
    }
    return config;
  }

  private async requireInvoice(invoiceId: string, companyId: string) {
    const invoice = await this.prisma.invoice.findFirst({ where: { id: invoiceId, companyId } });
    if (!invoice) {
      throw new NotFoundException('Nota fiscal não encontrada');
    }
    return invoice;
  }

  private mapItems(items: EmitNfeInput['items']) {
    return items.map((item, index) => ({
      itemNumber: index + 1,
      productCode: item.code,
      description: item.description,
      ncm: item.ncm,
      cest: item.cest,
      cfop: item.cfop,
      unit: item.unit,
      quantity: item.quantity,
      unitPrice: item.unitPrice,
      totalPrice: item.totalPrice,
      discount: item.discount,
      icmsCst: item.icmsCst,
      icmsCsosn: item.icmsCsosn,
      icmsRate: item.icmsRate,
      pisCst: item.pisCst,
      pisRate: item.pisRate,
      cofinsCst: item.cofinsCst,
      cofinsRate: item.cofinsRate,
    }));
  }

  private async createInvoiceRecord(params: {
    tenantId: string;
    companyId: string;
    model: 'NFE_55' | 'NFCE_65';
    series: number;
    number: number;
    environment: 'HOMOLOGATION' | 'PRODUCTION';
    operationNature: string;
    customerId?: string | null;
    saleId?: string | null;
    totals: { products: number; discount: number; invoice: number };
    items: EmitNfeInput['items'];
    payments: EmitNfeInput['payments'];
  }) {
    return this.prisma.invoice.create({
      data: {
        tenantId: params.tenantId,
        companyId: params.companyId,
        model: params.model,
        series: params.series,
        number: params.number,
        environment: params.environment,
        status: 'SENDING',
        operationNature: params.operationNature,
        customerId: params.customerId ?? null,
        saleId: params.saleId ?? null,
        totalProducts: params.totals.products,
        totalDiscount: params.totals.discount,
        totalInvoice: params.totals.invoice,
        items: {
          create: params.items.map((item) => ({
            productId: item.productId,
            code: item.code,
            description: item.description,
            ncm: item.ncm,
            cest: item.cest,
            cfop: item.cfop,
            unit: item.unit,
            quantity: item.quantity,
            unitPrice: item.unitPrice,
            totalPrice: item.totalPrice,
            discount: item.discount ?? 0,
            icmsCst: item.icmsCst,
            icmsCsosn: item.icmsCsosn,
            icmsRate: item.icmsRate,
            pisCst: item.pisCst,
            pisRate: item.pisRate,
            cofinsCst: item.cofinsCst,
            cofinsRate: item.cofinsRate,
          })),
        },
        payments: {
          create: params.payments.map((payment) => ({
            paymentMethod: payment.method,
            amount: payment.amount,
            cardBrand: payment.cardBrand,
            authorizationCode: payment.authorizationCode,
          })),
        },
      },
    });
  }

  /**
   * Chama o fiscal-service e persiste o resultado:
   * protocolo/XML em caso de autorização, código+mensagem em caso de rejeição.
   */
  private async dispatchEmission(
    invoiceId: string,
    tenantId: string,
    companyId: string,
    payload: EmitInvoicePayload,
    call: (payload: EmitInvoicePayload) => Promise<EmitInvoiceResult>,
  ) {
    let result: EmitInvoiceResult;
    try {
      result = await call(payload);
    } catch (error) {
      await this.prisma.invoice.update({
        where: { id: invoiceId },
        data: {
          status: 'ERROR',
          rejectionCode: 'FISCAL_SERVICE_ERROR',
          rejectionMessage: error instanceof Error ? error.message : 'Falha ao comunicar com fiscal-service',
        },
      });
      throw error;
    }

    if (result.success && result.status === 'AUTHORIZED') {
      await this.prisma.$transaction([
        this.prisma.invoice.update({
          where: { id: invoiceId },
          data: {
            status: 'AUTHORIZED',
            accessKey: result.accessKey,
            protocol: result.protocol,
            receiptNumber: result.receiptNumber,
            authorizedAt: result.authorizedAt ? new Date(result.authorizedAt) : new Date(),
            xmlPath: result.xmlPath,
            rejectionCode: null,
            rejectionMessage: null,
          },
        }),
        this.prisma.invoiceEvent.create({
          data: {
            invoiceId,
            eventType: 'AUTHORIZATION',
            protocol: result.protocol,
            status: 'ACCEPTED',
            xmlPath: result.xmlPath,
          },
        }),
        ...(result.xmlPath
          ? [
              this.prisma.invoiceXml.create({
                data: {
                  invoiceId,
                  type: payload.model === '55' ? 'NFE_AUTHORIZED' : 'NFCE_AUTHORIZED',
                  path: result.xmlPath,
                  sha256: result.xmlBase64 ? sha256OfBase64(result.xmlBase64) : undefined,
                },
              }),
            ]
          : []),
      ]);
    } else {
      await this.prisma.invoice.update({
        where: { id: invoiceId },
        data: {
          status: 'REJECTED',
          accessKey: result.accessKey,
          rejectionCode: result.rejectionCode ?? result.sefazCode,
          rejectionMessage: result.rejectionMessage ?? result.sefazMessage,
        },
      });
      await this.registerRejection(
        tenantId,
        companyId,
        invoiceId,
        result.rejectionCode ?? result.sefazCode,
        result.rejectionMessage ?? result.sefazMessage,
        'EMISSION',
      );
    }

    const invoice = await this.prisma.invoice.findUnique({ where: { id: invoiceId } });
    return { result, invoice };
  }

  private async registerRejection(
    tenantId: string,
    companyId: string,
    invoiceId: string | null,
    code?: string,
    message?: string,
    source = 'SEFAZ',
  ) {
    await this.prisma.fiscalRejection.create({
      data: {
        tenantId,
        companyId,
        invoiceId,
        code: code ?? 'UNKNOWN',
        message: message ?? 'Rejeição sem mensagem',
        source,
      },
    });
  }
}

function sha256OfBase64(base64: string): string {
  return createHash('sha256').update(Buffer.from(base64, 'base64')).digest('hex');
}
