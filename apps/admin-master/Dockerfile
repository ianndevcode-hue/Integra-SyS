FROM node:22-alpine AS base
RUN corepack enable

FROM base AS build
WORKDIR /repo
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml* tsconfig.base.json ./
COPY packages ./packages
COPY apps/admin-master ./apps/admin-master
RUN pnpm install --no-frozen-lockfile --filter @integra/admin-master...
RUN pnpm --filter @integra/types build && pnpm --filter @integra/shared build && pnpm --filter @integra/ui build
RUN pnpm --filter @integra/admin-master build

FROM node:22-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production
COPY --from=build /repo/apps/admin-master/.next/standalone ./
COPY --from=build /repo/apps/admin-master/.next/static ./apps/admin-master/.next/static
EXPOSE 3001
ENV PORT=3001
CMD ["node", "apps/admin-master/server.js"]
