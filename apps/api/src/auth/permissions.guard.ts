import { CanActivate, ExecutionContext, ForbiddenException, Injectable, SetMetadata } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import type { AuthenticatedRequest } from './jwt-auth.guard';

export const PERMISSIONS_KEY = 'required_permissions';

/** Exige que o usuário autenticado tenha TODAS as permissões listadas. */
export const RequirePermissions = (...permissions: string[]) => SetMetadata(PERMISSIONS_KEY, permissions);

@Injectable()
export class PermissionsGuard implements CanActivate {
  constructor(private readonly reflector: Reflector) {}

  canActivate(context: ExecutionContext): boolean {
    const required = this.reflector.getAllAndOverride<string[]>(PERMISSIONS_KEY, [
      context.getHandler(),
      context.getClass(),
    ]);

    if (!required || required.length === 0) {
      return true;
    }

    const request = context.switchToHttp().getRequest<AuthenticatedRequest>();
    const user = request.user;

    // MASTER_ADMIN e TENANT_ADMIN têm acesso amplo dentro dos seus escopos
    if (user.role === 'MASTER_ADMIN' || user.role === 'TENANT_ADMIN') {
      return true;
    }

    const missing = required.filter((permission) => !user.permissions.includes(permission));
    if (missing.length > 0) {
      throw new ForbiddenException(`Permissão fiscal insuficiente: ${missing.join(', ')}`);
    }

    return true;
  }
}
