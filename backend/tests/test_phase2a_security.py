"""Phase 2A Security tests — Forgot Password, Reset, Sessions/jti, Security Audit Log."""
import os
import pytest
import requests
from pymongo import MongoClient
from bson import ObjectId

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "https://journey-crm-hub-1.preview.emergentagent.com").rstrip("/")
API = f"{BASE_URL}/api"

MONGO_URL = os.environ.get("MONGO_URL", "mongodb://localhost:27017")
DB_NAME = os.environ.get("DB_NAME", "test_database")

ADMIN = ("admin@indianrounds.com", "admin123")
SALES = ("sales@indianrounds.com", "Ir@2026Safe")
OPS = ("ops@indianrounds.com", "ops123")

EPHEMERAL_EMAIL = "tempreset@test.com"
EPHEMERAL_PW = "Ir@2026Temp!"


@pytest.fixture(scope="session")
def mongo():
    return MongoClient(MONGO_URL)[DB_NAME]


def _login(email, password):
    r = requests.post(f"{API}/auth/login", json={"email": email, "password": password})
    assert r.status_code == 200, r.text
    return r.json()["access_token"], r.cookies


def _bearer(tok):
    return {"Authorization": f"Bearer {tok}"}


def _decode_jti(token):
    import jwt
    # We just need jti; no signature verification here
    return jwt.decode(token, options={"verify_signature": False}).get("jti")


# =================== FORGOT PASSWORD - no enumeration ===================
class TestForgotPassword:
    def test_forgot_nonexistent_returns_generic_200(self):
        r = requests.post(f"{API}/auth/forgot-password", json={"email": "noexist@nowhere.com"})
        assert r.status_code == 200
        assert r.json().get("success") is True
        assert "reset link" in r.json().get("message", "").lower()

    def test_forgot_real_email_returns_identical_generic(self):
        r = requests.post(f"{API}/auth/forgot-password", json={"email": ADMIN[0]})
        assert r.status_code == 200
        assert r.json().get("success") is True
        assert "reset link" in r.json().get("message", "").lower()


# =================== RESET TOKEN LIFECYCLE ===================
class TestResetTokenLifecycle:
    @pytest.fixture(scope="class")
    def eph_user(self, mongo):
        # cleanup prior
        mongo.users.delete_many({"email": EPHEMERAL_EMAIL})
        mongo.password_reset_tokens.delete_many({"email": EPHEMERAL_EMAIL})
        # Create via admin
        admin_tok, _ = _login(*ADMIN)
        r = requests.post(f"{API}/users", headers=_bearer(admin_tok), json={
            "email": EPHEMERAL_EMAIL, "password": EPHEMERAL_PW,
            "name": "Temp Reset", "role": "sales", "staff_code": "TMP001"
        })
        assert r.status_code == 200, r.text
        yield r.json()
        mongo.users.delete_many({"email": EPHEMERAL_EMAIL})
        mongo.password_reset_tokens.delete_many({"email": EPHEMERAL_EMAIL})
        mongo.sessions.delete_many({"user_id": r.json()["id"]})

    def test_verify_invalid_token_404(self, eph_user):
        r = requests.get(f"{API}/auth/verify-reset-token/completely_bogus_token_xyz")
        assert r.status_code == 404

    def test_full_reset_flow(self, mongo, eph_user):
        # Login the ephemeral user first to create a session
        old_tok, _ = _login(EPHEMERAL_EMAIL, EPHEMERAL_PW)
        me = requests.get(f"{API}/auth/me", headers=_bearer(old_tok))
        assert me.status_code == 200

        # Trigger forgot-password
        r = requests.post(f"{API}/auth/forgot-password", json={"email": EPHEMERAL_EMAIL})
        assert r.status_code == 200

        # Read token from Mongo
        doc = mongo.password_reset_tokens.find_one({"email": EPHEMERAL_EMAIL, "used": False},
                                                    sort=[("created_at", -1)])
        assert doc is not None, "Reset token not created in Mongo"
        token = doc["token"]

        # Verify valid token
        v = requests.get(f"{API}/auth/verify-reset-token/{token}")
        assert v.status_code == 200
        assert v.json()["email"] == EPHEMERAL_EMAIL
        assert v.json()["valid"] is True

        # Weak password → 400
        weak = requests.post(f"{API}/auth/reset-password", json={
            "token": token, "new_password": "weak", "confirm_password": "weak"})
        assert weak.status_code == 400

        # Strong password → 200
        new_pw = "Reset@2026Test"
        ok = requests.post(f"{API}/auth/reset-password", json={
            "token": token, "new_password": new_pw, "confirm_password": new_pw})
        assert ok.status_code == 200, ok.text

        # Old bearer token → 401
        me2 = requests.get(f"{API}/auth/me", headers=_bearer(old_tok))
        assert me2.status_code == 401, me2.text

        # Old sessions revoked with reason=password_reset
        eph_uid = eph_user["id"]
        sessions = list(mongo.sessions.find({"user_id": eph_uid}))
        old_sessions = [s for s in sessions if s.get("revoke_reason") == "password_reset"]
        assert len(old_sessions) >= 1

        # Token reuse → 410
        reuse = requests.post(f"{API}/auth/reset-password", json={
            "token": token, "new_password": new_pw, "confirm_password": new_pw})
        assert reuse.status_code == 410

        # Verify after used → 410
        v2 = requests.get(f"{API}/auth/verify-reset-token/{token}")
        assert v2.status_code == 410

        # Login with new password works
        new_tok, _ = _login(EPHEMERAL_EMAIL, new_pw)
        assert new_tok


