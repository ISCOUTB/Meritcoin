#!/bin/sh
RPC="${RPC:-http://besu-node-1:8545}"
echo "Watchdog iniciado"
LAST_BLOCK="0x0"
FROZEN_COUNT=0

while true; do
  sleep 30
  B=$(wget -qO- --post-data='{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
      --header='Content-Type: application/json' "$RPC" 2>/dev/null \
      | grep -o '0x[0-9a-f]*' | tail -1)

  echo "$(date): Bloque $B (último: $LAST_BLOCK)"

  if [ -z "$B" ] || [ "$B" = "$LAST_BLOCK" ]; then
    FROZEN_COUNT=$((FROZEN_COUNT + 1))
    echo "$(date): Sin avance ($FROZEN_COUNT/2)"
    if [ "$FROZEN_COUNT" -ge 2 ]; then
      echo "$(date): CONGELADO confirmado - reiniciando..."
      docker restart besu-node-1 besu-node-2 besu-node-3 besu-node-4
      FROZEN_COUNT=0
      sleep 30
    fi
  else
    FROZEN_COUNT=0
    LAST_BLOCK="$B"
  fi
done
