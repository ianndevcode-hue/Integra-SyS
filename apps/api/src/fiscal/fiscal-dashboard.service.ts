import { Injectable, UnprocessableEntityException } from '@nestjs/common';
import type { JwtPayload } from '@integra/types';
import { PrismaService } from '../core/prisma.service';

@Injectable()
export class FiscalDashboardService {
  constructor(private readonly prisma: PrismaService) {}

  async dashboard(user: JwtPayload) {
    if (!user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa associada');
    }
    const companyId = user.companyId;

    const monthStart = new Date();
    monthStart.setDate(1);
    monthStart.setHours(0, 0, 0, 0);

    const [
      nfeIssuedThisMonth,
      nfceIssuedThisMonth,
      authorized,
      rejected,
      canceled,
      pending,
      lastInvoice,
      certificate,
      config,
      sequences,
      sefazStatus,
      recentRejections,
    ] = await Promise.all([
      this.prisma.invoice.count({ where: { companyId, model: 'NFE_55', issueDate: { gte: monthStart } } }),
      this.prisma.invoice.count({ where: { companyId, model: 'NFCE_65', issueDate: { gte: monthStart } } }),
      this.prisma.invoice.count({ where: { companyId, status: 'AUTHORIZED' } }),
      this.prisma.invoice.count({ where: { companyId, status: 'REJECTED' } }),
      this.prisma.invoice.count({ where: { companyId, status: 'CANCELED' } }),
      this.prisma.invoice.count({ where: { companyId, status: { in: ['PENDING', 'SENDING', 'DRAFT'] } } }),
      this.prisma.invoice.findFirst({ where: { companyId }, orderBy: { issueDate: 'desc' } }),
      this.prisma.fiscalCertificate.findFirst({
        where: { companyId, status: { not: 'REMOVED' } },
        orderBy: { createdAt: 'desc' },
      }),
      this.prisma.fiscalConfig.findFirst({ where: { companyId } }),
      this.prisma.fiscalSequence.findMany({ where: { companyId } }),
      this.prisma.fiscalServiceStatus.findMany({ orderBy: { checkedAt: 'desc' }, take: 10 }),
      this.prisma.fiscalRejection.findMany({
        where: { companyId },
        orderBy: { createdAt: 'desc' },
        take: 5,
        include: { invoice: { select: { model: true, series: true, number: true } } },
      }),
    ]);

    const environment = config?.environment ?? 'HOMOLOGATION';
    const nfeSequence = sequences.find(
      (s) => s.model === 'NFE_55' && s.series === (config?.defaultNfeSeries ?? 1) && s.environment === environment,
    );
    const nfceSequence = sequences.find(
      (s) => s.model === 'NFCE_65' && s.series === (config?.defaultNfceSeries ?? 1) && s.environment === environment,
    );

    let certificateInfo: { validUntil: Date | null; daysToExpire: number | null; status: string } | null = null;
    if (certificate) {
      const daysToExpire = certificate.validUntil
        ? Math.ceil((certificate.validUntil.getTime() - Date.now()) / (24 * 60 * 60 * 1000))
        : null;
      certificateInfo = { validUntil: certificate.validUntil, daysToExpire, status: certificate.status };
    }

    return {
      nfeIssuedThisMonth,
      nfceIssuedThisMonth,
      authorized,
      rejected,
      canceled,
      pending,
      lastIssueAt: lastInvoice?.issueDate ?? null,
      certificate: certificateInfo,
      environment,
      nfeSeries: config?.defaultNfeSeries ?? null,
      nfceSeries: config?.defaultNfceSeries ?? null,
      nextNfeNumber: nfeSequence?.nextNumber ?? null,
      nextNfceNumber: nfceSequence?.nextNumber ?? null,
      sefazStatus: sefazStatus.map((s) => ({
        uf: s.uf,
        environment: s.environment,
        online: s.online,
        message: s.message,
        checkedAt: s.checkedAt,
      })),
      recentRejections,
    };
  }

  async rejections(user: JwtPayload, page = 1, pageSize = 20) {
    if (!user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa associada');
    }

    const where = { companyId: user.companyId };
    const [data, total] = await Promise.all([
      this.prisma.fiscalRejection.findMany({
        where,
        orderBy: { createdAt: 'desc' },
        skip: (page - 1) * pageSize,
        take: pageSize,
        include: { invoice: { select: { model: true, series: true, number: true, status: true } } },
      }),
      this.prisma.fiscalRejection.count({ where }),
    ]);

    return { data, total, page, pageSize };
  }

  async webserviceLogs(user: JwtPayload, page = 1, pageSize = 20) {
    if (!user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa associada');
    }

    const where = { companyId: user.companyId };
    const [data, total] = await Promise.all([
      this.prisma.fiscalWebserviceLog.findMany({
        where,
        orderBy: { createdAt: 'desc' },
        skip: (page - 1) * pageSize,
        take: pageSize,
      }),
      this.prisma.fiscalWebserviceLog.count({ where }),
    ]);

    return { data, total, page, pageSize };
  }
}
