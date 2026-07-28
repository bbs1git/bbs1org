#!/bin/sh

stopping=0
child_pid=''

stop() {
    stopping=1
    if [ -n "$child_pid" ]; then kill -TERM "$child_pid" 2>/dev/null || true; fi
}

trap stop INT TERM

while [ "$stopping" -eq 0 ]; do
    if [ -f app/data/install.lock ]; then
        php index.php cron &
        child_pid=$!
        wait "$child_pid"
        child_pid=''
    fi
    [ "$stopping" -eq 1 ] && break
    sleep 60 &
    child_pid=$!
    wait "$child_pid"
    child_pid=''
done