# =================== SESSIONS - jti tracking ===================
class TestSessions:
    def test_login_creates_session_with_jti(self):
        tok, _ = _login(*SALES)
        r = requests.get(f"{API}/sessions/mine", headers=_bearer(tok))
        assert r.status_code == 200
        rows = r.json()
        assert len(rows) >= 1
        row = next((x for x in rows if x.get("is_current")), None)
        assert row is not None, "No current session found"
        for k in ("jti", "device", "ip", "created_at", "last_active_at"):
            assert k in row
        assert row["revoked"] is False
        # exactly one is_current
        assert sum(1 for x in rows if x.get("is_current")) == 1

    def test_user_revokes_non_current_session(self):
        tok1, _ = _login(*SALES)
        tok2, _ = _login(*SALES)  # second session
        # Use tok2 as current, revoke tok1's jti
        jti1 = _decode_jti(tok1)
        r = requests.post(f"{API}/sessions/{jti1}/revoke", headers=_bearer(tok2))
        assert r.status_code == 200
        # tok1 now → 401 on /auth/me
        me = requests.get(f"{API}/auth/me", headers=_bearer(tok1))
        assert me.status_code == 401
        assert "signed out" in me.json().get("detail", "").lower() or "revoked" in me.json().get("detail", "").lower()

    def test_user_cannot_revoke_others_session(self):
        sales_tok, _ = _login(*SALES)
        admin_tok, _ = _login(*ADMIN)
        admin_jti = _decode_jti(admin_tok)
        r = requests.post(f"{API}/sessions/{admin_jti}/revoke", headers=_bearer(sales_tok))
        assert r.status_code == 403

    def test_admin_can_revoke_any_session(self):
        sales_tok, _ = _login(*SALES)
        admin_tok, _ = _login(*ADMIN)
        sales_jti = _decode_jti(sales_tok)
        r = requests.post(f"{API}/sessions/{sales_jti}/revoke", headers=_bearer(admin_tok))
        assert r.status_code == 200
        me = requests.get(f"{API}/auth/me", headers=_bearer(sales_tok))
        assert me.status_code == 401

    def test_admin_list_sessions(self):
        admin_tok, _ = _login(*ADMIN)
        sales_tok, _ = _login(*SALES)
        # Get sales uid
        salesme = requests.get(f"{API}/auth/me", headers=_bearer(sales_tok)).json()
        sales_uid = salesme["id"]

        r = requests.get(f"{API}/sessions", headers=_bearer(admin_tok))
        assert r.status_code == 200
        rows = r.json()
        assert len(rows) >= 1
        assert all("email" in row for row in rows)

        # Filter by user_id
        r2 = requests.get(f"{API}/sessions", headers=_bearer(admin_tok),
                          params={"user_id": sales_uid})
        assert r2.status_code == 200
        assert all(row.get("user_id") == sales_uid for row in r2.json())

        # active_only=false includes revoked
        r3 = requests.get(f"{API}/sessions", headers=_bearer(admin_tok),
                          params={"active_only": "false"})
        assert r3.status_code == 200


