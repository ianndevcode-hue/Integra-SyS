import { Injectable } from '@nestjs/common';
import { PrismaService } from './prisma.service';

export interface AuditEntry {
  tenantId?: string | null;
  companyId?: string | null;
  userId?: string | null;
  action: string;
  entity?: string;
  entityId?: string;
  metadata?: Record<string, unknown>;
  ip?: string;
}

/** Toda operação fiscal relevante (emissão, cancelamento, inutilização,
 *  CC-e, alteração de config, certificado) gera registro de auditoria. */
@Injectable()
export class AuditService {
  constructor(private readonly prisma: PrismaService) {}

  async log(entry: AuditEntry): Promise<void> {
    await this.prisma.auditLog.create({
      data: {
        tenantId: entry.tenantId ?? null,
        companyId: entry.companyId ?? null,
        userId: entry.userId ?? null,
        action: entry.action,
        entity: entry.entity,
        entityId: entry.entityId,
        metadata: entry.metadata as object | undefined,
        ip: entry.ip,
      },
    });
  }
}
