APP_NAME="Studio"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://studio.__DOMAIN__
APP_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=studio
DB_USERNAME=studio
DB_PASSWORD=__DB_PASSWORD__

SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

# Outgoing email: password reset links and the like. "log" writes them to
# storage/logs instead of sending; Studio tells people so until this is set.
# To send through any provider's SMTP (Resend, Postmark, Mailgun, SES, Brevo,
# Google Workspace…): MAIL_MAILER=smtp and the lines below, then as root
#   studio-recover reload
# See the Email section of the docs.
MAIL_MAILER=log
MAIL_FROM_ADDRESS=studio@__DOMAIN__
MAIL_FROM_NAME="Studio"
#MAIL_HOST=smtp.example.com
#MAIL_PORT=587
#MAIL_SCHEME=smtp
#MAIL_USERNAME=
#MAIL_PASSWORD=

STUDIO_DOMAIN=__DOMAIN__
STUDIO_WORKSPACE_DRIVER=native
STUDIO_HELPER=/usr/local/sbin/studio-admin
STUDIO_HELPER_SUDO=true
STUDIO_CALLBACK_URL=http://127.0.0.1:8081/api/bridge/events
STUDIO_PHP_VERSIONS=__PHP_VERSIONS__

# The one git provider of this Studio: github, gitlab, bitbucket or azure.
# STUDIO_GIT_URL: a self-managed GitLab, or https://dev.azure.com/<organization>.
STUDIO_GIT_PROVIDER=__GIT_PROVIDER__
STUDIO_GIT_URL=__GIT_URL__

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=__REVERB_APP_ID__
REVERB_APP_KEY=__REVERB_APP_KEY__
REVERB_APP_SECRET=__REVERB_APP_SECRET__
REVERB_HOST=studio.__DOMAIN__
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
