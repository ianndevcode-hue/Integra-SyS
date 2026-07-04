import { ForbiddenException, Injectable } from '@nestjs/common';
import { PrismaService } from '../core/prisma.service';

@Injectable()
export class LicenseService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Regra de licença fiscal: apenas empresas com licença ativa E com o
   * módulo fiscal liberado podem emitir notas.
   */
  async assertFiscalLicense(tenantId: string | null): Promise<void> {
    if (!tenantId) {
      throw new ForbiddenException('Usuário sem tenant associado');
    }

    const license = await this.prisma.license.findFirst({
      where: { tenantId, status: { in: ['ACTIVE', 'TRIAL'] } },
      orderBy: { createdAt: 'desc' },
    });

    if (!license) {
      throw new ForbiddenException('Empresa sem licença ativa');
    }

    if (license.validUntil && license.validUntil < new Date()) {
      throw new ForbiddenException('Licença expirada');
    }

    if (!license.fiscalEnabled) {
      throw new ForbiddenException('Módulo fiscal não liberado na licença. Contate o suporte.');
    }
  }
}
