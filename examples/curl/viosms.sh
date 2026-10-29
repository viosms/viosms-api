#!/usr/bin/env bash
# Buy a virtual number on VioSMS, wait for the SMS verification code and
# cancel (refund) if it doesn't arrive. Needs curl and jq.
#
#   export VIOSMS_API_KEY=your_key
#   ./viosms.sh wa 16          # WhatsApp, United Kingdom

set -euo pipefail

BASE_URL="https://vio-sms.com/api/v1"
SERVICE="${1:-wa}"
COUNTRY="${2:-16}"
POLL_EVERY=5
GIVE_UP_AFTER=300

: "${VIOSMS_API_KEY:?Set VIOSMS_API_KEY first (Profile page on vio-sms.com)}"
AUTH="Authorization: Bearer $VIOSMS_API_KEY"

echo "Balance: \$$(curl -sS -H "$AUTH" "$BASE_URL/balance" | jq -r '.balance')"

BUY=$(curl -sS -H "$AUTH" -H "Content-Type: application/json" \
  -d "{\"service\":\"$SERVICE\",\"country\":\"$COUNTRY\"}" \
  "$BASE_URL/buy")

ID=$(echo "$BUY" | jq -r '.data.id // empty')
if [ -z "$ID" ]; then
  echo "Buy failed: $(echo "$BUY" | jq -r '.message // .error')" >&2
  exit 1
fi

echo "Number: $(echo "$BUY" | jq -r '.data.phone')  (id $ID, \$$(echo "$BUY" | jq -r '.data.price'))"
echo "Enter this number in the app, then wait..."

waited=0
while [ "$waited" -lt "$GIVE_UP_AFTER" ]; do
  STATUS=$(curl -sS -H "$AUTH" "$BASE_URL/status?id=$ID")
  case "$(echo "$STATUS" | jq -r '.data.status')" in
    SMS_RECEIVED|COMPLETED)
      echo "Code: $(echo "$STATUS" | jq -r '.data.smsCode')"
      echo "Full SMS: $(echo "$STATUS" | jq -r '.data.smsText')"
      exit 0
      ;;
    CANCELLED)
      echo "The activation expired and was refunded."
      exit 0
      ;;
  esac
  sleep "$POLL_EVERY"
  waited=$((waited + POLL_EVERY))
done

echo "No code yet, cancelling for a refund..."
curl -sS -H "$AUTH" -H "Content-Type: application/json" \
  -d "{\"id\":\"$ID\"}" "$BASE_URL/cancel" | jq -r '.message // .error'
