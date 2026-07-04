import jwt, { SignOptions } from 'jsonwebtoken';
import type { JwtPayload } from '@integra/types';

export function signAccessToken(payload: JwtPayload, secret: string, expiresIn: string): string {
  return jwt.sign(payload as object, secret, { expiresIn } as SignOptions);
}

export function verifyAccessToken(token: string, secret: string): JwtPayload {
  return jwt.verify(token, secret) as JwtPayload;
}
