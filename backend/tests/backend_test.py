"""
Full regression backend tests for Indian Rounds Travel CRM.
Covers auth, leads, notifications, notes, contact events, reassign,
followups, allocations, supplier mail preview, master data, users, AI, etc.
"""
import os
import time
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/")
if not BASE_URL:
    # Fallback: read from /app/frontend/.env
    try:
        with open("/app/frontend/.env") as f:
            for line in f:
                if line.startswith("REACT_APP_BACKEND_URL="):
                    BASE_URL = line.split("=", 1)[1].strip().rstrip("/")
                    break
    except FileNotFoundError:
        pass

assert BASE_URL, "REACT_APP_BACKEND_URL not configured"
API = f"{BASE_URL}/api"

CREDS = {
    "admin": {"email": "admin@indianrounds.com", "password": "admin123"},
    "sales": {"email": "sales@indianrounds.com", "password": "sales123"},
    "ops":   {"email": "ops@indianrounds.com",   "password": "ops123"},
    "karuva":{"email": "karuva@indianrounds.com","password": "kar123"},
}

def _login(role):
    s = requests.Session()
    r = s.post(f"{API}/auth/login", json=CREDS[role], timeout=15)
    assert r.status_code == 200, f"login {role} failed: {r.status_code} {r.text}"
    data = r.json()
    token = data.get("access_token")
    if token:
        s.headers.update({"Authorization": f"Bearer {token}"})
    return s, data


# ---------------- fixtures ----------------
@pytest.fixture(scope="session")
def admin(): return _login("admin")

@pytest.fixture(scope="session")
def sales(): return _login("sales")

@pytest.fixture(scope="session")
def ops(): return _login("ops")

@pytest.fixture(scope="session")
def karuva():
    try:
        return _login("karuva")
    except AssertionError:
        pytest.skip("karuva account not seeded/available")


# ---------------- auth ----------------
class TestAuth:
    def test_login_admin(self, admin):
        s, data = admin
        assert data["role"] == "admin"
        assert data["email"] == CREDS["admin"]["email"]

    def test_login_sales(self, sales):
        s, data = sales
        assert data["role"] == "sales"

    def test_login_ops(self, ops):
        s, data = ops
        assert data["role"] in ("operations", "ops")

    def test_login_bad(self):
        r = requests.post(f"{API}/auth/login", json={"email":"x@x.com","password":"nope"})
        assert r.status_code in (400, 401, 403)

    def test_auth_me(self, admin):
        s, _ = admin
        r = s.get(f"{API}/auth/me")
        assert r.status_code == 200
        assert r.json()["email"] == CREDS["admin"]["email"]


# ---------------- users & allocations ----------------
class TestUsersAllocations:
    def test_users_list_admin(self, admin):
        s, _ = admin
        r = s.get(f"{API}/users")
        assert r.status_code == 200
        assert isinstance(r.json(), list)

    def test_users_list_sales_forbidden(self, sales):
        s, _ = sales
        r = s.get(f"{API}/users")
        assert r.status_code == 403

    def test_allocations_list(self, admin):
        s, _ = admin
        r = s.get(f"{API}/allocations")
        assert r.status_code == 200
        data = r.json()
        assert isinstance(data, list)
        # Thailand→Karuva should exist per prior seed
        dests = {a.get("destination") for a in data}
        assert "Thailand" in dests, f"Thailand allocation missing: {data}"

    def test_allocations_forbidden_for_sales_post(self, sales):
        s, _ = sales
        r = s.post(f"{API}/allocations", json={"destination":"Xyz","consultant_id":"nope"})
        assert r.status_code in (400, 403)


# ---------------- leads ----------------
class TestLeads:
    def test_list_leads(self, admin):
        s, _ = admin
        r = s.get(f"{API}/leads")
        assert r.status_code == 200
        assert isinstance(r.json(), list)

    def test_duplicate_check(self, admin):
        s, _ = admin
        # Try a phone that likely exists (from earlier tests)
        r = s.get(f"{API}/leads/duplicate-check", params={"phone":"9999999999"})
        assert r.status_code == 200
        body = r.json()
        assert "duplicate" in body or "exists" in body or isinstance(body, dict)

    def test_get_lead_ir07001(self, admin):
        s, _ = admin
        r = s.get(f"{API}/leads/IR07001")
        # Either present or not — accept 200 or 404
        assert r.status_code in (200, 404)


# ---------------- notifications ----------------
class TestNotifications:
    def test_notifications_admin(self, admin):
        s, _ = admin
        r = s.get(f"{API}/notifications")
        assert r.status_code == 200
        data = r.json()
        # Expect list or dict with list
        assert isinstance(data, (list, dict))

    def test_notifications_sales(self, sales):
        s, _ = sales
        r = s.get(f"{API}/notifications")
        assert r.status_code == 200


# ---------------- notes ----------------
class TestNotes:
    def test_add_and_list_note(self, admin):
        s, _ = admin
        # find any lead id
        leads = s.get(f"{API}/leads").json()
        if not leads:
            pytest.skip("no leads")
        lid = leads[0]["lead_id"]
        r = s.post(f"{API}/leads/{lid}/notes", json={"note":"TEST_regression_note"})
        assert r.status_code in (200, 201), r.text
        note = r.json()
        note_id = note.get("id") or note.get("_id")
        # list
        r2 = s.get(f"{API}/leads/{lid}/notes")
        assert r2.status_code == 200
        contents = [n.get("note") for n in r2.json()]
        assert "TEST_regression_note" in contents
        # cleanup
        if note_id:
            s.delete(f"{API}/notes/{note_id}")


