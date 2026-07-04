import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { AdminFiscalController } from './admin-fiscal.controller';
import { AdminFiscalService } from './admin-fiscal.service';

@Module({
  imports: [AuthModule],
  controllers: [AdminFiscalController],
  providers: [AdminFiscalService],
})
export class AdminModule {}
