import { Module } from '@nestjs/common';
import { AuthController } from './auth.controller';
import { AuthService } from './auth.service';
import { JwtAuthGuard } from './jwt-auth.guard';
import { LicenseService } from './license.service';
import { PermissionsGuard } from './permissions.guard';

@Module({
  controllers: [AuthController],
  providers: [AuthService, LicenseService, JwtAuthGuard, PermissionsGuard],
  exports: [AuthService, LicenseService, JwtAuthGuard, PermissionsGuard],
})
export class AuthModule {}
