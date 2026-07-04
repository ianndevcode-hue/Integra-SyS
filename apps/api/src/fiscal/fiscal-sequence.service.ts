import { Injectable, UnprocessableEntityException } from '@nestjs/common';
import type { FiscalModel } from '@integra/types';
import { PrismaService } from '../core/prisma.service';

/**
 * Controle de numeração fiscal por empresa + modelo + série + ambiente.
 *
 * - Homologação e produção têm numeração totalmente separada.
 * - A alocação usa transação com incremento atômico para nunca repetir número.
 */
@Injectable()
export class FiscalSequenceService {
  constructor(private readonly prisma: PrismaService) {}

  async allocateNumber(params: {
    tenantId: string;
    companyId: string;
    model: FiscalModel;
    series: number;
    environment: 'HOMOLOGATION' | 'PRODUCTION';
  }): Promise<number> {
    const dbModel = params.model === '55' ? 'NFE_55' : 'NFCE_65';

    return this.prisma.$transaction(async (tx) => {
      let sequence = await tx.fiscalSequence.findFirst({
        where: {
          companyId: params.companyId,
          branchId: null,
          model: dbModel,
          series: params.series,
          environment: params.environment,
        },
      });

      if (!sequence) {
        sequence = await tx.fiscalSequence.create({
          data: {
            tenantId: params.tenantId,
            companyId: params.companyId,
            model: dbModel,
            series: params.series,
            currentNumber: 0,
            nextNumber: 1,
            environment: params.environment,
          },
        });
      }

      if (sequence.status !== 'ACTIVE') {
        throw new UnprocessableEntityException(
          `Sequência fiscal ${params.model}/${params.series} está bloqueada (${sequence.status})`,
        );
      }

      const allocated = sequence.nextNumber;

      await tx.fiscalSequence.update({
        where: { id: sequence.id },
        data: { currentNumber: allocated, nextNumber: allocated + 1 },
      });

      return allocated;
    });
  }

  async list(companyId: string) {
    return this.prisma.fiscalSequence.findMany({
      where: { companyId },
      orderBy: [{ model: 'asc' }, { series: 'asc' }, { environment: 'asc' }],
    });
  }
}
