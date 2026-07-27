"""
Tests for Smart Lead Form overhaul:
- destination-cities master endpoints (GET all/one, PUT admin-only)
- lead trip auto-derivation (trip_end_date, duration_label, stay_summary)
- passport fields
- Supplier RFQ email preview (plain text) includes new blocks
"""
import os
import requests
import pytest

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/")
if not BASE_URL:
    with open("/app/frontend/.env") as f:
        for line in f:
            if line.startswith("REACT_APP_BACKEND_URL="):
                BASE_URL = line.split("=", 1)[1].strip().rstrip("/")
                break
API = f"{BASE_URL}/api"

CREDS = {
    "admin": {"email": "admin@indianrounds.com", "password": "admin123"},
    "sales": {"email": "sales@indianrounds.com", "password": "sales123"},
    "ops":   {"email": "ops@indianrounds.com",   "password": "ops123"},
}


def _login(role):
    s = requests.Session()
    r = s.post(f"{API}/auth/login", json=CREDS[role], timeout=15)
    assert r.status_code == 200, f"login {role} failed: {r.text}"
    tok = r.json().get("access_token")
    if tok:
        s.headers.update({"Authorization": f"Bearer {tok}"})
    return s


@pytest.fixture(scope="module")
def admin(): return _login("admin")

@pytest.fixture(scope="module")
def sales(): return _login("sales")

@pytest.fixture(scope="module")
def ops(): return _login("ops")


# ---------- destination-cities ----------
class TestDestinationCities:
    EXPECTED_DESTS = [
        "Thailand", "Bali", "Dubai", "Singapore", "Malaysia",
        "Sri Lanka", "Vietnam", "Maldives", "Europe", "Kashmir",
        "Kerala", "Goa", "Andaman", "Rajasthan", "Himachal", "Uttarakhand",
    ]

    def test_list_all(self, admin):
        r = admin.get(f"{API}/destination-cities")
        assert r.status_code == 200
        data = r.json()
        assert isinstance(data, dict)
        missing = [d for d in self.EXPECTED_DESTS if d not in data]
        assert not missing, f"Missing destinations: {missing}. Got keys: {list(data.keys())}"
        assert len(data) >= 16

    def test_thailand_cities(self, admin):
        r = admin.get(f"{API}/destination-cities/Thailand")
        assert r.status_code == 200
        body = r.json()
        assert body["destination"] == "Thailand"
        cities = body["cities"]
        assert isinstance(cities, list) and len(cities) >= 10
        for c in ["Bangkok", "Chiang Mai", "Hua Hin"]:
            assert c in cities, f"{c} missing from Thailand list: {cities}"

    def test_put_forbidden_for_sales(self, sales):
        r = sales.put(f"{API}/destination-cities/Thailand", json={"cities": ["Bangkok"]})
        assert r.status_code == 403

    def test_put_forbidden_for_ops(self, ops):
        r = ops.put(f"{API}/destination-cities/Thailand", json={"cities": ["Bangkok"]})
        assert r.status_code == 403

    def test_put_admin_replaces_and_trims(self, admin):
        # Save original
        orig = admin.get(f"{API}/destination-cities/Thailand").json()["cities"]
        try:
            payload = {"cities": ["  Bangkok  ", "", "Pattaya", "   ", "TEST_City"]}
            r = admin.put(f"{API}/destination-cities/Thailand", json=payload)
            assert r.status_code == 200
            saved = r.json()["cities"]
            assert saved == ["Bangkok", "Pattaya", "TEST_City"], saved
            # verify persistence via GET
            r2 = admin.get(f"{API}/destination-cities/Thailand")
            assert r2.json()["cities"] == ["Bangkok", "Pattaya", "TEST_City"]
        finally:
            # Restore
            admin.put(f"{API}/destination-cities/Thailand", json={"cities": orig})


