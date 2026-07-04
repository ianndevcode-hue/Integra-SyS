import { PrismaClient } from '@prisma/client';

export * from '@prisma/client';

let prisma: PrismaClient | undefined;

/** Singleton do PrismaClient para reuso entre módulos da API. */
export function getPrismaClient(): PrismaClient {
  if (!prisma) {
    prisma = new PrismaClient();
  }
  return prisma;
}
