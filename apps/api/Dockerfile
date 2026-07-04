FROM node:22-alpine AS base
RUN corepack enable

FROM base AS build
WORKDIR /repo
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml* tsconfig.base.json ./
COPY packages ./packages
COPY apps/api ./apps/api
RUN pnpm install --no-frozen-lockfile
RUN pnpm --filter @integra/database prisma:generate
RUN pnpm run build:packages
RUN pnpm --filter @integra/api build

FROM base AS runtime
WORKDIR /repo
ENV NODE_ENV=production
COPY --from=build /repo ./
EXPOSE 3333
CMD ["node", "apps/api/dist/main.js"]
