"""Backend Auth & Security Phase 1 tests — Indian Rounds CRM.

Covers: password policy, change-password flow, force-reset, failed-login lockout,
account status enforcement, admin session invalidation, refresh, audit log,
owner account seeding.
"""
import os
import time
import pytest
import requests

BASE_URL = os.environ["REACT_APP_BACKEND_URL"].rstrip("/")
API = f"{BASE_URL}/api"


def _login(email, password, session=None):
    s = session or requests.Session()
    r = s.post(f"{API}/auth/login", json={"email": email, "password": password})
    return s, r


@pytest.fixture(scope="module")
def admin_session():
    s, r = _login("admin@indianrounds.com", "admin123")
    assert r.status_code == 200, f"admin login failed: {r.status_code} {r.text}"
    token = r.json()["access_token"]
    s.headers.update({"Authorization": f"Bearer {token}"})
    return s


@pytest.fixture(scope="module")
def user_index(admin_session):
    r = admin_session.get(f"{API}/users")
    assert r.status_code == 200
    users = r.json()
    idx = {u["email"]: u for u in users}
    return idx


# ---------- 1. Owner seeded ----------
def test_owner_seeded(user_index):
    assert "omnamasiviya73@gmail.com" in user_index
    o = user_index["omnamasiviya73@gmail.com"]
    assert o["role"] == "admin"
    assert o.get("account_status", "active") == "active"


# ---------- 2. Password Policy on change-password ----------
class TestPasswordPolicy:
    @pytest.fixture(scope="class")
    def sales_session(self):
        # Try both legacy and updated password
        s, r = _login("sales@indianrounds.com", "sales123")
        if r.status_code != 200:
            s, r = _login("sales@indianrounds.com", "Ir@2026Safe")
        assert r.status_code == 200, f"sales login failed: {r.text}"
        s.headers.update({"Authorization": f"Bearer {r.json()['access_token']}"})
        s._current_pw = "sales123" if r.request.body and b"sales123" in r.request.body else "Ir@2026Safe"
        return s

    @pytest.mark.parametrize("bad", ["weak", "password", "PASSWORD1", "Passw1!", "alllower1!", "ALLUPPER1!", "NoDigits!!", "NoSpecial1A"])
    def test_weak_rejected(self, sales_session, bad):
        r = sales_session.post(f"{API}/auth/change-password", json={
            "current_password": sales_session._current_pw,
            "new_password": bad,
            "confirm_password": bad,
        })
        assert r.status_code == 400, f"expected 400 for '{bad}', got {r.status_code} {r.text}"
        assert "password" in r.json().get("detail", "").lower()

    def test_mismatch_confirm(self, sales_session):
        r = sales_session.post(f"{API}/auth/change-password", json={
            "current_password": sales_session._current_pw,
            "new_password": "Ir@2026Safe",
            "confirm_password": "Ir@2026Diff",
        })
        assert r.status_code == 400
        assert "match" in r.json()["detail"].lower()

    def test_wrong_current(self, sales_session):
        # Only meaningful if account is not in forced-reset (which bypasses current-pw check)
        me = sales_session.get(f"{API}/auth/me").json()
        if me.get("account_status") == "password_reset_required":
            pytest.skip("Sales in forced-reset mode — current password check is bypassed by design")
        r = sales_session.post(f"{API}/auth/change-password", json={
            "current_password": "totally-wrong-xxx",
            "new_password": "Ir@2026Safe",
            "confirm_password": "Ir@2026Safe",
        })
        assert r.status_code == 401


