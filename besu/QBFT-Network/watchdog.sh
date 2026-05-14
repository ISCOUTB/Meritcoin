#!/bin/sh
RPC="${RPC:-http://besu-node-1:8545}"
echo "Watchdog iniciado"
while true; do
  B1=$(wget -qO- --post-data='{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' --header='Content-Type: application/json' "$RPC" 2>/dev/null | grep -o '0x[0-9a-f]*' | tail -1)
  echo "$(date): Bloque $B1"
  sleep 30
  B2=$(wget -qO- --post-data='{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' --header='Content-Type: application/json' "$RPC" 2>/dev/null | grep -o '0x[0-9a-f]*' | tail -1)
  echo "$(date): Check $B2"
  if [ -z "$B2" ] || [ "$B1" = "$B2" ]; then
    echo "$(date): CONGELADO - reiniciando..."
    docker restart besu-node-1 besu-node-2 besu-node-3 besu-node-4
    sleep 15
  fi
  sleep 30
done
