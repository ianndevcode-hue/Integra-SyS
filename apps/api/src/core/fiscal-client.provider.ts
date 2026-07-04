import { Provider } from '@nestjs/common';
import { FiscalClient } from '@integra/fiscal-client';
import { getFiscalClientConfig } from '@integra/config';

export const FISCAL_CLIENT = 'FISCAL_CLIENT';

export const FiscalClientProvider: Provider = {
  provide: FISCAL_CLIENT,
  useFactory: () => {
    const config = getFiscalClientConfig();
    return new FiscalClient({
      baseUrl: config.baseUrl,
      internalKey: config.internalKey,
      timeoutMs: config.timeoutMs,
    });
  },
};
