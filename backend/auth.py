"""Auth utilities: JWT + bcrypt + password policy + session invalidation + lockout.

Session invalidation strategy: every user has an incrementing `token_version`. JWTs
embed `tv`; if it doesn't match the current user's version, the token is rejected.
Bumping token_version therefore invalidates all outstanding sessions for that user
(used on password change + admin force reset).

Account status enum: active | inactive | suspended | locked | password_reset_required
"""
import os
import re
import bcrypt
import jwt
from datetime import datetime, timezone, timedelta
from bson import ObjectId
from fastapi import HTTPException, Request, Depends

JWT_ALGORITHM = "HS256"
LOCKOUT_MAX_ATTEMPTS = 5
LOCKOUT_MINUTES = 15
ACCESS_TOKEN_HOURS = 1  # inactivity timeout — 1 hour per user setting
REFRESH_TOKEN_DAYS = 7

VALID_ACCOUNT_STATUSES = {"active", "inactive", "suspended", "locked", "password_reset_required"}


def get_jwt_secret() -> str:
    return os.environ["JWT_SECRET"]


# =================== Password Policy ===================
class PasswordPolicyError(HTTPException):
    def __init__(self, msg: str):
        super().__init__(status_code=400, detail=msg)


def validate_password_policy(password: str) -> None:
    """Raises HTTPException(400) if password fails policy. Min 8, upper/lower/digit/special."""
    if not password or len(password) < 8:
        raise PasswordPolicyError("Password must be at least 8 characters.")
    if not re.search(r"[A-Z]", password):
        raise PasswordPolicyError("Password must contain at least 1 uppercase letter.")
    if not re.search(r"[a-z]", password):
        raise PasswordPolicyError("Password must contain at least 1 lowercase letter.")
    if not re.search(r"\d", password):
        raise PasswordPolicyError("Password must contain at least 1 number.")
    if not re.search(r"[^A-Za-z0-9]", password):
        raise PasswordPolicyError("Password must contain at least 1 special character.")


def hash_password(password: str) -> str:
    salt = bcrypt.gensalt()
    return bcrypt.hashpw(password.encode("utf-8"), salt).decode("utf-8")


def verify_password(plain: str, hashed: str) -> bool:
    try:
        return bcrypt.checkpw(plain.encode("utf-8"), hashed.encode("utf-8"))
    except Exception:
        return False


# =================== JWT ===================
def create_access_token(user_id: str, email: str, role: str, tv: int = 0) -> str:
    payload = {
        "sub": user_id, "email": email, "role": role, "tv": tv,
        "exp": datetime.now(timezone.utc) + timedelta(hours=ACCESS_TOKEN_HOURS),
        "type": "access",
    }
    return jwt.encode(payload, get_jwt_secret(), algorithm=JWT_ALGORITHM)


def create_refresh_token(user_id: str, tv: int = 0) -> str:
    payload = {
        "sub": user_id, "tv": tv,
        "exp": datetime.now(timezone.utc) + timedelta(days=REFRESH_TOKEN_DAYS),
        "type": "refresh",
    }
    return jwt.encode(payload, get_jwt_secret(), algorithm=JWT_ALGORITHM)


def set_auth_cookies(response, access_token: str, refresh_token: str):
    response.set_cookie(
        key="access_token", value=access_token, httponly=True,
        secure=True, samesite="none", max_age=ACCESS_TOKEN_HOURS * 3600, path="/"
    )
    response.set_cookie(
        key="refresh_token", value=refresh_token, httponly=True,
        secure=True, samesite="none", max_age=REFRESH_TOKEN_DAYS * 86400, path="/"
    )


def clear_auth_cookies(response):
    response.delete_cookie("access_token", path="/")
    response.delete_cookie("refresh_token", path="/")


def _get_db(request: Request):
    return request.app.state.db