# ---------- 3. Full change-password happy path (mutates sales user) ----------
@pytest.mark.order(after="TestPasswordPolicy")
def test_change_password_flow():
    # login sales (legacy or already-changed)
    s, r = _login("sales@indianrounds.com", "sales123")
    used_pw = "sales123"
    if r.status_code != 200:
        s, r = _login("sales@indianrounds.com", "Ir@2026Safe")
        used_pw = "Ir@2026Safe"
    assert r.status_code == 200
    token = r.json()["access_token"]
    s.headers.update({"Authorization": f"Bearer {token}"})

    new_pw = "Ir@2026Safe" if used_pw != "Ir@2026Safe" else "Ir@2027Safe"

    r = s.post(f"{API}/auth/change-password", json={
        "current_password": used_pw, "new_password": new_pw, "confirm_password": new_pw
    })
    assert r.status_code == 200, r.text
    assert "successfully" in r.json()["message"].lower()

    # Old password fails
    _, r2 = _login("sales@indianrounds.com", used_pw)
    assert r2.status_code == 401

    # New password works
    _, r3 = _login("sales@indianrounds.com", new_pw)
    assert r3.status_code == 200

    # Restore to Ir@2026Safe if we changed to Ir@2027Safe
    if new_pw != "Ir@2026Safe":
        s2 = requests.Session()
        _, rlog = _login("sales@indianrounds.com", new_pw, s2)
        s2.headers.update({"Authorization": f"Bearer {rlog.json()['access_token']}"})
        r_rev = s2.post(f"{API}/auth/change-password", json={
            "current_password": new_pw, "new_password": "Ir@2026Safe", "confirm_password": "Ir@2026Safe"
        })
        assert r_rev.status_code == 200

    # Same-as-current rejected
    s3 = requests.Session()
    _, rlog = _login("sales@indianrounds.com", "Ir@2026Safe", s3)
    s3.headers.update({"Authorization": f"Bearer {rlog.json()['access_token']}"})
    r_same = s3.post(f"{API}/auth/change-password", json={
        "current_password": "Ir@2026Safe", "new_password": "Ir@2026Safe", "confirm_password": "Ir@2026Safe"
    })
    assert r_same.status_code == 400
    assert "different" in r_same.json()["detail"].lower()


# ---------- 4. Force password reset ----------
def test_force_password_reset(admin_session, user_index):
    sales_id = user_index["sales@indianrounds.com"]["id"]

    # Get an old sales token first
    _, r = _login("sales@indianrounds.com", "Ir@2026Safe")
    assert r.status_code == 200
    old_token = r.json()["access_token"]

    # Admin force-reset
    r = admin_session.post(f"{API}/users/{sales_id}/force-reset", json={"reason": "test"})
    assert r.status_code == 200

    # Old token invalid
    r_me = requests.get(f"{API}/auth/me", headers={"Authorization": f"Bearer {old_token}"})
    assert r_me.status_code == 401

    # Users list shows password_reset_required
    r = admin_session.get(f"{API}/users")
    sales = next(u for u in r.json() if u["email"] == "sales@indianrounds.com")
    assert sales["account_status"] == "password_reset_required"

    # Sales can still log in with current password; response flags forced
    s, r = _login("sales@indianrounds.com", "Ir@2026Safe")
    assert r.status_code == 200
    data = r.json()
    assert data["password_reset_required"] is True
    s.headers.update({"Authorization": f"Bearer {data['access_token']}"})

    # Sales can change password with empty current
    r = s.post(f"{API}/auth/change-password", json={
        "current_password": "", "new_password": "Ir@2026Safe", "confirm_password": "Ir@2026Safe"
    })
    # New == current — should still succeed since forced flow re-hashes; but our impl rejects same. Use fresh pw:
    if r.status_code == 400:
        r = s.post(f"{API}/auth/change-password", json={
            "current_password": "", "new_password": "Ir@2026New!", "confirm_password": "Ir@2026New!"
        })
        assert r.status_code == 200
        # Revert to Ir@2026Safe
        s2, rl = _login("sales@indianrounds.com", "Ir@2026New!")
        s2.headers.update({"Authorization": f"Bearer {rl.json()['access_token']}"})
        rev = s2.post(f"{API}/auth/change-password", json={
            "current_password": "Ir@2026New!", "new_password": "Ir@2026Safe", "confirm_password": "Ir@2026Safe"
        })
        assert rev.status_code == 200

    # Status back to active
    r = admin_session.get(f"{API}/users")
    sales = next(u for u in r.json() if u["email"] == "sales@indianrounds.com")
    assert sales["account_status"] == "active"


# ---------- 5. Failed login lockout ----------
def test_failed_login_lockout(admin_session):
    email = "test_lockme@example.com"  # backend lowercases everything
    # Cleanup old
    r = admin_session.get(f"{API}/users")
    for u in r.json():
        if u["email"] == email:
            admin_session.delete(f"{API}/users/{u['id']}")

    # Create
    r = admin_session.post(f"{API}/users", json={
        "email": email, "password": "Test@1234abc", "name": "Lockme",
        "role": "sales", "staff_code": "LCK001", "consultant_code": "LK"
    })
    assert r.status_code == 200, r.text
    lock_id = r.json()["id"]

    try:
        # 5 failed attempts
        for i in range(5):
            _, rr = _login(email, "wrong-password")
            # 5th response should be 423
            if i < 4:
                assert rr.status_code == 401
            else:
                assert rr.status_code == 423, f"attempt {i+1}: {rr.status_code} {rr.text}"

        # 6th even with correct pw → 423
        _, rr = _login(email, "Test@1234abc")
        assert rr.status_code == 423
        assert "locked" in rr.json()["detail"].lower() or "too many" in rr.json()["detail"].lower()

        # Verify status
        r = admin_session.get(f"{API}/users")
        u = next(x for x in r.json() if x["email"] == email)
        assert u["account_status"] == "locked"

        # Admin unlock
        r = admin_session.post(f"{API}/users/{lock_id}/unlock")
        assert r.status_code == 200

        # Correct login works
        _, rr = _login(email, "Test@1234abc")
        assert rr.status_code == 200
    finally:
        admin_session.delete(f"{API}/users/{lock_id}")


