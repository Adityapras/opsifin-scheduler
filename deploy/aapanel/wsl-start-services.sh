#!/usr/bin/env bash
set -u

# Run as root from Windows Task Scheduler after WSL/systemd has started.
# Services that do not exist on a particular aaPanel install are skipped.

start_service() {
    local service_name="$1"
    local init_script="${2:-$1}"

    if systemctl list-unit-files "${service_name}.service" --no-legend 2>/dev/null | grep -q "${service_name}.service"; then
        systemctl start "$service_name"
        return
    fi

    if [ -x "/etc/init.d/${init_script}" ]; then
        "/etc/init.d/${init_script}" start
    fi
}

start_aapanel_supervisor() {
    local supervisor_bin="/www/server/panel/pyenv/bin/supervisord"
    local supervisor_config="/etc/supervisor/supervisord.conf"

    if pgrep -f "${supervisor_bin}.*${supervisor_config}" >/dev/null 2>&1; then
        return
    fi

    if [ -x /etc/init.d/supervisor ]; then
        /etc/init.d/supervisor start
        return
    fi

    if [ ! -x "$supervisor_bin" ] || [ ! -r "$supervisor_config" ]; then
        return
    fi

    # A force-killed daemon leaves these files behind and aaPanel reports
    # "daemon abnormal" until they are removed.
    rm -f /run/supervisor.sock /run/supervisord.pid
    "$supervisor_bin" -c "$supervisor_config"
}

if command -v bt >/dev/null 2>&1; then
    bt start >/dev/null 2>&1 || true
fi

start_service nginx nginx
start_service mysqld mysqld
start_service php-fpm-84 php-fpm-84
start_service cron cron
start_aapanel_supervisor