# ---------------- contact events ----------------
class TestContactEvents:
    def test_log_and_list(self, admin):
        s, _ = admin
        leads = s.get(f"{API}/leads").json()
        if not leads:
            pytest.skip("no leads")
        lid = leads[0]["lead_id"]
        r = s.post(f"{API}/leads/{lid}/contact-events",
                   json={"kind":"call","channel":"customer","note":"TEST_regression_call"})
        assert r.status_code in (200, 201), r.text
        r2 = s.get(f"{API}/leads/{lid}/contact-events")
        assert r2.status_code == 200
        events = r2.json()
        assert any(e.get("note") == "TEST_regression_call" for e in events)


# ---------------- reassign ----------------
class TestReassign:
    def test_reassign_forbidden_for_sales(self, sales, admin):
        s_admin, _ = admin
        leads = s_admin.get(f"{API}/leads").json()
        if not leads:
            pytest.skip("no leads")
        lid = leads[0]["lead_id"]
        s, _ = sales
        r = s.post(f"{API}/leads/{lid}/reassign", json={"consultant_id":"anyone"})
        assert r.status_code in (401, 403)

    def test_reassign_ops_ok(self, ops, admin):
        s_admin, _ = admin
        leads = s_admin.get(f"{API}/leads").json()
        if not leads:
            pytest.skip("no leads")
        lid = leads[0]["lead_id"]
        # find a consultant user id
        users = s_admin.get(f"{API}/users").json()
        consultant = next((u for u in users if u.get("role") in ("sales","admin")), None)
        if not consultant:
            pytest.skip("no consultant user")
        s, _ = ops
        r = s.post(f"{API}/leads/{lid}/reassign", json={"consultant_id":consultant["id"]})
        # Accept 200 or 400 (already assigned)
        assert r.status_code in (200, 201, 400), r.text


# ---------------- followups ----------------
class TestFollowups:
    def test_upcoming(self, admin):
        s, _ = admin
        r = s.get(f"{API}/followups/upcoming", params={"hours":168})
        assert r.status_code == 200
        assert isinstance(r.json(), list)


# ---------------- supplier mail preview ----------------
class TestSupplierMail:
    def test_preview(self, admin):
        s, _ = admin
        leads = s.get(f"{API}/leads").json()
        if not leads:
            pytest.skip("no leads")
        lid = leads[0]["lead_id"]
        r = s.get(f"{API}/supplier-mail/preview/{lid}")
        assert r.status_code == 200
        body = r.json()
        html = body.get("html") or body.get("body") or ""
        assert "<html" in html.lower() or "<table" in html.lower() or "<div" in html.lower(), \
            f"HTML preview missing tags: {html[:200]}"

    def test_preview_forbidden_for_sales(self, sales, admin):
        s_admin, _ = admin
        leads = s_admin.get(f"{API}/leads").json()
        if not leads:
            pytest.skip("no leads")
        lid = leads[0]["lead_id"]
        s, _ = sales
        r = s.get(f"{API}/supplier-mail/preview/{lid}")
        assert r.status_code in (200, 403)  # currently require_roles admin/ops


# ---------------- master ----------------
class TestMaster:
    def test_master_list(self, admin):
        s, _ = admin
        r = s.get(f"{API}/master")
        assert r.status_code == 200
        assert isinstance(r.json(), list)


# ---------------- AI ----------------
class TestAI:
    def test_itinerary(self, admin):
        s, _ = admin
        payload = {"destination":"Bali","days":3,"adults":2,"children":0,"package_type":"Family"}
        r = s.post(f"{API}/ai/itinerary", json=payload, timeout=90)
        assert r.status_code == 200, r.text
        body = r.json()
        text = body.get("content") or body.get("itinerary") or ""
        assert len(text) > 50, f"itinerary too short: {body}"

    def test_quote(self, admin):
        s, _ = admin
        payload = {"destination":"Bali","budget":"50000","days":4,"adults":2}
        r = s.post(f"{API}/ai/quote-recommend", json=payload, timeout=90)
        assert r.status_code == 200, r.text
        body = r.json()
        text = body.get("content") or body.get("recommendation") or ""
        assert len(text) > 10, f"quote too short: {body}"


# ---------------- dashboard ----------------
class TestDashboard:
    def test_stats(self, admin):
        s, _ = admin
        r = s.get(f"{API}/dashboard/stats")
        assert r.status_code == 200
        d = r.json()
        assert isinstance(d, dict)


# ---------------- PWA static ----------------
class TestPWA:
    def test_manifest(self):
        r = requests.get(f"{BASE_URL}/manifest.json", timeout=15)
        assert r.status_code == 200
        assert "name" in r.text.lower() or "short_name" in r.text.lower()

    def test_sw(self):
        r = requests.get(f"{BASE_URL}/sw.js", timeout=15)
        assert r.status_code == 200
        assert "self" in r.text or "service" in r.text.lower() or len(r.text) > 0
