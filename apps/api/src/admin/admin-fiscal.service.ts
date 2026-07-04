import { Inject, Injectable } from '@nestjs/common';
import type { FiscalClient } from '@integra/fiscal-client';
import { FISCAL_CLIENT } from '../core/fiscal-client.provider';
import { PrismaService } from '../core/prisma.service';

/**
 * Visão fiscal administrativa (Admin Master): suporte, diagnóstico e
 * auditoria. Não emite notas em nome de clientes.
 */
@Injectable()
export class AdminFiscalService {
  constructor(
    private readonly prisma: PrismaService,
    @Inject(FISCAL_CLIENT) private readonly fiscalClient: FiscalClient,
  ) {}

  async overview() {
    const monthStart = new Date();
    monthStart.setDate(1);
    monthStart.setHours(0, 0, 0, 0);

    const in30Days = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000);

    const [companiesWithFiscal, invoicesThisMonth, recentFailures, expiringCertificates] = await Promise.all([
      this.prisma.fiscalConfig.count({ where: { OR: [{ nfeEnabled: true }, { nfceEnabled: true }] } }),
      this.prisma.invoice.count({ where: { issueDate: { gte: monthStart } } }),
      this.prisma.fiscalRejection.count({ where: { createdAt: { gte: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000) } } }),
      this.prisma.fiscalCertificate.count({
        where: { status: { not: 'REMOVED' }, validUntil: { lte: in30Days, gte: new Date() } },
      }),
    ]);

    let fiscalServiceHealth: { status: string } | { status: 'offline'; error: string };
    try {
      fiscalServiceHealth = await this.fiscalClient.health();
    } catch (error) {
      fiscalServiceHealth = { status: 'offline', error: error instanceof Error ? error.message : 'sem resposta' };
    }

    return { companiesWithFiscal, invoicesThisMonth, recentFailures, expiringCertificates, fiscalServiceHealth };
  }

  async companiesWithFiscal() {
    const configs = await this.prisma.fiscalConfig.findMany({
      include: { company: { select: { id: true, cnpj: true, corporateName: true, tenantId: true } } },
      orderBy: { updatedAt: 'desc' },
    });

    const counts = await this.prisma.invoice.groupBy({
      by: ['companyId'],
      _count: { id: true },
    });
    const countMap = new Map(counts.map((c) => [c.companyId, c._count.id]));

    return configs.map((config) => ({
      companyId: config.companyId,
      tenantId: config.tenantId,
      cnpj: config.company.cnpj,
      corporateName: config.company.corporateName,
      uf: config.uf,
      environment: config.environment,
      nfeEnabled: config.nfeEnabled,
      nfceEnabled: config.nfceEnabled,
      invoiceCount: countMap.get(config.companyId) ?? 0,
    }));
  }

  async invoicesByCompany(companyId: string, page = 1, pageSize = 20) {
    const where = { companyId };
    const [data, total] = await Promise.all([
      this.prisma.invoice.findMany({
        where,
        orderBy: { issueDate: 'desc' },
        skip: (page - 1) * pageSize,
        take: pageSize,
      }),
      this.prisma.invoice.count({ where }),
    ]);

    return { data, total, page, pageSize };
  }

  async recentFailures(page = 1, pageSize = 50) {
    const [data, total] = await Promise.all([
      this.prisma.fiscalRejection.findMany({
        orderBy: { createdAt: 'desc' },
        skip: (page - 1) * pageSize,
        take: pageSize,
        include: {
          company: { select: { corporateName: true, cnpj: true } },
          invoice: { select: { model: true, series: true, number: true } },
        },
      }),
      this.prisma.fiscalRejection.count(),
    ]);

    return { data, total, page, pageSize };
  }

  async expiringCertificates() {
    const in45Days = new Date(Date.now() + 45 * 24 * 60 * 60 * 1000);

    return this.prisma.fiscalCertificate.findMany({
      where: { status: { not: 'REMOVED' }, validUntil: { lte: in45Days } },
      include: { company: { select: { corporateName: true, cnpj: true } } },
      orderBy: { validUntil: 'asc' },
    });
  }

  async fiscalUsageByTenant() {
    const usage = await this.prisma.invoice.groupBy({
      by: ['tenantId'],
      _count: { id: true },
      _sum: { totalInvoice: true },
    });

    const tenants = await this.prisma.tenant.findMany({
      where: { id: { in: usage.map((u) => u.tenantId) } },
      select: { id: true, name: true, slug: true },
    });
    const tenantMap = new Map(tenants.map((t) => [t.id, t]));

    return usage.map((u) => ({
      tenantId: u.tenantId,
      tenant: tenantMap.get(u.tenantId) ?? null,
      invoiceCount: u._count.id,
      totalAmount: u._sum.totalInvoice,
    }));
  }

  async webserviceLogs(page = 1, pageSize = 50) {
    const [data, total] = await Promise.all([
      this.prisma.fiscalWebserviceLog.findMany({
        orderBy: { createdAt: 'desc' },
        skip: (page - 1) * pageSize,
        take: pageSize,
        include: { company: { select: { corporateName: true } } },
      }),
      this.prisma.fiscalWebserviceLog.count(),
    ]);

    return { data, total, page, pageSize };
  }

  async sefazStatusHistory() {
    return this.prisma.fiscalServiceStatus.findMany({
      orderBy: { checkedAt: 'desc' },
      take: 54,
    });
  }
}
