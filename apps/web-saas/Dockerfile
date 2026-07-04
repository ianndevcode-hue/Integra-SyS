FROM node:22-alpine AS base
RUN corepack enable

FROM base AS build
WORKDIR /repo
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml* tsconfig.base.json ./
COPY packages ./packages
COPY apps/web-saas ./apps/web-saas
RUN pnpm install --no-frozen-lockfile --filter @integra/web-saas...
RUN pnpm --filter @integra/types build && pnpm --filter @integra/shared build && pnpm --filter @integra/ui build
RUN pnpm --filter @integra/web-saas build

FROM node:22-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production
COPY --from=build /repo/apps/web-saas/.next/standalone ./
COPY --from=build /repo/apps/web-saas/.next/static ./apps/web-saas/.next/static
EXPOSE 3000
CMD ["node", "apps/web-saas/server.js"]
