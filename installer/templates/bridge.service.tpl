[Unit]
Description=Studio bridge for __HANDLE__/__SLUG__
After=network.target

[Service]
Type=simple
User=__USER__
Group=__USER__
WorkingDirectory=__WORKSPACE_DIR__
EnvironmentFile=__ENV_FILE__
Environment=BRIDGE_HOST=127.0.0.1
Environment=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
ExecStart=/usr/bin/node __STUDIO_DIR__/bridge/bin/bridge.js
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
