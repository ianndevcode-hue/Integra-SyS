/** Leitura centralizada de variáveis de ambiente. */

function env(name: string, fallback?: string): string {
  const value = process.env[name] ?? fallback;
  if (value === undefined) {
    throw new Error(`Variável de ambiente obrigatória ausente: ${name}`);
  }
  return value;
}

export function getApiConfig() {
  return {
    port: parseInt(env('API_PORT', '3333'), 10),
    jwtSecret: env('JWT_SECRET', 'dev-secret'),
    jwtExpiresIn: env('JWT_EXPIRES_IN', '8h'),
    databaseUrl: env('DATABASE_URL', 'postgresql://integra:integra@localhost:5432/integra_sys?schema=public'),
    redisUrl: env('REDIS_URL', 'redis://localhost:6379'),
    encryptionKey: env('APP_ENCRYPTION_KEY', '0'.repeat(64)),
  };
}

export function getFiscalClientConfig() {
  return {
    baseUrl: env('FISCAL_SERVICE_URL', 'http://localhost:3334'),
    internalKey: env('FISCAL_INTERNAL_KEY', 'dev-internal-key'),
    timeoutMs: parseInt(env('FISCAL_CLIENT_TIMEOUT_MS', '60000'), 10),
  };
}

export const INTERNAL_KEY_HEADER = 'X-INTEGRA-INTERNAL-KEY';
