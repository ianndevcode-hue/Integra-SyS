export class FiscalServiceHttpError extends Error {
  constructor(
    public readonly statusCode: number,
    public readonly body: unknown,
    message?: string,
  ) {
    super(message ?? `fiscal-service respondeu com status ${statusCode}`);
    this.name = 'FiscalServiceHttpError';
  }
}

export class FiscalServiceUnavailableError extends Error {
  constructor(cause?: unknown) {
    super('fiscal-service indisponível ou sem resposta');
    this.name = 'FiscalServiceUnavailableError';
    this.cause = cause;
  }
}
