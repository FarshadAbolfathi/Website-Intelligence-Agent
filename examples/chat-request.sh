#!/usr/bin/env bash
# Ask the public chat API from the command line.
curl -s https://assistant.example.com/public/v1/chat \
  -H 'Content-Type: application/json' \
  -d '{"site_key":"YOUR_SITE_KEY","message":"قیمت این محصول چقدر است؟"}'
