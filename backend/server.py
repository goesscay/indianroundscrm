"""Indian Rounds Travel CRM - Main FastAPI application."""
from dotenv import load_dotenv
from pathlib import Path
load_dotenv(Path(__file__).parent / '.env')

import os
import logging
from datetime import datetime, timezone
from typing import Optional, List
from bson import ObjectId

from fastapi import FastAPI, APIRouter, HTTPException, Depends, Request, Response, Query
from starlette.middleware.cors import CORSMiddleware
from motor.motor_asyncio import AsyncIOMotorClient
from pydantic import BaseModel, EmailStr, Field, field_validator, model_validator

from auth import (
    hash_password, verify_password, create_access_token, create_refresh_token,
    set_auth_cookies, clear_auth_cookies, get_current_user, require_roles, seed_admin
)
from ai_service import generate_itinerary, recommend_quote
from email_service import send_email as send_email_service, render_supplier_rfq_html

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("ir-crm")

app = FastAPI(title="Indian Rounds CRM")
mongo_client = AsyncIOMotorClient(os.environ["MONGO_URL"])
db = mongo_client[os.environ["DB_NAME"]]
app.state.db = db

frontend_url = os.environ.get("FRONTEND_URL", "http://localhost:3000")
app.add_middleware(
    CORSMiddleware,
    allow_origins=[frontend_url, "http://localhost:3000"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

api = APIRouter(prefix="/api")


# =================== Helpers ===================
def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def oid(id_str: str) -> ObjectId:
    try:
        return ObjectId(id_str)
    except Exception:
        raise HTTPException(status_code=400, detail="Invalid ID format")


def clean_doc(doc: dict) -> dict:
    if not doc:
        return doc
    doc["id"] = str(doc["_id"])
    del doc["_id"]
    doc.pop("password_hash", None)
    return doc


async def log_activity(user: dict, action: str, entity: str, entity_id: str = "",
                       old_value: Optional[dict] = None, new_value: Optional[dict] = None):
    await db.activity_log.insert_one({
        "user_id": user["id"], "user_email": user["email"], "user_name": user.get("name"),
        "action": action, "entity": entity, "entity_id": entity_id,
        "old_value": old_value, "new_value": new_value,
        "timestamp": now_iso(),
    })


async def generate_lead_id(consultant_code: str = "") -> str:
    """IR + <2-letter consultant code> + MM + 3-digit running number (per consultant per month)."""
    now = datetime.now(timezone.utc)
    month = f"{now.month:02d}"
    year = now.year
    cc = (consultant_code or "XX").upper()[:2]
    prefix = f"IR{cc}{month}"
    counter_key = f"lead_seq_{cc}_{year}_{month}"
    result = await db.counters.find_one_and_update(
        {"_id": counter_key},
        {"$inc": {"seq": 1}},
        upsert=True,
        return_document=True,
    )
    seq = result["seq"] if result else 1
    return f"{prefix}{seq:03d}"


# =================== Models ===================
class LoginBody(BaseModel):
    email: EmailStr
    password: str


class UserCreate(BaseModel):
    email: EmailStr
    password: str
    name: str
    role: str  # 'admin' | 'sales' | 'operations'
    staff_code: str = ""
    consultant_code: str = ""  # 2-letter code used in lead IDs (KA, ME, SU)


class UserUpdate(BaseModel):
    name: Optional[str] = None
    role: Optional[str] = None
    staff_code: Optional[str] = None
    consultant_code: Optional[str] = None
    active: Optional[bool] = None
    password: Optional[str] = None


class DestinationAllocation(BaseModel):
    destination: str
    consultant_id: str


class LeadCreate(BaseModel):
    customer_name: str
    phone: str
    email: str = ""
    lead_date: str = ""  # ISO date
    travel_date: str = ""
    destination: str
    hotel_category: str = ""
    meal_plan: str = ""
    package_type: str = ""
    budget: str = ""
    adults: int = 1
    children: int = 0
    child_ages: str = ""
    rooms: int = 1
    room_type: str = ""
    departure_from: str = ""
    special_requests: str = ""
    lead_source: str = ""

    @field_validator("phone")
    @classmethod
    def _phone_10_digit(cls, v):
        digits = "".join(c for c in (v or "") if c.isdigit())
        # Strip leading 91 country code if 12 digits
        if len(digits) == 12 and digits.startswith("91"): digits = digits[2:]
        if len(digits) == 11 and digits.startswith("0"): digits = digits[1:]
        if len(digits) != 10:
            raise ValueError("Please enter a valid 10-digit mobile number.")
        if not digits[0] in "6789":
            raise ValueError("Invalid Indian mobile number (must start with 6/7/8/9).")
        return digits

    @field_validator("children")
    @classmethod
    def _children_range(cls, v):
        if v < 0 or v > 10:
            raise ValueError("Number of children must be between 0 and 10")
        return v

    @model_validator(mode="after")
    def _validate_child_ages(self):
        if self.children == 0:
            self.child_ages = ""
            return self
        raw = (self.child_ages or "").strip()
        if not raw:
            raise ValueError(f"Child ages required — expected {self.children} age(s)")
        parts = [p.strip() for p in raw.split(",") if p.strip() != ""]
        if len(parts) != self.children:
            raise ValueError(f"Expected {self.children} child age(s), got {len(parts)}")
        for i, p in enumerate(parts, 1):
            if not p.lstrip("-").isdigit():
                raise ValueError(f"Child {i} age must be a whole number")
            age = int(p)
            if age < 0 or age > 12:
                raise ValueError(f"Child {i} age must be between 0 and 12")
        return self


class LeadUpdate(BaseModel):
    customer_name: Optional[str] = None
    phone: Optional[str] = None
    email: Optional[str] = None
    travel_date: Optional[str] = None
    destination: Optional[str] = None
    hotel_category: Optional[str] = None
    meal_plan: Optional[str] = None
    package_type: Optional[str] = None
    budget: Optional[str] = None
    adults: Optional[int] = None
    children: Optional[int] = None
    child_ages: Optional[str] = None
    rooms: Optional[int] = None
    room_type: Optional[str] = None
    departure_from: Optional[str] = None
    special_requests: Optional[str] = None
    lead_status: Optional[str] = None
    remarks: Optional[str] = None
    supplier_quote_amount: Optional[float] = None
    selling_amount: Optional[float] = None
    quote_received: Optional[bool] = None
    quote_received_date: Optional[str] = None
    quote_sent_to_customer: Optional[bool] = None
    quote_sent_date: Optional[str] = None
    booking_status: Optional[str] = None
    booking_amount_received: Optional[float] = None
    follow_up_date: Optional[str] = None
    payment_status: Optional[str] = None


class SupplierCreate(BaseModel):
    name: str
    email: EmailStr
    phone: str = ""
    destination: str = ""
    country: str = ""


class MasterItem(BaseModel):
    category: str  # e.g. 'destination','hotel_category','meal_plan','package_type','room_type','departure_city','payment_mode','lead_source'
    value: str


class SupplierEmailSend(BaseModel):
    lead_id: str
    supplier_ids: List[str]
    cc: List[str] = []
    bcc: List[str] = []
    subject: str
    body: str


class SupplierResponseUpdate(BaseModel):
    status: Optional[str] = None  # Email Sent, Awaiting Reply, Quote Received, Quote Rejected, Supplier Declined, No Response, Follow-up Required, Booking Confirmed, Quote Accepted
    reply_received: Optional[bool] = None
    reply_date: Optional[str] = None
    quote_received: Optional[bool] = None
    quote_amount: Optional[float] = None
    currency: Optional[str] = None
    hotel_offered: Optional[str] = None
    flight_included: Optional[bool] = None
    transfer_included: Optional[bool] = None
    activities_included: Optional[str] = None
    valid_until: Optional[str] = None
    remarks: Optional[str] = None


class AIItineraryReq(BaseModel):
    destination: str
    days: int = 5
    budget: str = ""
    package_type: str = "Family"
    adults: int = 2
    children: int = 0
    hotel_category: str = "4 Star"
    meal_plan: str = "AP"


class AIQuoteReq(BaseModel):
    destination: str
    budget: str = ""
    hotel_category: str = "4 Star"
    adults: int = 2
    children: int = 0
    days: int = 5


# =================== Auth Routes ===================
@api.post("/auth/google-callback")
async def google_callback(request: Request, response: Response):
    """Exchange Emergent Auth session_id for our own JWT. Only pre-registered users allowed."""
    import httpx
    body = await request.json()
    session_id = body.get("session_id")
    if not session_id:
        raise HTTPException(status_code=400, detail="session_id required")
    async with httpx.AsyncClient(timeout=15) as client:
        r = await client.get(
            "https://demobackend.emergentagent.com/auth/v1/env/oauth/session-data",
            headers={"X-Session-ID": session_id},
        )
    if r.status_code != 200:
        raise HTTPException(status_code=401, detail="Google auth failed")
    data = r.json()
    email = (data.get("email") or "").lower()
    if not email:
        raise HTTPException(status_code=401, detail="No email from Google")
    user = await db.users.find_one({"email": email})
    if not user:
        raise HTTPException(status_code=403, detail=f"Not authorized — {email} is not registered. Contact admin.")
    if not user.get("active", True):
        raise HTTPException(status_code=403, detail="Account disabled")
    uid = str(user["_id"])
    access = create_access_token(uid, email, user["role"])
    refresh = create_refresh_token(uid)
    set_auth_cookies(response, access, refresh)
    return {
        "id": uid, "email": email, "name": user.get("name") or data.get("name"),
        "role": user["role"], "staff_code": user.get("staff_code", ""),
        "access_token": access,
    }


@api.post("/auth/login")
async def login(body: LoginBody, response: Response):
    email = body.email.lower()
    user = await db.users.find_one({"email": email})
    if not user or not verify_password(body.password, user["password_hash"]):
        raise HTTPException(status_code=401, detail="Invalid email or password")
    if not user.get("active", True):
        raise HTTPException(status_code=403, detail="Account disabled")
    uid = str(user["_id"])
    access = create_access_token(uid, email, user["role"])
    refresh = create_refresh_token(uid)
    set_auth_cookies(response, access, refresh)
    return {
        "id": uid, "email": email, "name": user.get("name"),
        "role": user["role"], "staff_code": user.get("staff_code", ""),
        "access_token": access,
    }


@api.post("/auth/logout")
async def logout(response: Response, user=Depends(get_current_user)):
    clear_auth_cookies(response)
    return {"success": True}


@api.get("/auth/me")
async def me(user=Depends(get_current_user)):
    return user


# =================== Users (Admin) ===================
@api.get("/users")
async def list_users(user=Depends(require_roles("admin"))):
    users = await db.users.find({}).to_list(1000)
    return [clean_doc(u) for u in users]


@api.post("/users")
async def create_user(body: UserCreate, user=Depends(require_roles("admin"))):
    email = body.email.lower()
    if await db.users.find_one({"email": email}):
        raise HTTPException(status_code=400, detail="Email already exists")
    if body.role not in ("admin", "sales", "operations"):
        raise HTTPException(status_code=400, detail="Invalid role")
    doc = {
        "email": email, "password_hash": hash_password(body.password),
        "name": body.name, "role": body.role,
        "staff_code": body.staff_code,
        "consultant_code": (body.consultant_code or "").upper()[:2],
        "active": True, "created_at": now_iso(),
    }
    r = await db.users.insert_one(doc)
    await log_activity(user, "create_user", "user", str(r.inserted_id), None, {"email": email, "role": body.role})
    doc["_id"] = r.inserted_id
    return clean_doc(doc)


@api.patch("/users/{uid}")
async def update_user(uid: str, body: UserUpdate, user=Depends(require_roles("admin"))):
    updates = {k: v for k, v in body.model_dump(exclude_unset=True).items() if v is not None}
    if "consultant_code" in updates:
        updates["consultant_code"] = updates["consultant_code"].upper()[:2]
    if "password" in updates:
        updates["password_hash"] = hash_password(updates.pop("password"))
    if not updates:
        raise HTTPException(status_code=400, detail="No fields to update")
    result = await db.users.update_one({"_id": oid(uid)}, {"$set": updates})
    if result.matched_count == 0:
        raise HTTPException(status_code=404, detail="User not found")
    await log_activity(user, "update_user", "user", uid, None, updates)
    doc = await db.users.find_one({"_id": oid(uid)})
    return clean_doc(doc)


@api.delete("/users/{uid}")
async def delete_user(uid: str, user=Depends(require_roles("admin"))):
    if uid == user["id"]:
        raise HTTPException(status_code=400, detail="Cannot delete yourself")
    r = await db.users.delete_one({"_id": oid(uid)})
    if r.deleted_count == 0:
        raise HTTPException(status_code=404, detail="User not found")
    await log_activity(user, "delete_user", "user", uid)
    return {"success": True}


# =================== Leads ===================
@api.get("/leads/duplicate-check")
async def duplicate_check(phone: str = "", email: str = "", user=Depends(get_current_user)):
    """Return matching leads by phone or email (case-insensitive)."""
    ors = []
    if phone.strip():
        ors.append({"phone": phone.strip()})
    if email.strip():
        ors.append({"email": {"$regex": f"^{email.strip()}$", "$options": "i"}})
    if not ors:
        return {"matches": []}
    query = {"$or": ors}
    # Sales users only check within their own leads
    if user["role"] == "sales":
        query["staff_id"] = user["id"]
    docs = await db.leads.find(query).sort("created_at", -1).limit(10).to_list(10)
    return {"matches": [{
        "lead_id": d.get("lead_id"),
        "customer_name": d.get("customer_name"),
        "phone": d.get("phone"),
        "email": d.get("email"),
        "destination": d.get("destination"),
        "created_at": d.get("created_at"),
        "lead_status": d.get("lead_status"),
        "staff_name": d.get("staff_name"),
    } for d in docs]}


@api.get("/leads/calendar")
async def calendar_events(user=Depends(get_current_user), month: str = ""):
    """Return travel + follow-up dates for calendar view. month optional YYYY-MM filter."""
    query = _lead_filter_for_user(user)
    leads = await db.leads.find(query).to_list(5000)
    events = []
    for l in leads:  # noqa: E741
        for kind, field in (("travel", "travel_date"), ("follow-up", "follow_up_date")):
            d = (l.get(field) or "")[:10]
            if d and (not month or d.startswith(month)):
                events.append({
                    "date": d, "kind": kind,
                    "lead_id": l.get("lead_id"),
                    "customer_name": l.get("customer_name"),
                    "destination": l.get("destination"),
                    "lead_status": l.get("lead_status"),
                })
    return sorted(events, key=lambda e: e["date"])


def _lead_filter_for_user(user: dict) -> dict:
    if user["role"] == "sales":
        return {"staff_id": user["id"]}
    return {}


def _calc_derived(lead: dict) -> dict:
    supp = lead.get("supplier_quote_amount") or 0
    sell = lead.get("selling_amount") or 0
    lead["profit"] = float(sell) - float(supp) if supp or sell else 0
    smd = lead.get("supplier_mail_date")
    if smd:
        try:
            d = datetime.fromisoformat(smd.replace("Z", "+00:00"))
            lead["quote_pending_days"] = (datetime.now(timezone.utc) - d).days
        except Exception:
            lead["quote_pending_days"] = 0
    else:
        lead["quote_pending_days"] = 0
    return lead


@api.post("/leads")
async def create_lead(body: LeadCreate, force: bool = False,
                     user=Depends(require_roles("sales", "admin", "operations"))):
    # Duplicate block — only admin can override with ?force=true
    existing = await db.leads.find_one({"phone": body.phone})
    if existing and not (force and user["role"] == "admin"):
        raise HTTPException(status_code=409, detail={
            "message": "Lead already exists for this mobile number.",
            "existing": {
                "lead_id": existing.get("lead_id"),
                "customer_name": existing.get("customer_name"),
                "staff_name": existing.get("staff_name"),
                "destination": existing.get("destination"),
                "lead_status": existing.get("lead_status"),
                "follow_up_date": existing.get("follow_up_date"),
            },
        })
    if existing and force and user["role"] == "admin":
        await db.duplicate_log.insert_one({
            "phone": body.phone, "original_lead_id": existing.get("lead_id"),
            "created_by": user["email"], "timestamp": now_iso(),
        })

    # Auto-allocate to destination consultant if mapping exists
    owner = user
    alloc = await db.allocations.find_one({"destination": body.destination})
    if alloc:
        consultant = await db.users.find_one({"_id": ObjectId(alloc["consultant_id"])})
        if consultant:
            consultant["id"] = str(consultant["_id"])
            owner = consultant

    consultant_code = (owner.get("consultant_code") or owner.get("staff_code") or "XX")[:2].upper()
    lead_id = await generate_lead_id(consultant_code)
    doc = body.model_dump()
    doc.update({
        "lead_id": lead_id,
        "lead_date": doc.get("lead_date") or now_iso(),
        "staff_id": owner.get("id") or str(owner.get("_id")),
        "staff_name": owner.get("name", ""),
        "staff_code": owner.get("consultant_code") or owner.get("staff_code", ""),
        "lead_status": "New Lead",
        "mail_status": "Not Sent",
        "supplier_mail_date": None,
        "remarks": "",
        "supplier_quote_amount": 0,
        "selling_amount": 0,
        "quote_received": False,
        "quote_received_date": None,
        "quote_sent_to_customer": False,
        "quote_sent_date": None,
        "booking_status": "",
        "booking_amount_received": 0,
        "follow_up_date": None,
        "payment_status": "",
        "created_at": now_iso(),
        "updated_at": now_iso(),
        "updated_by": user["email"],
    })
    r = await db.leads.insert_one(doc)
    await log_activity(user, "create_lead", "lead", lead_id, None, {"customer": body.customer_name, "destination": body.destination})
    doc["_id"] = r.inserted_id
    return _calc_derived(clean_doc(doc))


@api.get("/leads")
async def list_leads(
    user=Depends(get_current_user),
    q: str = "",
    status: str = "",
    destination: str = "",
    staff_id: str = "",
    booking_status: str = "",
    limit: int = 500,
):
    query = _lead_filter_for_user(user)
    if staff_id and user["role"] in ("admin", "operations"):
        query["staff_id"] = staff_id
    if status:
        query["lead_status"] = status
    if destination:
        query["destination"] = destination
    if booking_status:
        query["booking_status"] = booking_status
    if q:
        query["$or"] = [
            {"lead_id": {"$regex": q, "$options": "i"}},
            {"customer_name": {"$regex": q, "$options": "i"}},
            {"phone": {"$regex": q, "$options": "i"}},
            {"destination": {"$regex": q, "$options": "i"}},
            {"email": {"$regex": q, "$options": "i"}},
        ]
    leads = await db.leads.find(query).sort("created_at", -1).limit(limit).to_list(limit)
    return [_calc_derived(clean_doc(l)) for l in leads]


@api.get("/leads/{lead_id}")
async def get_lead(lead_id: str, user=Depends(get_current_user)):
    lead = await db.leads.find_one({"lead_id": lead_id})
    if not lead:
        raise HTTPException(status_code=404, detail="Lead not found")
    if user["role"] == "sales" and lead.get("staff_id") != user["id"]:
        raise HTTPException(status_code=403, detail="Forbidden")
    return _calc_derived(clean_doc(lead))


@api.patch("/leads/{lead_id}")
async def update_lead(lead_id: str, body: LeadUpdate, user=Depends(get_current_user)):
    existing = await db.leads.find_one({"lead_id": lead_id})
    if not existing:
        raise HTTPException(status_code=404, detail="Lead not found")
    if user["role"] == "sales" and existing.get("staff_id") != user["id"]:
        raise HTTPException(status_code=403, detail="Forbidden")

    updates = {k: v for k, v in body.model_dump(exclude_unset=True).items() if v is not None}

    # Sales can only update limited fields
    if user["role"] == "sales":
        sales_allowed = {"customer_name", "phone", "email", "travel_date", "destination",
                         "hotel_category", "meal_plan", "package_type", "budget",
                         "adults", "children", "child_ages", "rooms", "room_type",
                         "departure_from", "special_requests", "lead_status",
                         "remarks", "follow_up_date", "booking_status"}
        updates = {k: v for k, v in updates.items() if k in sales_allowed}

    updates["updated_at"] = now_iso()
    updates["updated_by"] = user["email"]
    await db.leads.update_one({"lead_id": lead_id}, {"$set": updates})
    await log_activity(user, "update_lead", "lead", lead_id, None, updates)
    doc = await db.leads.find_one({"lead_id": lead_id})
    return _calc_derived(clean_doc(doc))


@api.delete("/leads/{lead_id}")
async def delete_lead(lead_id: str, user=Depends(require_roles("admin"))):
    r = await db.leads.delete_one({"lead_id": lead_id})
    if r.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Not found")
    await log_activity(user, "delete_lead", "lead", lead_id)
    return {"success": True}


# =================== Suppliers ===================
@api.get("/suppliers")
async def list_suppliers(user=Depends(get_current_user)):
    docs = await db.suppliers.find({}).sort("name", 1).to_list(1000)
    return [clean_doc(d) for d in docs]


@api.post("/suppliers")
async def create_supplier(body: SupplierCreate, user=Depends(require_roles("admin", "operations"))):
    doc = body.model_dump()
    doc["email"] = doc["email"].lower()
    doc["created_at"] = now_iso()
    r = await db.suppliers.insert_one(doc)
    doc["_id"] = r.inserted_id
    await log_activity(user, "create_supplier", "supplier", str(r.inserted_id), None, {"name": body.name})
    return clean_doc(doc)


@api.delete("/suppliers/{sid}")
async def delete_supplier(sid: str, user=Depends(require_roles("admin", "operations"))):
    await db.suppliers.delete_one({"_id": oid(sid)})
    await log_activity(user, "delete_supplier", "supplier", sid)
    return {"success": True}


# =================== Supplier Email ===================
@api.post("/supplier-mail/send")
async def send_supplier_mail(body: SupplierEmailSend, user=Depends(require_roles("admin", "operations"))):
    lead = await db.leads.find_one({"lead_id": body.lead_id})
    if not lead:
        raise HTTPException(status_code=404, detail="Lead not found")

    suppliers = []
    for sid in body.supplier_ids:
        s = await db.suppliers.find_one({"_id": oid(sid)})
        if s:
            suppliers.append({"id": str(s["_id"]), "name": s["name"], "email": s["email"]})

    if not suppliers:
        raise HTTPException(status_code=400, detail="No valid suppliers selected")

    # Attempt real send via Resend (falls back to simulation if unconfigured)
    send_result = await send_email_service(
        to=[s["email"] for s in suppliers],
        subject=body.subject,
        body=body.body,
        cc=body.cc,
        bcc=body.bcc,
    )

    mail_doc = {
        "lead_id": body.lead_id,
        "to_suppliers": suppliers,
        "cc": body.cc,
        "bcc": body.bcc,
        "subject": body.subject,
        "body": body.body,
        "sent_by": user["email"],
        "sent_at": now_iso(),
        "status": send_result.get("status", "unknown"),
        "provider": send_result.get("provider"),
        "message_id": send_result.get("message_id"),
        "error": send_result.get("error"),
    }
    r = await db.mail_history.insert_one(mail_doc)

    supplier_names = ", ".join(s["name"] for s in suppliers)

    # Upsert lead_suppliers tracker rows (assign sequence #)
    existing_count = await db.lead_suppliers.count_documents({"lead_id": body.lead_id})
    for i, s in enumerate(suppliers):
        existing = await db.lead_suppliers.find_one({"lead_id": body.lead_id, "supplier_id": s["id"]})
        if existing:
            await db.lead_suppliers.update_one(
                {"_id": existing["_id"]},
                {"$set": {"status": "Email Sent", "last_email_at": now_iso(),
                          "last_update": now_iso()},
                 "$inc": {"emails_sent": 1}}
            )
        else:
            existing_count += 1
            await db.lead_suppliers.insert_one({
                "lead_id": body.lead_id,
                "supplier_id": s["id"],
                "supplier_name": s["name"],
                "supplier_email": s["email"],
                "sequence": existing_count,
                "status": "Email Sent",
                "emails_sent": 1,
                "first_email_at": now_iso(),
                "last_email_at": now_iso(),
                "reply_received": False,
                "quote_received": False,
                "followups": 0,
                "last_update": now_iso(),
                "created_at": now_iso(),
            })

    await db.leads.update_one(
        {"lead_id": body.lead_id},
        {"$set": {
            "mail_status": "Sent",
            "supplier_mail_date": now_iso(),
            "supplier_name": supplier_names,
            "lead_status": "Supplier Mail Sent" if lead.get("lead_status") == "New Lead" else lead.get("lead_status"),
            "updated_at": now_iso(),
            "updated_by": user["email"],
        }}
    )
    await log_activity(user, "send_supplier_mail", "lead", body.lead_id, None, {"suppliers": supplier_names, "status": send_result.get("status"), "provider": send_result.get("provider")})
    mail_doc["_id"] = r.inserted_id
    return {**clean_doc(mail_doc), "send_result": send_result}


@api.get("/supplier-mail/preview/{lead_id}")
async def preview_supplier_mail(lead_id: str, intro: str = "", user=Depends(require_roles("admin", "operations"))):
    from email_service import render_supplier_rfq_html
    lead = await db.leads.find_one({"lead_id": lead_id})
    if not lead:
        raise HTTPException(status_code=404, detail="Lead not found")
    return {"html": render_supplier_rfq_html(lead, intro_text=intro),
            "subject": f"Travel Enquiry - {lead.get('lead_id')} - {lead.get('destination')}"}


@api.get("/supplier-mail/{lead_id}")
async def mail_history(lead_id: str, user=Depends(get_current_user)):
    docs = await db.mail_history.find({"lead_id": lead_id}).sort("sent_at", -1).to_list(200)
    return [clean_doc(d) for d in docs]


# =================== Supplier Response Tracker ===================
@api.get("/lead-suppliers/{lead_id}")
async def get_lead_suppliers(lead_id: str, user=Depends(get_current_user)):
    docs = await db.lead_suppliers.find({"lead_id": lead_id}).sort("sequence", 1).to_list(200)
    now = datetime.now(timezone.utc)
    results = []
    for d in docs:
        d = clean_doc(d)
        # Compute hours since last email
        le = d.get("last_email_at")
        if le:
            try:
                lt = datetime.fromisoformat(le.replace("Z", "+00:00"))
                d["hours_since_email"] = int((now - lt).total_seconds() // 3600)
            except Exception:
                d["hours_since_email"] = 0
        else:
            d["hours_since_email"] = 0
        results.append(d)
    return results


@api.patch("/lead-suppliers/{tracker_id}")
async def update_lead_supplier(tracker_id: str, body: SupplierResponseUpdate,
                               user=Depends(require_roles("admin", "operations"))):
    existing = await db.lead_suppliers.find_one({"_id": oid(tracker_id)})
    if not existing:
        raise HTTPException(status_code=404, detail="Tracker not found")
    updates = {k: v for k, v in body.model_dump(exclude_unset=True).items() if v is not None}
    # If marking quote received, require quote_amount
    if updates.get("quote_received") and updates.get("quote_amount") is None and not existing.get("quote_amount"):
        raise HTTPException(status_code=400, detail="Quote Amount required when Quote Received = Yes")
    if updates.get("status") == "Quote Received":
        updates.setdefault("reply_received", True)
        updates.setdefault("reply_date", now_iso())
        updates.setdefault("quote_received", True)
    updates["last_update"] = now_iso()
    updates["updated_by"] = user["email"]
    await db.lead_suppliers.update_one({"_id": oid(tracker_id)}, {"$set": updates})
    await log_activity(user, "update_supplier_response", "lead_supplier", tracker_id, None, updates)
    return clean_doc(await db.lead_suppliers.find_one({"_id": oid(tracker_id)}))


@api.post("/lead-suppliers/{tracker_id}/followup")
async def log_followup(tracker_id: str, user=Depends(require_roles("admin", "operations"))):
    r = await db.lead_suppliers.update_one(
        {"_id": oid(tracker_id)},
        {"$set": {"last_followup_at": now_iso(), "status": "Follow-up Required", "last_update": now_iso()},
         "$inc": {"followups": 1, "emails_sent": 1}}
    )
    if r.matched_count == 0:
        raise HTTPException(status_code=404, detail="Not found")
    return {"success": True}


@api.get("/lead-suppliers/dashboard/summary")
async def supplier_dashboard(user=Depends(require_roles("admin", "operations"))):
    now = datetime.now(timezone.utc)
    today_str = now.strftime("%Y-%m-%d")
    all_rows = await db.lead_suppliers.find({}).to_list(10000)

    sent_today = sum(1 for r in all_rows if (r.get("last_email_at") or "").startswith(today_str))
    awaiting = sum(1 for r in all_rows if r.get("status") in ("Email Sent", "Awaiting Reply", "Follow-up Required") and not r.get("reply_received"))
    quotes_today = sum(1 for r in all_rows if r.get("quote_received") and (r.get("reply_date") or "").startswith(today_str))

    overdue = 0
    response_hours = []
    for r in all_rows:
        le = r.get("last_email_at")
        if not le: continue
        try:
            lt = datetime.fromisoformat(le.replace("Z", "+00:00"))
            hrs = (now - lt).total_seconds() / 3600
            if hrs > 24 and not r.get("reply_received"):
                overdue += 1
            if r.get("reply_received") and r.get("reply_date"):
                try:
                    rt = datetime.fromisoformat(r["reply_date"].replace("Z", "+00:00"))
                    fe = r.get("first_email_at") or le
                    ft = datetime.fromisoformat(fe.replace("Z", "+00:00"))
                    response_hours.append((rt - ft).total_seconds() / 3600)
                except Exception:
                    pass
        except Exception:
            pass

    avg_response = round(sum(response_hours) / len(response_hours), 1) if response_hours else 0

    # Top fastest suppliers
    by_supplier = {}
    for r in all_rows:
        if not r.get("reply_received"): continue
        sid = r.get("supplier_id")
        by_supplier.setdefault(sid, {"name": r.get("supplier_name"), "hours": [], "replies": 0})
        by_supplier[sid]["replies"] += 1
        try:
            fe = r.get("first_email_at"); rp = r.get("reply_date")
            if fe and rp:
                ft = datetime.fromisoformat(fe.replace("Z", "+00:00"))
                rt = datetime.fromisoformat(rp.replace("Z", "+00:00"))
                by_supplier[sid]["hours"].append((rt - ft).total_seconds() / 3600)
        except Exception:
            pass
    fastest = []
    for sid, d in by_supplier.items():
        if d["hours"]:
            fastest.append({"name": d["name"], "avg_hours": round(sum(d["hours"]) / len(d["hours"]), 1), "replies": d["replies"]})
    fastest.sort(key=lambda x: x["avg_hours"])

    # Suppliers without email
    suppliers = await db.suppliers.find({}).to_list(1000)
    no_email = sum(1 for s in suppliers if not (s.get("email") or "").strip())

    # Need follow-up (sent > 24h, no reply, followups < 3)
    need_followup = sum(1 for r in all_rows
                        if not r.get("reply_received") and (r.get("followups") or 0) < 3
                        and r.get("last_email_at")
                        and (now - datetime.fromisoformat(r["last_email_at"].replace("Z", "+00:00"))).total_seconds() > 86400)

    return {
        "sent_today": sent_today,
        "awaiting_replies": awaiting,
        "quotes_received_today": quotes_today,
        "overdue": overdue,
        "avg_response_hours": avg_response,
        "top_fastest": fastest[:5],
        "suppliers_no_email": no_email,
        "need_followup": need_followup,
    }


# =================== Master Data ===================
@api.get("/master")
async def list_master(category: str = "", user=Depends(get_current_user)):
    query = {"category": category} if category else {}
    docs = await db.master.find(query).to_list(2000)
    return [clean_doc(d) for d in docs]


@api.post("/master")
async def add_master(body: MasterItem, user=Depends(require_roles("admin"))):
    doc = body.model_dump()
    exists = await db.master.find_one({"category": doc["category"], "value": doc["value"]})
    if exists:
        return clean_doc(exists)
    doc["created_at"] = now_iso()
    r = await db.master.insert_one(doc)
    doc["_id"] = r.inserted_id
    await log_activity(user, "add_master", "master", str(r.inserted_id), None, doc)
    return clean_doc(doc)


@api.delete("/master/{mid}")
async def delete_master(mid: str, user=Depends(require_roles("admin"))):
    await db.master.delete_one({"_id": oid(mid)})
    return {"success": True}


# =================== Dashboard ===================
@api.get("/notifications")
async def notifications(user=Depends(get_current_user)):
    """Return live alerts for the current user's role."""
    now = datetime.now(timezone.utc)
    today = now.strftime("%Y-%m-%d")
    alerts = []

    if user["role"] in ("admin", "operations"):
        # New replies received in last 24h
        recent = await db.lead_suppliers.find({
            "reply_received": True,
            "reply_date": {"$gte": (now - __import__("datetime").timedelta(hours=24)).isoformat()}
        }).sort("reply_date", -1).limit(10).to_list(10)
        for r in recent:
            alerts.append({
                "kind": "reply_received",
                "icon": "🔔",
                "title": f"New reply from #{r.get('sequence')} {r.get('supplier_name')}",
                "detail": f"Lead {r['lead_id']} · Quote {r.get('currency') or ''} {r.get('quote_amount') or ''}",
                "lead_id": r["lead_id"],
                "timestamp": r.get("reply_date"),
            })

        # Overdue > 24h with no reply
        all_trackers = await db.lead_suppliers.find({"reply_received": False}).to_list(500)
        for r in all_trackers:
            le = r.get("last_email_at") or ""
            try:
                lt = datetime.fromisoformat(le.replace("Z", "+00:00"))
                hrs = (now - lt).total_seconds() / 3600
                if hrs > 24:
                    alerts.append({
                        "kind": "overdue",
                        "icon": "🔴",
                        "title": f"#{r.get('sequence')} {r.get('supplier_name')} overdue {int(hrs)}h",
                        "detail": f"Lead {r['lead_id']} · {r.get('followups', 0)} follow-ups",
                        "lead_id": r["lead_id"],
                        "timestamp": le,
                    })
            except Exception:
                pass

    # Follow-ups due today (all roles, scoped)
    q = _lead_filter_for_user(user)
    q["follow_up_date"] = {"$regex": f"^{today}"}
    fups = await db.leads.find(q).limit(20).to_list(20)
    for l in fups:  # noqa: E741
        alerts.append({
            "kind": "followup_due",
            "icon": "🟠",
            "title": f"Follow-up today: {l.get('customer_name')}",
            "detail": f"Lead {l.get('lead_id')} · {l.get('destination')}",
            "lead_id": l.get("lead_id"),
            "timestamp": l.get("follow_up_date"),
        })

    return sorted(alerts, key=lambda a: a.get("timestamp") or "", reverse=True)[:20]


# =================== Resend Inbound Webhook (scaffold) ===================
@api.post("/webhooks/resend")
async def resend_webhook(request: Request):
    """Resend event webhook. Configure at https://resend.com/webhooks pointing to /api/webhooks/resend.
    Handles email.delivered, email.opened, email.bounced, and inbound events."""
    payload = await request.json()
    event = payload.get("type") or payload.get("event") or ""
    data = payload.get("data") or {}
    msg_id = data.get("email_id") or data.get("id") or ""

    # Match by our stored message_id in mail_history
    if msg_id:
        mh = await db.mail_history.find_one({"message_id": msg_id})
        if mh:
            update = {"webhook_last_event": event, "webhook_last_at": now_iso()}
            if event.endswith(".delivered"): update["delivery_status"] = "delivered"
            elif event.endswith(".bounced"): update["delivery_status"] = "bounced"
            elif event.endswith(".opened"): update["opened_at"] = now_iso()
            await db.mail_history.update_one({"_id": mh["_id"]}, {"$set": update})

    # Inbound reply → auto-flip supplier tracker to Awaiting Quote (Ops to fill quote details)
    if event in ("email.received", "inbound.received"):
        from_email = (data.get("from") or "").lower()
        if from_email:
            supp = await db.suppliers.find_one({"email": {"$regex": f"^{from_email}$", "$options": "i"}})
            if supp:
                await db.lead_suppliers.update_many(
                    {"supplier_id": str(supp["_id"]), "reply_received": False},
                    {"$set": {"reply_received": True, "reply_date": now_iso(),
                              "status": "Awaiting Quote", "last_update": now_iso(),
                              "inbound_snippet": (data.get("text") or "")[:500]}}
                )
    return {"ok": True}


@api.get("/dashboard/stats")
async def dashboard_stats(user=Depends(get_current_user)):
    base = _lead_filter_for_user(user)
    now = datetime.now(timezone.utc)
    today_iso = now.strftime("%Y-%m-%d")
    month_prefix = now.strftime("%Y-%m")

    leads = await db.leads.find(base).to_list(5000)
    total = len(leads)
    today_leads = sum(1 for l in leads if (l.get("created_at") or "").startswith(today_iso))
    month_leads = sum(1 for l in leads if (l.get("created_at") or "").startswith(month_prefix))
    booked = sum(1 for l in leads if l.get("booking_status") == "Booked")
    cancelled = sum(1 for l in leads if l.get("lead_status") == "Cancelled")
    quote_pending = sum(1 for l in leads if l.get("mail_status") == "Sent" and not l.get("quote_received"))
    revenue = sum(float(l.get("selling_amount") or 0) for l in leads if l.get("booking_status") == "Booked")
    profit = sum(float(l.get("selling_amount") or 0) - float(l.get("supplier_quote_amount") or 0)
                 for l in leads if l.get("booking_status") == "Booked")
    followups_today = sum(1 for l in leads if (l.get("follow_up_date") or "").startswith(today_iso))
    pending_payments = sum(1 for l in leads if l.get("booking_status") == "Booked" and l.get("payment_status") != "Paid")

    # By-status counts for chart
    status_counts = {}
    for l in leads:
        s = l.get("lead_status") or "Unknown"
        status_counts[s] = status_counts.get(s, 0) + 1

    # Monthly sales (last 6 months)
    monthly = {}
    for l in leads:
        ca = l.get("created_at") or ""
        if len(ca) >= 7:
            key = ca[:7]
            monthly.setdefault(key, {"leads": 0, "revenue": 0, "profit": 0})
            monthly[key]["leads"] += 1
            if l.get("booking_status") == "Booked":
                monthly[key]["revenue"] += float(l.get("selling_amount") or 0)
                monthly[key]["profit"] += float(l.get("selling_amount") or 0) - float(l.get("supplier_quote_amount") or 0)
    monthly_list = sorted([{"month": k, **v} for k, v in monthly.items()], key=lambda x: x["month"])[-6:]

    # Top destinations
    dest_counts = {}
    for l in leads:
        d = l.get("destination") or "Unknown"
        dest_counts[d] = dest_counts.get(d, 0) + 1
    top_destinations = sorted([{"destination": k, "count": v} for k, v in dest_counts.items()], key=lambda x: -x["count"])[:5]

    return {
        "total_leads": total,
        "today_leads": today_leads,
        "month_leads": month_leads,
        "booked": booked,
        "cancelled": cancelled,
        "quote_pending": quote_pending,
        "revenue": revenue,
        "profit": profit,
        "followups_today": followups_today,
        "pending_payments": pending_payments,
        "status_counts": status_counts,
        "monthly": monthly_list,
        "top_destinations": top_destinations,
    }


@api.get("/dashboard/leaderboard")
async def leaderboard(user=Depends(require_roles("admin"))):
    leads = await db.leads.find({}).to_list(10000)
    by_staff = {}
    for l in leads:
        sid = l.get("staff_id") or "unknown"
        by_staff.setdefault(sid, {"staff_id": sid, "staff_name": l.get("staff_name", ""),
                                   "leads": 0, "booked": 0, "revenue": 0})
        by_staff[sid]["leads"] += 1
        if l.get("booking_status") == "Booked":
            by_staff[sid]["booked"] += 1
            by_staff[sid]["revenue"] += float(l.get("selling_amount") or 0)
    ranked = sorted(by_staff.values(), key=lambda x: -x["revenue"])
    for r in ranked:
        r["conversion_rate"] = round((r["booked"] / r["leads"]) * 100, 1) if r["leads"] else 0
    return ranked


# =================== Activity Log ===================
@api.get("/activity")
async def activity_log(user=Depends(require_roles("admin")), limit: int = 200):
    docs = await db.activity_log.find({}).sort("timestamp", -1).limit(limit).to_list(limit)
    return [clean_doc(d) for d in docs]


# =================== AI ===================
@api.post("/ai/itinerary")
async def ai_itinerary(body: AIItineraryReq, user=Depends(get_current_user)):
    try:
        content = await generate_itinerary(
            body.destination, body.days, body.budget, body.package_type,
            body.adults, body.children, body.hotel_category, body.meal_plan
        )
        return {"content": content}
    except Exception as e:
        logger.exception("itinerary error")
        raise HTTPException(status_code=500, detail=f"AI error: {e}")


@api.post("/ai/quote-recommend")
async def ai_quote(body: AIQuoteReq, user=Depends(get_current_user)):
    try:
        content = await recommend_quote(
            body.destination, body.budget, body.hotel_category,
            body.adults, body.children, body.days
        )
        return {"content": content}
    except Exception as e:
        logger.exception("quote recommend error")
        raise HTTPException(status_code=500, detail=f"AI error: {e}")


# =================== Seed Master Defaults ===================
DEFAULT_MASTER = {
    "destination": ["Bali", "Dubai", "Maldives", "Thailand", "Singapore", "Kashmir", "Kerala", "Goa", "Andaman"],
    "hotel_category": ["3 Star", "4 Star", "5 Star", "Villa / Resort", "Boutique"],
    "meal_plan": ["CP", "MAP", "AP", "EP"],
    "package_type": ["Solo", "Couple", "Family", "Honeymoon", "Group"],
    "room_type": ["Single", "Double", "Twin", "Triple", "Suite"],
    "departure_city": ["Mumbai", "Delhi", "Bangalore", "Chennai", "Kolkata", "Hyderabad"],
    "payment_mode": ["Bank Transfer", "UPI", "Card", "Cash"],
    "lead_source": ["Website", "Referral", "Instagram", "Facebook", "Walk-in", "WhatsApp"],
}

DEFAULT_SUPPLIERS = [
    {"name": "Wanderlust Holidays", "email": "sales@wanderlust.demo", "phone": "+91-9876543210", "destination": "Bali", "country": "Indonesia"},
    {"name": "SunSand Tours", "email": "quotes@sunsand.demo", "phone": "+91-9876543211", "destination": "Maldives", "country": "Maldives"},
    {"name": "Emirates Travel Partners", "email": "b2b@emiratestravel.demo", "phone": "+971-501234567", "destination": "Dubai", "country": "UAE"},
    {"name": "Andaman Blue", "email": "hello@andamanblue.demo", "phone": "+91-9876543212", "destination": "Andaman", "country": "India"},
]


async def seed_master(db):
    for category, values in DEFAULT_MASTER.items():
        for v in values:
            await db.master.update_one(
                {"category": category, "value": v},
                {"$setOnInsert": {"category": category, "value": v, "created_at": now_iso()}},
                upsert=True,
            )
    for s in DEFAULT_SUPPLIERS:
        await db.suppliers.update_one(
            {"email": s["email"]},
            {"$setOnInsert": {**s, "created_at": now_iso()}},
            upsert=True,
        )


# =================== Startup ===================
@app.on_event("startup")
async def startup():
    await db.users.create_index("email", unique=True)
    await db.leads.create_index("lead_id", unique=True)
    await db.leads.create_index("staff_id")
    await db.suppliers.create_index("email", unique=True)
    await db.master.create_index([("category", 1), ("value", 1)], unique=True)
    await db.activity_log.create_index("timestamp")
    await seed_admin(db)
    await seed_master(db)
    logger.info("Indian Rounds CRM ready")


@app.on_event("shutdown")
async def shutdown():
    mongo_client.close()


@api.get("/")
async def root():
    return {"service": "Indian Rounds CRM", "status": "ok"}


# =================== Destination Allocations ===================
@api.get("/allocations")
async def list_allocations(user=Depends(get_current_user)):
    docs = await db.allocations.find({}).sort("destination", 1).to_list(500)
    result = []
    for a in docs:
        try:
            c = await db.users.find_one({"_id": ObjectId(a["consultant_id"])})
        except Exception:
            c = None
        result.append({
            "id": str(a["_id"]),
            "destination": a["destination"],
            "consultant_id": a["consultant_id"],
            "consultant_name": (c or {}).get("name", "—"),
            "consultant_code": (c or {}).get("consultant_code") or (c or {}).get("staff_code", ""),
        })
    return result


@api.post("/allocations")
async def set_allocation(body: DestinationAllocation, user=Depends(require_roles("admin"))):
    consultant = await db.users.find_one({"_id": oid(body.consultant_id)})
    if not consultant:
        raise HTTPException(status_code=404, detail="Consultant not found")
    if consultant["role"] not in ("sales", "admin"):
        raise HTTPException(status_code=400, detail="Assigned user must be a sales consultant or admin")
    await db.allocations.update_one(
        {"destination": body.destination},
        {"$set": {"destination": body.destination, "consultant_id": body.consultant_id, "updated_at": now_iso()}},
        upsert=True,
    )
    await log_activity(user, "set_allocation", "allocation", body.destination, None, {"consultant_id": body.consultant_id})
    return {"success": True}


@api.delete("/allocations/{aid}")
async def delete_allocation(aid: str, user=Depends(require_roles("admin"))):
    await db.allocations.delete_one({"_id": oid(aid)})
    return {"success": True}


app.include_router(api)
