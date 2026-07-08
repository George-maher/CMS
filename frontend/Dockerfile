FROM node:22-alpine AS base

RUN apk add --no-cache wget

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --legacy-peer-deps

COPY . .

FROM base AS development
ARG VITE_API_URL
ARG VITE_PLATFORM_ADMIN_LOGIN_PATH
ENV VITE_API_URL=${VITE_API_URL:-/api}
ENV VITE_PLATFORM_ADMIN_LOGIN_PATH=${VITE_PLATFORM_ADMIN_LOGIN_PATH:-platform-secure-admin-login}
ENV NODE_OPTIONS="--max-old-space-size=1024"
# Pre-optimize dependencies at build time so Vite doesn't OOM at runtime
RUN npx vite optimize --force 2>/dev/null || true
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]

FROM base AS build
ARG VITE_API_URL
ARG VITE_PLATFORM_ADMIN_LOGIN_PATH
ENV VITE_API_URL=${VITE_API_URL:-/api}
ENV VITE_PLATFORM_ADMIN_LOGIN_PATH=${VITE_PLATFORM_ADMIN_LOGIN_PATH:-platform-secure-admin-login}
RUN npm run build

FROM nginx:1.25-alpine AS production
COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
