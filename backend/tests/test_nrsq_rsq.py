"""
Backend tests for NRSQ (No Requirement Supplier Quotation) and RSQ workflow
on the Lead Detail page. Covers:
 - PATCH /api/leads/{id} accepts quote_type / package_selected / selling_amount
   and persists them (verified via GET)
 - Sales user PATCH allow-list also includes quote_type + package_selected
"""
import os
import time
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/")
if not BASE_URL:
    try:
        with open("/app/frontend/.env") as f:
            for line in f:
                if line.startswith("REACT_APP_BACKEND_URL="):
                    BASE_URL = line.split("=", 1)[1].strip().rstrip("/")
                    break
    except FileNotFoundError:
        pass
API = f"{BASE_URL}/api"

CREDS = {
    "admin": {"email": "admin@indianrounds.com", "password": "admin123"},
    "sales": {"email": "sales@indianrounds.com", "password": "sales123"},
    "ops":   {"email": "ops@indianrounds.com",   "password": "ops123"},
}


def _login(role):
    s = requests.Session()
    r = s.post(f"{API}/auth/login", json=CREDS[role], timeout=15)
    assert r.status_code == 200, f"login {role} failed: {r.status_code} {r.text}"
    tok = r.json().get("access_token")
    if tok:
        s.headers.update({"Authorization": f"Bearer {tok}"})
    return s, r.json()


@pytest.fixture(scope="module")
def ops():
    return _login("ops")


@pytest.fixture(scope="module")
def sales():
    return _login("sales")


@pytest.fixture(scope="module")
def fresh_lead_id_ops(ops):
    """Find a fresh lead (no quote_type) visible to ops."""
    s, _ = ops
    r = s.get(f"{API}/leads?limit=500", timeout=15)
    assert r.status_code == 200
    for l in r.json():
        if not l.get("quote_type"):
            return l["lead_id"]
    pytest.skip("no fresh lead (no quote_type) found for ops")


@pytest.fixture(scope="module")
def fresh_lead_id_sales(sales):
    """Find a fresh lead (no quote_type) owned by sales user."""
    s, _ = sales
    r = s.get(f"{API}/leads?limit=500", timeout=15)
    assert r.status_code == 200
    for l in r.json():
        if not l.get("quote_type"):
            return l["lead_id"]
    pytest.skip("no fresh lead owned by sales user")


# ---------------- NRSQ persistence tests ----------------
class TestNRSQPersistence:
    def test_ops_patch_nrsq_persists(self, ops, fresh_lead_id_ops):
        s, _ = ops
        lid = fresh_lead_id_ops
        payload = {
            "quote_type": "nrsq",
            "package_selected": "TEST NRSQ Bali 5N/6D Villa AP",
            "selling_amount": 91234,
            "quote_sent_to_customer": True,
            "quote_sent_date": "2026-01-15T09:00:00+00:00",
            "lead_status": "Quote Sent to Customer",
        }
        r = s.patch(f"{API}/leads/{lid}", json=payload, timeout=15)
        assert r.status_code == 200, r.text
        body = r.json()
        assert body["quote_type"] == "nrsq"
        assert body["package_selected"] == payload["package_selected"]
        assert body["selling_amount"] == 91234
        assert body["quote_sent_to_customer"] is True
        assert body["lead_status"] == "Quote Sent to Customer"

        # GET to confirm persistence
        g = s.get(f"{API}/leads/{lid}", timeout=15)
        assert g.status_code == 200
        gd = g.json()
        assert gd["quote_type"] == "nrsq"
        assert gd["package_selected"] == payload["package_selected"]
        assert gd["selling_amount"] == 91234
        assert gd["lead_status"] == "Quote Sent to Customer"
        assert gd["quote_sent_to_customer"] is True


# ---------------- RSQ persistence tests ----------------
class TestRSQPersistence:
    def test_ops_patch_quote_type_rsq_only(self, ops):
        s, _ = ops
        # pick a fresh lead
        r = s.get(f"{API}/leads?limit=500", timeout=15)
        assert r.status_code == 200
        target = None
        for l in r.json():
            if not l.get("quote_type"):
                target = l["lead_id"]; break
        if not target:
            pytest.skip("no fresh lead available for RSQ test")

        # openMail() only sends {quote_type: 'rsq'} — simulate that
        r = s.patch(f"{API}/leads/{target}", json={"quote_type": "rsq"}, timeout=15)
        assert r.status_code == 200, r.text
        assert r.json()["quote_type"] == "rsq"

        g = s.get(f"{API}/leads/{target}", timeout=15)
        assert g.status_code == 200
        assert g.json()["quote_type"] == "rsq"


# ---------------- Sales-role field allow-list ----------------
class TestSalesAllowList:
    def test_sales_can_patch_quote_type_nrsq(self, sales, fresh_lead_id_sales):
        s, _ = sales
        lid = fresh_lead_id_sales
        payload = {
            "quote_type": "nrsq",
            "package_selected": "TEST Sales NRSQ Package",
            "selling_amount": 55000,
            "quote_sent_to_customer": True,
            "quote_sent_date": "2026-01-15T09:00:00+00:00",
            "lead_status": "Quote Sent to Customer",
        }
        r = s.patch(f"{API}/leads/{lid}", json=payload, timeout=15)
        assert r.status_code == 200, r.text
        body = r.json()
        # These fields ARE in the sales allow-list per server.py line 597
        assert body["quote_type"] == "nrsq"
        assert body["package_selected"] == payload["package_selected"]
        assert body["selling_amount"] == 55000
        assert body["lead_status"] == "Quote Sent to Customer"


# ---------------- Model contract ----------------
class TestLeadFieldsAvailable:
    def test_fresh_lead_has_no_quote_type_or_empty(self, ops):
        s, _ = ops
        r = s.get(f"{API}/leads?limit=500", timeout=15)
        assert r.status_code == 200
        fresh = [l for l in r.json() if not l.get("quote_type")]
        assert len(fresh) >= 1, "expected at least one fresh lead in the system for testing"
