#!/bin/sh
# Default APP_URL from Render's public hostname when not set explicitly.
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
  export APP_URL="${RENDER_EXTERNAL_URL}/PHP_projects/social-hub"
fi
exec "$@"
