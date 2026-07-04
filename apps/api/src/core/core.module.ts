import { Global, Module } from '@nestjs/common';
import { PrismaService } from './prisma.service';
import { AuditService } from './audit.service';
import { CryptoService } from './crypto.service';
import { FiscalClientProvider } from './fiscal-client.provider';

@Global()
@Module({
  providers: [PrismaService, AuditService, CryptoService, FiscalClientProvider],
  exports: [PrismaService, AuditService, CryptoService, FiscalClientProvider],
})
export class CoreModule {}
