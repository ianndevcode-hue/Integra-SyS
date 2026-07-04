import { Injectable } from '@nestjs/common';
import { encryptSecret, decryptSecret } from '@integra/auth';
import { getApiConfig } from '@integra/config';

/** Cifra/decifra segredos fiscais (senha de certificado, CSC token)
 *  com AES-256-GCM. Nada é persistido em texto puro. */
@Injectable()
export class CryptoService {
  private readonly key = getApiConfig().encryptionKey;

  encrypt(plainText: string): string {
    return encryptSecret(plainText, this.key);
  }

  decrypt(payload: string): string {
    return decryptSecret(payload, this.key);
  }
}
