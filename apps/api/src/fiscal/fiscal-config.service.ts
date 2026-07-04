import {
  Inject,
  Injectable,
  NotFoundException,
  UnprocessableEntityException,
} from '@nestjs/common';
import type { FiscalClient } from '@integra/fiscal-client';
import type { JwtPayload } from '@integra/types';
import type { CertificateUploadInput, FiscalConfigUpdateInput } from '@integra/validation';
import { FISCAL_CLIENT } from '../core/fiscal-client.provider';
import { PrismaService } from '../core/prisma.service';
import { AuditService } from '../core/audit.service';
import { CryptoService } from '../core/crypto.service';

@Injectable()
export class FiscalConfigService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
    private readonly crypto: CryptoService,
    @Inject(FISCAL_CLIENT) private readonly fiscalClient: FiscalClient,
  ) {}

  private scope(user: JwtPayload) {
    if (!user.tenantId || !user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa/tenant associado');
    }
    return { tenantId: user.tenantId, companyId: user.companyId, userId: user.sub };
  }

  async getConfig(user: JwtPayload) {
    const { companyId } = this.scope(user);

    const config = await this.prisma.fiscalConfig.findFirst({
      where: { companyId },
      include: { technician: true },
    });

    const [sequences, csc, certificate] = await Promise.all([
      this.prisma.fiscalSequence.findMany({ where: { companyId }, orderBy: [{ model: 'asc' }, { series: 'asc' }] }),
      config
        ? this.prisma.fiscalCsc.findFirst({ where: { companyId, environment: config.environment, active: true } })
        : null,
      this.prisma.fiscalCertificate.findFirst({
        where: { companyId, status: { not: 'REMOVED' } },
        orderBy: { createdAt: 'desc' },
      }),
    ]);

    return {
      config,
      sequences,
      csc: csc ? { id: csc.id, cscId: csc.cscId, environment: csc.environment, active: csc.active } : null,
      certificate: certificate
        ? {
            id: certificate.id,
            subjectCnpj: certificate.subjectCnpj,
            serialNumber: certificate.serialNumber,
            validFrom: certificate.validFrom,
            validUntil: certificate.validUntil,
            status: certificate.status,
          }
        : null,
    };
  }

  async updateConfig(user: JwtPayload, input: FiscalConfigUpdateInput) {
    const { tenantId, companyId, userId } = this.scope(user);

    let config = await this.prisma.fiscalConfig.findFirst({ where: { companyId } });

    const data = {
      ...(input.uf ? { uf: input.uf } : {}),
      ...(input.cityCode ? { cityCode: input.cityCode } : {}),
      ...(input.cityName ? { cityName: input.cityName } : {}),
      ...(input.stateRegistration !== undefined ? { stateRegistration: input.stateRegistration } : {}),
      ...(input.municipalRegistration !== undefined ? { municipalRegistration: input.municipalRegistration } : {}),
      ...(input.taxRegime ? { taxRegime: input.taxRegime } : {}),
      ...(input.crt ? { crt: input.crt } : {}),
      ...(input.environment ? { environment: input.environment === 'production' ? 'PRODUCTION' as const : 'HOMOLOGATION' as const } : {}),
      ...(input.defaultNfeSeries ? { defaultNfeSeries: input.defaultNfeSeries } : {}),
      ...(input.defaultNfceSeries ? { defaultNfceSeries: input.defaultNfceSeries } : {}),
      ...(input.nfeEnabled !== undefined ? { nfeEnabled: input.nfeEnabled } : {}),
      ...(input.nfceEnabled !== undefined ? { nfceEnabled: input.nfceEnabled } : {}),
    };

    if (config) {
      config = await this.prisma.fiscalConfig.update({ where: { id: config.id }, data });
    } else {
      if (!input.uf || !input.cityCode || !input.cityName) {
        throw new UnprocessableEntityException('Para criar a configuração fiscal informe UF, código IBGE e município');
      }
      config = await this.prisma.fiscalConfig.create({
        data: {
          tenantId,
          companyId,
          uf: input.uf,
          cityCode: input.cityCode,
          cityName: input.cityName,
          stateRegistration: input.stateRegistration,
          municipalRegistration: input.municipalRegistration,
          taxRegime: input.taxRegime ?? 'SIMPLES_NACIONAL',
          crt: input.crt ?? 1,
          environment: input.environment === 'production' ? 'PRODUCTION' : 'HOMOLOGATION',
          defaultNfeSeries: input.defaultNfeSeries ?? 1,
          defaultNfceSeries: input.defaultNfceSeries ?? 1,
          nfeEnabled: input.nfeEnabled ?? false,
          nfceEnabled: input.nfceEnabled ?? false,
        },
      });
    }

    // Responsável técnico
    if (input.technicianName || input.technicianEmail || input.technicianCnpj || input.technicianPhone) {
      const existing = await this.prisma.fiscalResponsibleTechnician.findUnique({
        where: { fiscalConfigId: config.id },
      });
      const technicianData = {
        name: input.technicianName ?? existing?.name ?? '',
        email: input.technicianEmail ?? existing?.email ?? '',
        cnpj: input.technicianCnpj ?? existing?.cnpj ?? '',
        phone: input.technicianPhone ?? existing?.phone ?? '',
      };
      await this.prisma.fiscalResponsibleTechnician.upsert({
        where: { fiscalConfigId: config.id },
        update: technicianData,
        create: { fiscalConfigId: config.id, ...technicianData },
      });
    }

    // CSC (token cifrado, nunca em texto puro)
    if (input.cscId && input.cscToken) {
      const environment = config.environment;
      const encryptedToken = this.crypto.encrypt(input.cscToken);
      const existing = await this.prisma.fiscalCsc.findFirst({ where: { companyId, environment } });
      if (existing) {
        await this.prisma.fiscalCsc.update({
          where: { id: existing.id },
          data: { cscId: input.cscId, encryptedCscToken: encryptedToken, active: true },
        });
      } else {
        await this.prisma.fiscalCsc.create({
          data: { tenantId, companyId, cscId: input.cscId, encryptedCscToken: encryptedToken, environment },
        });
      }
    }

    // Ajuste manual de numeração
    if (input.nextNfeNumber || input.nextNfceNumber) {
      const updates: Array<{ model: 'NFE_55' | 'NFCE_65'; series: number; next: number }> = [];
      if (input.nextNfeNumber) updates.push({ model: 'NFE_55', series: config.defaultNfeSeries, next: input.nextNfeNumber });
      if (input.nextNfceNumber) updates.push({ model: 'NFCE_65', series: config.defaultNfceSeries, next: input.nextNfceNumber });

      for (const update of updates) {
        const sequence = await this.prisma.fiscalSequence.findFirst({
          where: { companyId, model: update.model, series: update.series, environment: config.environment },
        });
        if (sequence) {
          await this.prisma.fiscalSequence.update({
            where: { id: sequence.id },
            data: { nextNumber: update.next, currentNumber: Math.max(0, update.next - 1) },
          });
        } else {
          await this.prisma.fiscalSequence.create({
            data: {
              tenantId,
              companyId,
              model: update.model,
              series: update.series,
              nextNumber: update.next,
              currentNumber: Math.max(0, update.next - 1),
              environment: config.environment,
            },
          });
        }
      }
    }

    await this.audit.log({
      tenantId,
      companyId,
      userId,
      action: 'fiscal.config.update',
      entity: 'FiscalConfig',
      entityId: config.id,
      metadata: { fields: Object.keys(input) },
    });

    return this.getConfig(user);
  }

  // ------------------------------------------------------------------
  // Certificado digital A1
  // ------------------------------------------------------------------

  async uploadCertificate(user: JwtPayload, input: CertificateUploadInput) {
    const { tenantId, companyId, userId } = this.scope(user);

    // Senha nunca é salva em texto puro: cifrada com AES-256-GCM
    const encryptedPassword = this.crypto.encrypt(input.password);

    const result = await this.fiscalClient.enviarCertificado({
      tenantId,
      companyId,
      fileBase64: input.fileBase64,
      encryptedPassword,
    });

    if (!result.success || !result.certificatePath) {
      throw new UnprocessableEntityException(result.error?.message ?? 'Certificado inválido');
    }

    // Desativa certificados anteriores
    await this.prisma.fiscalCertificate.updateMany({
      where: { companyId, status: { not: 'REMOVED' } },
      data: { status: 'REMOVED' },
    });

    const certificate = await this.prisma.fiscalCertificate.create({
      data: {
        tenantId,
        companyId,
        certificatePath: result.certificatePath,
        encryptedPassword,
        validFrom: result.validFrom ? new Date(result.validFrom) : null,
        validUntil: result.validUntil ? new Date(result.validUntil) : null,
        serialNumber: result.serialNumber,
        subjectCnpj: result.subjectCnpj,
        status: result.status ?? 'ACTIVE',
      },
    });

    await this.audit.log({
      tenantId,
      companyId,
      userId,
      action: 'fiscal.certificate.upload',
      entity: 'FiscalCertificate',
      entityId: certificate.id,
      metadata: { subjectCnpj: result.subjectCnpj, validUntil: result.validUntil },
    });

    return {
      id: certificate.id,
      subjectCnpj: certificate.subjectCnpj,
      serialNumber: certificate.serialNumber,
      validFrom: certificate.validFrom,
      validUntil: certificate.validUntil,
      status: certificate.status,
    };
  }

  async testCertificate(user: JwtPayload, certificateId: string) {
    const { companyId } = this.scope(user);

    const certificate = await this.prisma.fiscalCertificate.findFirst({
      where: { id: certificateId, companyId },
    });
    if (!certificate) {
      throw new NotFoundException('Certificado não encontrado');
    }

    return this.fiscalClient.testarCertificado({
      certificatePath: certificate.certificatePath,
      encryptedPassword: certificate.encryptedPassword,
    });
  }

  async removeCertificate(user: JwtPayload, certificateId: string) {
    const { tenantId, companyId, userId } = this.scope(user);

    const certificate = await this.prisma.fiscalCertificate.findFirst({
      where: { id: certificateId, companyId },
    });
    if (!certificate) {
      throw new NotFoundException('Certificado não encontrado');
    }

    await this.fiscalClient.removerCertificado({ certificatePath: certificate.certificatePath });
    await this.prisma.fiscalCertificate.update({
      where: { id: certificate.id },
      data: { status: 'REMOVED' },
    });

    await this.audit.log({
      tenantId,
      companyId,
      userId,
      action: 'fiscal.certificate.remove',
      entity: 'FiscalCertificate',
      entityId: certificate.id,
    });

    return { success: true };
  }
}