async def get_current_user(request: Request) -> dict:
    token = request.cookies.get("access_token")
    if not token:
        auth_header = request.headers.get("Authorization", "")
        if auth_header.startswith("Bearer "):
            token = auth_header[7:]
    if not token:
        raise HTTPException(status_code=401, detail="Not authenticated")
    try:
        payload = jwt.decode(token, get_jwt_secret(), algorithms=[JWT_ALGORITHM])
        if payload.get("type") != "access":
            raise HTTPException(status_code=401, detail="Invalid token type")
        db = _get_db(request)
        user = await db.users.find_one({"_id": ObjectId(payload["sub"])})
        if not user:
            raise HTTPException(status_code=401, detail="User not found")
        # Session invalidation via token_version mismatch
        current_tv = user.get("token_version", 0)
        if payload.get("tv", 0) != current_tv:
            raise HTTPException(status_code=401, detail="Session revoked. Please log in again.")
        # Account status enforcement
        status = user.get("account_status", "active")
        if status == "inactive":
            raise HTTPException(status_code=403, detail="Your account is inactive. Contact admin.")
        if status == "suspended":
            raise HTTPException(status_code=403, detail="Your account is suspended. Contact admin.")
        if status == "locked":
            raise HTTPException(status_code=403, detail="Your account is locked due to too many failed attempts.")
        # password_reset_required is allowed through (frontend routes to /change-password)
        user["id"] = str(user["_id"])
        del user["_id"]
        user.pop("password_hash", None)
        return user
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=401, detail="Token expired")
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=401, detail="Invalid token")


def require_roles(*roles):
    async def _dep(user: dict = Depends(get_current_user)):
        if user["role"] not in roles:
            raise HTTPException(status_code=403, detail="Forbidden: insufficient role")
        return user
    return _dep


# =================== Seeding ===================
async def seed_admin(db):
    """Seed OWNER (real admin email) + demo admin/sales/ops users. Idempotent — never rehashes existing user unless env password changed."""
    now = datetime.now(timezone.utc).isoformat()
    defaults = {"account_status": "active", "token_version": 0, "failed_login_attempts": 0,
                "locked_until": None, "last_login_at": None, "last_logout_at": None,
                "created_at": now}

    # OWNER — real developer account, always admin
    owner_email = "omnamasiviya73@gmail.com"
    owner = await db.users.find_one({"email": owner_email})
    if owner is None:
        # Placeholder password — owner should reset via Change Password on first login OR admin force reset
        # Owner is meant to use Google Sign-In primarily; email/password fallback available with Ir@2026Owner
        await db.users.insert_one({
            "email": owner_email,
            "password_hash": hash_password("Ir@2026Owner"),
            "name": "Owner",
            "role": "admin",
            "staff_code": "OWN001",
            "consultant_code": "OW",
            "active": True,
            **defaults,
        })
    else:
        # Ensure owner is admin + active regardless of past mutations
        await db.users.update_one({"email": owner_email},
            {"$set": {"role": "admin", "active": True,
                      "account_status": owner.get("account_status", "active")}})

    email = os.environ.get("ADMIN_EMAIL", "admin@indianrounds.com").lower()
    password = os.environ.get("ADMIN_PASSWORD", "admin123")
    existing = await db.users.find_one({"email": email})
    if existing is None:
        await db.users.insert_one({
            "email": email,
            "password_hash": hash_password(password),
            "name": "Admin",
            "role": "admin",
            "staff_code": "ADM001",
            "active": True,
            **defaults,
        })
    elif not verify_password(password, existing["password_hash"]):
        await db.users.update_one({"email": email}, {"$set": {"password_hash": hash_password(password)}})

    demos = [
        {"email": "sales@indianrounds.com", "password": "sales123", "name": "Ravi Kumar",
         "role": "sales", "staff_code": "SAL001", "consultant_code": "RK"},
        {"email": "ops@indianrounds.com", "password": "ops123", "name": "Priya Sharma",
         "role": "operations", "staff_code": "OPS001", "consultant_code": ""},
    ]
    for d in demos:
        e = d["email"].lower()
        exists = await db.users.find_one({"email": e})
        if exists is None:
            await db.users.insert_one({
                "email": e, "password_hash": hash_password(d["password"]),
                "name": d["name"], "role": d["role"], "staff_code": d["staff_code"],
                "consultant_code": d["consultant_code"], "active": True,
                **defaults,
            })

    # Backfill security fields on any legacy user documents
    await db.users.update_many(
        {"account_status": {"$exists": False}},
        {"$set": {"account_status": "active", "token_version": 0,
                  "failed_login_attempts": 0, "locked_until": None}},
    )