# ---------- lead auto-derivation ----------
class TestLeadTripAutoDerive:
    _phone_counter = [7000000000]

    def _create_lead(self, sess, **extra):
        self._phone_counter[0] += 1
        payload = {
            "customer_name": "TEST Smart Form",
            "phone": str(self._phone_counter[0]),
            "destination": "Thailand",
            "travel_date": "2026-08-15",
            "duration_days": 4,
            "stay_cities": [
                {"city": "Bangkok", "nights": 2},
                {"city": "Pattaya", "nights": 1},
            ],
            "passport_available": False,
            "passport_reason": "Passport Not Applied",
            "package_type": "Family",
            "adults": 2,
            "children": 0,
        }
        payload.update(extra)
        r = sess.post(f"{API}/leads", json=payload)
        assert r.status_code in (200, 201), r.text
        return r.json()

    def test_create_computes_all_fields(self, sales):
        lead = self._create_lead(sales)
        assert lead["trip_end_date"] == "2026-08-18", lead
        assert lead["duration_label"] == "3N / 4D", lead
        assert lead["stay_summary"] == "Bangkok 2N + Pattaya 1N", lead
        assert lead["passport_available"] is False
        assert lead["passport_reason"] == "Passport Not Applied"
        # stay_cities persisted
        sc = lead.get("stay_cities") or []
        assert len(sc) == 2
        assert sc[0]["city"] == "Bangkok" and sc[0]["nights"] == 2

    def test_update_recomputes_on_duration_change(self, sales, admin):
        lead = self._create_lead(sales)
        lid = lead["lead_id"]
        r = admin.patch(f"{API}/leads/{lid}", json={"duration_days": 5})
        assert r.status_code == 200, r.text
        upd = r.json()
        assert upd["duration_label"] == "4N / 5D"
        assert upd["trip_end_date"] == "2026-08-19"
        # GET to confirm persistence
        got = admin.get(f"{API}/leads/{lid}").json()
        assert got["duration_label"] == "4N / 5D"
        assert got["trip_end_date"] == "2026-08-19"

    def test_update_recomputes_stay_summary(self, sales, admin):
        lead = self._create_lead(sales)
        lid = lead["lead_id"]
        r = admin.patch(f"{API}/leads/{lid}", json={
            "stay_cities": [{"city": "Phuket", "nights": 3}]
        })
        assert r.status_code == 200, r.text
        assert r.json()["stay_summary"] == "Phuket 3N"


# ---------- RSQ email preview ----------
class TestSupplierMailPreviewNewBlocks:
    def test_preview_contains_new_blocks(self, admin, sales):
        # ensure a fresh lead with all new fields
        import time
        phone = str(7100000000 + int(time.time()) % 899999999)
        payload = {
            "customer_name": "TEST RSQ Preview",
            "phone": phone,
            "destination": "Thailand",
            "travel_date": "2026-08-15",
            "duration_days": 4,
            "stay_cities": [
                {"city": "Bangkok", "nights": 2},
                {"city": "Pattaya", "nights": 1},
            ],
            "passport_available": False,
            "passport_reason": "Passport Not Applied",
            "package_type": "Family",
            "adults": 2, "children": 0,
        }
        r = sales.post(f"{API}/leads", json=payload)
        assert r.status_code in (200, 201), r.text
        lid = r.json()["lead_id"]

        r = admin.get(f"{API}/supplier-mail/preview/{lid}")
        assert r.status_code == 200, r.text
        body = r.json()
        # Backend may return in 'text' or 'body' field
        text = body.get("text") or body.get("body") or body.get("html") or ""
        assert text, f"empty preview: {body}"
        # New blocks
        assert "Trip End Date" in text, text[:800]
        assert "2026-08-18" in text
        assert "Duration" in text
        assert "3N / 4D" in text or "3N/4D" in text
        assert "STAY DETAILS" in text, text[:800]
        assert "Bangkok" in text and "2" in text
        assert "Pattaya" in text
        assert "PASSPORT" in text, text[:800]
        # Passport line - accept variable whitespace
        import re
        assert re.search(r"Passport\s*:\s*No", text), text[:800]
        assert "Passport Not Applied" in text
