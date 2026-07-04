import { Injectable, NotFoundException, UnprocessableEntityException } from '@nestjs/common';
import type { JwtPayload, PdvNfceStatusDto } from '@integra/types';
import { toPdvFiscalStatus } from '@integra/shared';
import type { PdvNfceInput } from '@integra/validation';
import { PrismaService } from '../core/prisma.service';
import { InvoiceService } from '../fiscal/invoice.service';

/**
 * Fluxo fiscal do PDV:
 * 1. PDV envia a venda (com id local para idempotência offline)
 * 2. API cria a venda no banco
 * 3. API chama o fiscal-service para emitir a NFC-e
 * 4. Resultado (autorização/rejeição) é persistido e devolvido ao PDV
 */
@Injectable()
export class PdvService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly invoiceService: InvoiceService,
  ) {}

  async emitNfceForSale(user: JwtPayload, input: PdvNfceInput) {
    if (!user.tenantId || !user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa associada');
    }

    // Idempotência da sincronização offline: mesma venda local não duplica
    let sale = await this.prisma.sale.findFirst({
      where: { companyId: user.companyId, localSaleId: input.localSaleId },
      include: { invoices: { orderBy: { createdAt: 'desc' }, take: 1 } },
    });

    if (sale?.invoices[0]?.status === 'AUTHORIZED') {
      return {
        sale,
        invoice: sale.invoices[0],
        result: { success: true, status: 'AUTHORIZED', alreadyEmitted: true },
      };
    }

    if (!sale) {
      const total = input.items.reduce((sum, item) => sum + item.totalPrice, 0) - (input.discount ?? 0);
      sale = await this.prisma.sale.create({
        data: {
          tenantId: user.tenantId,
          companyId: user.companyId,
          localSaleId: input.localSaleId,
          operatorId: user.sub,
          status: 'FINISHED',
          total,
          discount: input.discount ?? 0,
          soldAt: new Date(input.soldAt),
        },
        include: { invoices: { orderBy: { createdAt: 'desc' }, take: 1 } },
      });
    }

    const emission = await this.invoiceService.emitNfce(
      user,
      {
        customerDocument: input.customerDocument,
        customerName: input.customerName,
        items: input.items,
        payments: input.payments,
      },
      { saleId: sale.id },
    );

    return { sale, invoice: emission.invoice, result: emission.result };
  }

  async invoiceStatus(user: JwtPayload, invoiceId: string): Promise<PdvNfceStatusDto & { pdvStatus: string }> {
    if (!user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa associada');
    }

    const invoice = await this.prisma.invoice.findFirst({
      where: { id: invoiceId, companyId: user.companyId },
    });
    if (!invoice) {
      throw new NotFoundException('Nota não encontrada');
    }

    return {
      invoiceId: invoice.id,
      saleId: invoice.saleId ?? undefined,
      status: invoice.status,
      pdvStatus: toPdvFiscalStatus(invoice.status),
      accessKey: invoice.accessKey ?? undefined,
      protocol: invoice.protocol ?? undefined,
      rejectionCode: invoice.rejectionCode ?? undefined,
      rejectionMessage: invoice.rejectionMessage ?? undefined,
      authorizedAt: invoice.authorizedAt?.toISOString(),
    };
  }

  async retryEmission(user: JwtPayload, invoiceId: string) {
    if (!user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa associada');
    }

    const invoice = await this.prisma.invoice.findFirst({
      where: { id: invoiceId, companyId: user.companyId },
      include: { items: true, payments: true, sale: true, customer: true },
    });
    if (!invoice) {
      throw new NotFoundException('Nota não encontrada');
    }
    if (invoice.status !== 'REJECTED' && invoice.status !== 'ERROR') {
      throw new UnprocessableEntityException('Apenas notas rejeitadas ou com erro podem ser reenviadas');
    }
    if (invoice.model !== 'NFCE_65') {
      throw new UnprocessableEntityException('Reenvio pelo PDV disponível apenas para NFC-e');
    }

    const emission = await this.invoiceService.emitNfce(
      user,
      {
        customerDocument: invoice.customer?.document ?? undefined,
        customerName: invoice.customer?.name,
        items: invoice.items.map((item) => ({
          productId: item.productId ?? item.code,
          code: item.code,
          description: item.description,
          ncm: item.ncm,
          cest: item.cest ?? undefined,
          cfop: item.cfop,
          unit: item.unit,
          quantity: Number(item.quantity),
          unitPrice: Number(item.unitPrice),
          totalPrice: Number(item.totalPrice),
          discount: Number(item.discount),
          icmsCst: item.icmsCst ?? undefined,
          icmsCsosn: item.icmsCsosn ?? undefined,
          icmsRate: item.icmsRate !== null ? Number(item.icmsRate) : undefined,
          pisCst: item.pisCst ?? undefined,
          pisRate: item.pisRate !== null ? Number(item.pisRate) : undefined,
          cofinsCst: item.cofinsCst ?? undefined,
          cofinsRate: item.cofinsRate !== null ? Number(item.cofinsRate) : undefined,
        })),
        payments: invoice.payments.map((payment) => ({
          method: payment.paymentMethod as never,
          amount: Number(payment.amount),
          cardBrand: payment.cardBrand ?? undefined,
          authorizationCode: payment.authorizationCode ?? undefined,
        })),
      },
      { saleId: invoice.saleId ?? undefined },
    );

    return emission;
  }
}
