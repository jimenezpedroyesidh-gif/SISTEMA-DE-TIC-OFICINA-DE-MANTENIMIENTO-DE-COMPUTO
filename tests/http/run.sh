#!/usr/bin/env bash
# Arranca el servidor de pruebas contra tickets_db_test, ejecuta la suite HTTP y lo apaga.
# Uso (desde la raíz del proyecto):  bash tests/http/run.sh
set -u
PORT="${TEST_PORT:-8097}"
export TEST_DB_NAME="${TEST_DB_NAME:-tickets_db_test}"
case "$TEST_DB_NAME" in *_test) ;; *) echo "La base debe terminar en _test"; exit 2;; esac
CONEXIONES_CLAVE_ARCHIVO="${TMPDIR:-/tmp}/zt_http_clave_conexiones.key" APP_DB_NAME="$TEST_DB_NAME" PHP_CLI_SERVER_WORKERS=6 php -S "127.0.0.1:$PORT" -t public >/dev/null 2>"${TEST_SERVER_LOG:-/dev/null}" &
PID=$!
trap 'kill $PID 2>/dev/null' EXIT
sleep 1.5
TEST_BASE_URL="http://127.0.0.1:$PORT" php tests/http/run_http_tests.php
