#!/bin/sh
set -eu

trap 'rm -f public/hot' EXIT
trap 'exit 0' INT TERM
rm -f public/hot

# This directory is a Linux-only named volume, separate from host node_modules.
npm ci
npm run build

# The exit trap keeps Laravel from pointing at a stopped development server.
npm run dev -- --host 0.0.0.0 &
vite_pid=$!
wait "$vite_pid"
