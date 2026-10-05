[Unit]
Description=__NAME__
After=network.target mariadb.service postgresql.service redis-server.service

[Service]
Type=simple
User=__USER__
Group=__USER__
WorkingDirectory=__APP_DIR__
ExecStart=/usr/bin/__PHP__ artisan __ARGS__
Restart=always
RestartSec=5
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
