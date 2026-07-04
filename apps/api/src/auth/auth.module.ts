import { Module } from '@nestjs/common';
import { AuthController } from './auth.controller';
import { AuthService } from './auth.service';
import { LicenseService } from './license.service';

@Module({
  controllers: [AuthController],
  providers: [AuthService, LicenseService],
  exports: [AuthService, LicenseService],
})
export class AuthModule {}
