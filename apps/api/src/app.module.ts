import { Module } from '@nestjs/common';
import { AuthModule } from './auth/auth.module';
import { CoreModule } from './core/core.module';
import { FiscalModule } from './fiscal/fiscal.module';
import { PdvModule } from './pdv/pdv.module';
import { AdminModule } from './admin/admin.module';

@Module({
  imports: [CoreModule, AuthModule, FiscalModule, PdvModule, AdminModule],
})
export class AppModule {}
