#!/usr/bin/env bash
#
# start-portal.sh — запуск PHP dev-сервера SesamePortal на порту 8080.
#
# Использование:
#   ./start-portal.sh            # запустить в фоне
#   ./start-portal.sh -f         # запустить на переднем плане (логи в терминал)
#   ./start-portal.sh -s         # статус (запущен/порт занят/остановлен)
#   ./start-portal.sh -k         # остановить запущенный сервер
#   ./start-portal.sh -r         # перезапустить (stop + start)
#
# Логи:   /tmp/sesame-portal-server.log
# PID:    /tmp/sesame-portal.pid

set -euo pipefail

PORT=8080
HOST=0.0.0.0
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PUBLIC="$ROOT/public"
PID_FILE="/tmp/sesame-portal.pid"
LOG_FILE="/tmp/sesame-portal-server.log"

usage() {
    sed -n '2,14p' "$0"
    exit 1
}

is_running() {
    [[ -f "$PID_FILE" ]] || return 1
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    [[ -n "$pid" ]] || return 1
    kill -0 "$pid" 2>/dev/null
}

port_listening() {
    ss -ltn 2>/dev/null | grep -q ":$PORT " 2>/dev/null
}

cmd_status() {
    if is_running; then
        echo "portal: запущен (PID $(cat "$PID_FILE"), порт $PORT)"
        return 0
    fi
    if port_listening; then
        echo "portal: порт $PORT занят, но PID-файл не соответствует (возможно, запущен другой процесс)"
        return 1
    fi
    echo "portal: остановлен"
    return 3
}

cmd_stop() {
    if ! is_running && ! port_listening; then
        echo "portal: уже остановлен"
        rm -f "$PID_FILE"
        return 0
    fi

    # Сначала пытаемся остановить по PID-файлу
    if is_running; then
        local pid
        pid="$(cat "$PID_FILE")"
        echo "portal: останавливаю PID $pid ..."
        kill "$pid" 2>/dev/null || true
        for _ in 1 2 3 4 5; do
            kill -0 "$pid" 2>/dev/null || break
            sleep 0.3
        done
        if kill -0 "$pid" 2>/dev/null; then
            echo "portal: процесс не завершился, шлю SIGKILL"
            kill -9 "$pid" 2>/dev/null || true
        fi
    fi

    # Добиваем любые php -S на этом порту
    if port_listening; then
        pkill -f "php -S ${HOST}:${PORT}" 2>/dev/null || true
        sleep 0.5
    fi

    rm -f "$PID_FILE"
    echo "portal: остановлен"
}

cmd_start() {
    if is_running; then
        echo "portal: уже запущен (PID $(cat "$PID_FILE"), порт $PORT)"
        echo "  перезапуск: $0 -r"
        return 0
    fi

    if port_listening; then
        echo "portal: ошибка — порт $PORT уже занят" >&2
        echo "  освобождаю порт (pkill php -S ${HOST}:${PORT}) ..."
        pkill -f "php -S ${HOST}:${PORT}" 2>/dev/null || true
        sleep 0.5
        if port_listening; then
            echo "portal: порт $PORT всё ещё занят — не могу запустить" >&2
            exit 1
        fi
    fi

    command -v php >/dev/null || { echo "portal: php не найден в PATH" >&2; exit 1; }

    if [[ "${1:-}" == "-f" ]]; then
        # Передний план — блокирует терминал, логи в stdout/stderr
        echo "portal: запускаю на переднем плане (0.0.0.0:$PORT) ..."
        exec php -S "$HOST:$PORT" -t "$PUBLIC"
    fi

    # Фоновый запуск через setsid — переживает закрытие терминала
    echo "portal: запускаю dev-сервер (0.0.0.0:$PORT, лог → $LOG_FILE) ..."
    setsid php -S "$HOST:$PORT" -t "$PUBLIC" </dev/null >>"$LOG_FILE" 2>&1 &
    local pid=$!
    echo "$pid" > "$PID_FILE"

    # Ждём, пока порт начнёт слушаться
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        port_listening && break
        if ! kill -0 "$pid" 2>/dev/null; then
            echo "portal: процесс завершился преждевременно — проверьте $LOG_FILE" >&2
            tail -5 "$LOG_FILE" >&2 2>/dev/null || true
            rm -f "$PID_FILE"
            exit 1
        fi
        sleep 0.3
    done

    if ! port_listening; then
        echo "portal: порт $PORT не слушается за 3 сек — проверьте $LOG_FILE" >&2
        tail -5 "$LOG_FILE" >&2 2>/dev/null || true
        exit 1
    fi

    echo "portal: запущен (PID $pid, http://127.0.0.1:$PORT)"
    echo "  лог:       $LOG_FILE"
    echo "  остановка: $0 -k"
    echo "  статус:    $0 -s"
}

cmd_restart() {
    cmd_stop
    cmd_start
}

main() {
    local action="${1:-start}"
    case "$action" in
        -s|--status) cmd_status ;;
        -k|--stop)   cmd_stop ;;
        -r|--restart) cmd_restart ;;
        -f|--foreground) shift; cmd_start -f ;;
        -h|--help)   usage ;;
        start)       cmd_start ;;
        *)           usage ;;
    esac
}

main "$@"