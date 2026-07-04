import { createCipheriv, createDecipheriv, randomBytes } from 'crypto';

/**
 * Criptografia AES-256-GCM para segredos fiscais
 * (senha do certificado A1 e CSC token). Nada é salvo em texto puro.
 *
 * Formato do payload: base64(iv):base64(authTag):base64(ciphertext)
 */

const ALGORITHM = 'aes-256-gcm';

function keyBuffer(hexKey: string): Buffer {
  const key = Buffer.from(hexKey, 'hex');
  if (key.length !== 32) {
    throw new Error('APP_ENCRYPTION_KEY deve ter 32 bytes em hex (64 caracteres)');
  }
  return key;
}

export function encryptSecret(plainText: string, hexKey: string): string {
  const iv = randomBytes(12);
  const cipher = createCipheriv(ALGORITHM, keyBuffer(hexKey), iv);
  const encrypted = Buffer.concat([cipher.update(plainText, 'utf8'), cipher.final()]);
  const authTag = cipher.getAuthTag();
  return `${iv.toString('base64')}:${authTag.toString('base64')}:${encrypted.toString('base64')}`;
}

export function decryptSecret(payload: string, hexKey: string): string {
  const [ivB64, tagB64, dataB64] = payload.split(':');
  if (!ivB64 || !tagB64 || !dataB64) {
    throw new Error('Payload cifrado inválido');
  }
  const decipher = createDecipheriv(ALGORITHM, keyBuffer(hexKey), Buffer.from(ivB64, 'base64'));
  decipher.setAuthTag(Buffer.from(tagB64, 'base64'));
  return Buffer.concat([decipher.update(Buffer.from(dataB64, 'base64')), decipher.final()]).toString('utf8');
}