# ---------- 6. Account status enforcement ----------
def test_status_suspended_and_inactive(admin_session, user_index):
    ops_id = user_index["ops@indianrounds.com"]["id"]

    # Get ops token first (before suspend)
    _, r = _login("ops@indianrounds.com", "ops123")
    assert r.status_code == 200
    ops_token = r.json()["access_token"]

    try:
        # Suspend
        r = admin_session.post(f"{API}/users/{ops_id}/status", json={"account_status": "suspended"})
        assert r.status_code == 200

        # Login blocked with 403
        _, r = _login("ops@indianrounds.com", "ops123")
        assert r.status_code == 403
        assert "suspended" in r.json()["detail"].lower()

        # Old token invalidated (tv bumped) → 401 or 403
        r_me = requests.get(f"{API}/auth/me", headers={"Authorization": f"Bearer {ops_token}"})
        assert r_me.status_code in (401, 403)

        # Inactive
        r = admin_session.post(f"{API}/users/{ops_id}/status", json={"account_status": "inactive"})
        assert r.status_code == 200
        _, r = _login("ops@indianrounds.com", "ops123")
        assert r.status_code == 403
        assert "inactive" in r.json()["detail"].lower()

        # Invalid status rejected
        r = admin_session.post(f"{API}/users/{ops_id}/status", json={"account_status": "bogus"})
        assert r.status_code == 400
    finally:
        # Restore
        admin_session.post(f"{API}/users/{ops_id}/status", json={"account_status": "active"})
        _, r = _login("ops@indianrounds.com", "ops123")
        assert r.status_code == 200


# ---------- 7. Admin logout user (session termination) ----------
def test_admin_logout_user(admin_session, user_index):
    ops_id = user_index["ops@indianrounds.com"]["id"]
    _, r = _login("ops@indianrounds.com", "ops123")
    assert r.status_code == 200
    ops_token = r.json()["access_token"]

    # verify token works
    r = requests.get(f"{API}/auth/me", headers={"Authorization": f"Bearer {ops_token}"})
    assert r.status_code == 200

    # admin terminates
    r = admin_session.post(f"{API}/users/{ops_id}/logout")
    assert r.status_code == 200

    # token invalid
    r = requests.get(f"{API}/auth/me", headers={"Authorization": f"Bearer {ops_token}"})
    assert r.status_code == 401

    # ops can re-login
    _, r = _login("ops@indianrounds.com", "ops123")
    assert r.status_code == 200


# ---------- 8. Refresh session ----------
def test_refresh_session():
    s, r = _login("sales@indianrounds.com", "Ir@2026Safe")
    assert r.status_code == 200
    assert s.cookies.get("refresh_token")
    r2 = s.post(f"{API}/auth/refresh")
    assert r2.status_code == 200, r2.text
    assert r2.json().get("success") is True
    # cookies should be refreshed
    assert s.cookies.get("access_token")


# ---------- 9. Security audit log ----------
def test_security_audit_log(admin_session):
    r = admin_session.get(f"{API}/security-audit-log", params={"limit": 100})
    assert r.status_code == 200
    events = r.json()
    assert isinstance(events, list)
    assert len(events) > 0
    kinds = {e.get("event") for e in events}
    # We expect at least these to have appeared during this test run
    expected_any = {"login_success", "login_failed", "login_locked", "admin_force_reset",
                    "admin_status_change", "admin_session_terminated", "admin_unlock", "password_changed"}
    intersection = kinds & expected_any
    assert len(intersection) >= 5, f"Expected multiple audit events; got kinds={kinds}"
    # Structure check
    e0 = events[0]
    for f in ("user_email", "event", "timestamp"):
        assert f in e0, f"missing {f} in {e0}"


# ---------- 10. Non-admin cannot access admin endpoints ----------
def test_non_admin_forbidden():
    s, r = _login("sales@indianrounds.com", "Ir@2026Safe")
    assert r.status_code == 200
    s.headers.update({"Authorization": f"Bearer {r.json()['access_token']}"})
    r = s.get(f"{API}/security-audit-log")
    assert r.status_code == 403
    r = s.get(f"{API}/users")
    assert r.status_code == 403