# =================== REFRESH rotates jti ===================
class TestRefresh:
    def test_refresh_rotates_jti(self, mongo):
        s = requests.Session()
        r = s.post(f"{API}/auth/login", json={"email": SALES[0], "password": SALES[1]})
        assert r.status_code == 200
        old_tok = r.json()["access_token"]
        old_jti = _decode_jti(old_tok)

        # Refresh via cookie
        rf = s.post(f"{API}/auth/refresh")
        assert rf.status_code == 200

        # Old jti session should be revoked with reason=rotated_on_refresh
        old_row = mongo.sessions.find_one({"jti": old_jti})
        assert old_row is not None
        assert old_row.get("revoked") is True
        assert old_row.get("revoke_reason") == "rotated_on_refresh"

        # Old bearer token → 401
        me = requests.get(f"{API}/auth/me", headers=_bearer(old_tok))
        assert me.status_code == 401


# =================== LOGOUT revokes ===================
class TestLogout:
    def test_logout_revokes_session(self, mongo):
        tok, _ = _login(*SALES)
        jti = _decode_jti(tok)
        r = requests.post(f"{API}/auth/logout", headers=_bearer(tok))
        assert r.status_code == 200
        row = mongo.sessions.find_one({"jti": jti})
        assert row["revoked"] is True
        assert row["revoke_reason"] == "user_logout"
        me = requests.get(f"{API}/auth/me", headers=_bearer(tok))
        assert me.status_code == 401


# =================== Admin kick user ===================
class TestAdminLogoutUser:
    def test_admin_logout_user_revokes_all(self, mongo):
        # Ensure ops can login (may be locked from a prev test)
        admin_tok, _ = _login(*ADMIN)
        # unlock ops just in case
        ops_login = requests.post(f"{API}/auth/login", json={"email": OPS[0], "password": OPS[1]})
        if ops_login.status_code != 200:
            # find ops uid + unlock
            me_admin = requests.get(f"{API}/users", headers=_bearer(admin_tok)).json()
            ops = next((u for u in me_admin if u["email"] == OPS[0]), None)
            if ops:
                requests.post(f"{API}/users/{ops['id']}/unlock", headers=_bearer(admin_tok))
            ops_login = requests.post(f"{API}/auth/login", json={"email": OPS[0], "password": OPS[1]})
        assert ops_login.status_code == 200
        ops_tok = ops_login.json()["access_token"]
        ops_uid = ops_login.json()["id"]

        r = requests.post(f"{API}/users/{ops_uid}/logout", headers=_bearer(admin_tok))
        assert r.status_code == 200
        # All ops sessions revoked with admin_kick_all
        rows = list(mongo.sessions.find({"user_id": ops_uid, "revoke_reason": "admin_kick_all"}))
        assert len(rows) >= 1
        me = requests.get(f"{API}/auth/me", headers=_bearer(ops_tok))
        assert me.status_code == 401


# =================== SECURITY AUDIT LOG ===================
class TestSecurityAuditLog:
    def test_admin_can_view(self):
        admin_tok, _ = _login(*ADMIN)
        r = requests.get(f"{API}/security-audit-log", headers=_bearer(admin_tok),
                         params={"event": "login_success"})
        assert r.status_code == 200
        rows = r.json()
        assert all(row.get("event") == "login_success" for row in rows)

    def test_filter_by_user_email(self):
        admin_tok, _ = _login(*ADMIN)
        r = requests.get(f"{API}/security-audit-log", headers=_bearer(admin_tok),
                         params={"user_email": ADMIN[0]})
        assert r.status_code == 200
        rows = r.json()
        assert all(row.get("user_email") == ADMIN[0] for row in rows)

    def test_password_reset_events_logged(self):
        admin_tok, _ = _login(*ADMIN)
        r = requests.get(f"{API}/security-audit-log", headers=_bearer(admin_tok),
                         params={"event": "password_reset_completed"})
        assert r.status_code == 200
        # At least one from the reset flow test above
        assert len(r.json()) >= 1

    def test_sales_forbidden(self):
        sales_tok, _ = _login(*SALES)
        r = requests.get(f"{API}/security-audit-log", headers=_bearer(sales_tok))
        assert r.status_code == 403
