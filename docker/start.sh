#!/usr/bin/env bash
# Starts the game on a free port, remembered in .app-port, so that nobody has to think about it.
# APP_PORT=8050 make start chooses the port. With --announce, ends with the address to open.
set -uo pipefail

port_file=.app-port
first_time=$([ -f "$port_file" ] || echo 1)
wanted=${APP_PORT:-$(cat "$port_file" 2>/dev/null || echo 8040)}

# Something listens on this port of the machine.
listening() {
    (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null
}

# The port the game itself publishes, when it runs already: that one is not "taken".
own=$(docker compose port php 8000 2>/dev/null | sed 's/.*://')

port=$wanted
if [ "$port" != "$own" ] && listening "$port"; then
    if [ -n "${APP_PORT:-}" ]; then
        echo "Port $port is taken: choose another one, e.g. APP_PORT=$((port + 10)) make install" >&2
        exit 1
    fi
    for candidate in $(seq $((port + 1)) $((port + 50))); do
        if ! listening "$candidate"; then
            echo "Port $port is taken: the game will use $candidate." >&2
            port=$candidate
            break
        fi
    done
fi
echo "$port" > "$port_file"

echo "Starting the game${first_time:+ (the first time, Docker builds its image: a few minutes)}..."
# Quiet progress through variables rather than --progress, which older versions of Compose refuse.
export COMPOSE_PROGRESS=quiet BUILDKIT_PROGRESS=quiet
if ! APP_PORT=$port docker compose up -d --build --wait; then
    # Docker may leave a container without its port: the next try must start from scratch.
    docker compose down >/dev/null 2>&1
    echo >&2
    echo "The game could not start: see the message of Docker above, then run make install again." >&2
    exit 1
fi

if [ "${1:-}" = "--announce" ]; then
    echo
    echo "Ready: open http://127.0.0.1:$port"
fi
