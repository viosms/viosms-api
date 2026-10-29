"""
Buy a virtual number on VioSMS, wait for the SMS verification code and
cancel (refund) if it doesn't arrive.

    pip install requests
    export VIOSMS_API_KEY=your_key
    python viosms.py wa 16          # WhatsApp, United Kingdom

Service and country codes come from GET /services and GET /countries:

    python viosms.py --services
    python viosms.py --countries
"""

import os
import sys
import time

import requests

BASE_URL = "https://vio-sms.com/api/v1"
POLL_EVERY = 5          # seconds between /status calls
GIVE_UP_AFTER = 5 * 60  # cancel if no code after this many seconds


class VioSMSError(Exception):
    def __init__(self, status, body):
        self.status = status
        self.body = body
        super().__init__(f"HTTP {status}: {body.get('error') or body}")


class VioSMS:
    def __init__(self, api_key, base_url=BASE_URL, timeout=30):
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.session = requests.Session()
        self.session.headers["Authorization"] = f"Bearer {api_key}"

    def _request(self, method, path, **kwargs):
        r = self.session.request(method, self.base_url + path, timeout=self.timeout, **kwargs)
        try:
            body = r.json()
        except ValueError:
            body = {"error": r.text}
        if not r.ok:
            err = VioSMSError(r.status_code, body)
            err.retry_after = int(r.headers.get("Retry-After", 0) or 0)
            raise err
        return body

    def balance(self):
        return self._request("GET", "/balance")["balance"]

    def services(self):
        return self._request("GET", "/services")["data"]

    def countries(self):
        return self._request("GET", "/countries")["data"]

    def buy(self, service, country, operator="any"):
        payload = {"service": service, "country": str(country), "operator": operator}
        return self._request("POST", "/buy", json=payload)["data"]

    def status(self, activation_id):
        return self._request("GET", "/status", params={"id": activation_id})["data"]

    def cancel(self, activation_id):
        return self._request("POST", "/cancel", json={"id": activation_id})

    def wait_for_code(self, activation_id, poll_every=POLL_EVERY, give_up_after=GIVE_UP_AFTER):
        """Poll /status until the activation leaves WAITING. Returns None on timeout."""
        deadline = time.monotonic() + give_up_after
        while time.monotonic() < deadline:
            activation = self.status(activation_id)
            if activation["status"] != "WAITING":
                return activation
            time.sleep(poll_every)
        return None

    def cancel_with_retry(self, activation_id):
        """Cancelling is allowed 2 minutes after buying; wait out Retry-After if needed."""
        for _ in range(5):
            try:
                return self.cancel(activation_id)
            except VioSMSError as e:
                if e.status in (400, 503) and e.retry_after:
                    time.sleep(e.retry_after)
                elif e.status == 503:
                    time.sleep(5)
                else:
                    raise
        raise RuntimeError("could not cancel activation " + activation_id)


def main():
    api_key = os.environ.get("VIOSMS_API_KEY")
    if not api_key:
        sys.exit("Set VIOSMS_API_KEY first (Profile page on vio-sms.com).")

    client = VioSMS(api_key)
    args = sys.argv[1:]

    if args[:1] == ["--services"]:
        for s in client.services():
            print(f"{s['code']:<8} {s['name']:<30} {s['price']}")
        return
    if args[:1] == ["--countries"]:
        for c in client.countries():
            print(f"{c['code']:<6} {c['name']}")
        return
    if len(args) < 2:
        sys.exit(__doc__)

    service, country = args[0], args[1]
    print(f"Balance: ${client.balance():.2f}")

    try:
        number = client.buy(service, country)
    except VioSMSError as e:
        if e.status == 402:
            sys.exit(f"Not enough balance: need ${e.body.get('required')}, have ${e.body.get('balance')}")
        if e.status == 403 and e.body.get("error") == "service_blocked":
            sys.exit(f"Service blocked for {e.retry_after}s after repeated failed activations.")
        if e.status == 500:
            sys.exit("No numbers in stock for this service/country. Try another country (nothing was charged).")
        raise

    print(f"Number: {number['phone']}  (id {number['id']}, ${number['price']}, valid until {number['expiresAt']})")
    print("Enter this number in the app, then wait...")

    activation = client.wait_for_code(number["id"])
    if activation and activation["status"] == "CANCELLED":
        print("The activation expired and was refunded.")
        return
    if activation:
        print(f"Code: {activation['smsCode']}")
        print(f"Full SMS: {activation['smsText']}")
        return

    print("No code yet, cancelling for a refund...")
    client.cancel_with_retry(number["id"])
    print("Cancelled, the price is back on your balance.")


if __name__ == "__main__":
    main()
