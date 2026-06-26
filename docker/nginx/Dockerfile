FROM nginx:1.25-alpine

RUN apk add --no-cache openssl

RUN rm /docker-entrypoint.d/10-listen-on-ipv6-by-default.sh

COPY default.conf /etc/nginx/conf.d/default.conf
COPY generate-ssl.sh /docker-entrypoint.d/40-generate-ssl.sh
RUN chmod +x /docker-entrypoint.d/40-generate-ssl.sh
