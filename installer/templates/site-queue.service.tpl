[Unit]
Description=Queue worker __NAME__
After=network.target mariadb.service postgresql.service redis-server.service

[Service]
Type=simple
User=__USER__
Group=__USER__
WorkingDirectory=__APP_DIR__
ExecStart=/usr/bin/__PHP__ artisan queue:work --queue=__QUEUE__ --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
