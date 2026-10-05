[__NAME__]
user = __USER__
group = __GROUP__
listen = /run/php/__NAME__.sock
listen.owner = caddy
listen.group = caddy
listen.mode = 0660
pm = ondemand
pm.max_children = __MAX__
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[memory_limit] = 256M
php_admin_value[error_log] = __LOG__
php_admin_flag[log_errors] = on
clear_env = no
