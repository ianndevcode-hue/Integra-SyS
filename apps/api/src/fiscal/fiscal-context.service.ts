import { Injectable, UnprocessableEntityException } from '@nestjs/common';
import type { FiscalCompanyContext, FiscalEnvironment } from '@integra/types';
import { PrismaService } from '../core/prisma.service';

/**
 * Monta o contexto fiscal (emitente + certificado + CSC + ambiente)
 * enviado ao fiscal-service em cada operação.
 */
@Injectable()
export class FiscalContextService {
  constructor(private readonly prisma: PrismaService) {}

  async build(tenantId: string, companyId: string, options?: { requireCsc?: boolean }): Promise<FiscalCompanyContext> {
    const [company, config, certificate] = await Promise.all([
      this.prisma.company.findFirst({ where: { id: companyId, tenantId } }),
      this.prisma.fiscalConfig.findFirst({ where: { companyId, tenantId }, include: { technician: true } }),
      this.prisma.fiscalCertificate.findFirst({
        where: { companyId, tenantId, status: { in: ['ACTIVE', 'EXPIRING'] } },
        orderBy: { createdAt: 'desc' },
      }),
    ]);

    if (!company) {
      throw new UnprocessableEntityException('Empresa não encontrada');
    }
    if (!company.cnpj) {
      throw new UnprocessableEntityException('Empresa precisa ter CNPJ cadastrado');
    }
    if (!config) {
      throw new UnprocessableEntityException('Empresa precisa ter configuração fiscal');
    }
    if (!certificate) {
      throw new UnprocessableEntityException('Empresa precisa ter certificado A1 válido cadastrado');
    }
    if (certificate.validUntil && certificate.validUntil < new Date()) {
      throw new UnprocessableEntityException('Certificado A1 expirado. Atualize o certificado para emitir.');
    }

    const environment: FiscalEnvironment = config.environment === 'PRODUCTION' ? 'production' : 'homologation';

    let csc: FiscalCompanyContext['csc'];
    const cscRecord = await this.prisma.fiscalCsc.findFirst({
      where: { companyId, tenantId, environment: config.environment, active: true },
    });
    if (cscRecord) {
      csc = { id: cscRecord.cscId, encryptedToken: cscRecord.encryptedCscToken };
    }
    if (options?.requireCsc && !csc) {
      throw new UnprocessableEntityException('NFC-e precisa ter CSC/Token configurado para o ambiente atual');
    }

    return {
      tenantId,
      companyId,
      branchId: null,
      environment,
      uf: config.uf,
      certificate: {
        path: certificate.certificatePath,
        encryptedPassword: certificate.encryptedPassword,
      },
      csc,
      issuer: {
        cnpj: company.cnpj,
        corporateName: company.corporateName,
        tradeName: company.tradeName ?? undefined,
        stateRegistration: config.stateRegistration ?? 'ISENTO',
        municipalRegistration: config.municipalRegistration ?? undefined,
        crt: config.crt,
        address: {
          street: company.street ?? '',
          number: company.number ?? 'S/N',
          complement: company.complement ?? undefined,
          district: company.district ?? '',
          cityCode: config.cityCode,
          cityName: config.cityName,
          uf: config.uf,
          zipCode: company.zipCode ?? '',
          phone: company.phone ?? undefined,
        },
        ...(config.technician
          ? {
              technician: {
                name: config.technician.name,
                email: config.technician.email,
                cnpj: config.technician.cnpj,
                phone: config.technician.phone,
              },
            }
          : {}),
      } as FiscalCompanyContext['issuer'],
    };
  }
}
