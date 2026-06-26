#!/bin/sh
# Generate self-signed SSL certificate for development
if [ ! -f /etc/nginx/ssl/server.crt ]; then
    mkdir -p /etc/nginx/ssl
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/server.key \
        -out /etc/nginx/ssl/server.crt \
        -subj "/C=US/ST=State/L=City/O=Church/CN=localhost" \
        -addext "subjectAltName=DNS:localhost,DNS:app.local"
fi
