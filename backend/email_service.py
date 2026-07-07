"""Resend email service — plain-text supplier RFQ template."""
import os
import asyncio
import logging
from datetime import datetime
from typing import List, Optional

logger = logging.getLogger("ir-crm.email")


def _fmt_date(iso: str) -> str:
    if not iso:
        return "N/A"
    try:
        d = datetime.fromisoformat(iso.replace("Z", "+00:00")) if "T" in iso else datetime.strptime(iso[:10], "%Y-%m-%d")
        return d.strftime("%Y-%m-%d")
    except Exception:
        return iso[:10] or "N/A"


def _v(val) -> str:
    """Field value → 'N/A' if empty."""
    if val is None:
        return "N/A"
    s = str(val).strip()
    return s if s else "N/A"


def render_supplier_rfq_text(lead: dict, intro_text: str = "") -> str:
    """Plain-text supplier RFQ body. UPPERCASE section headings, N/A for blanks."""
    lead_id       = _v(lead.get("lead_id"))
    destination   = _v(lead.get("destination"))
    travel_date   = _fmt_date(lead.get("travel_date") or "")
    package_type  = _v(lead.get("package_type"))
    hotel_cat     = _v(lead.get("hotel_category"))
    meal_plan     = _v(lead.get("meal_plan"))
    rooms         = _v(lead.get("rooms"))
    room_type     = _v(lead.get("room_type"))
    adults        = _v(lead.get("adults"))
    children_raw  = lead.get("children")
    children      = _v(children_raw)
    child_age     = (lead.get("child_ages") or "").strip() or "Nil"
    special       = (lead.get("special_requests") or "").strip() or "Nil"

    intro = (intro_text or "").strip() or "Please share your best quotation for the travel requirement below."

    lines = [
        "Dear Team,",
        "",
        "Greetings from Indian Rounds!",
        "",
        intro,
        "",
        "TRIP DETAILS",
        f"Lead ID       : {lead_id}",
        f"Destination   : {destination}",
        f"Travel Date   : {travel_date}",
        f"Package Type  : {package_type}",
        "",
        "ACCOMMODATION",
        f"Hotel Category: {hotel_cat}",
        f"Meal Plan     : {meal_plan}",
        f"Rooms         : {rooms}",
        f"Room Type     : {room_type}",
        "",
        "GUEST DETAILS",
        f"Adults        : {adults}",
        f"Children      : {children}",
        f"Child Age     : {child_age}",
        "",
        "SPECIAL REQUESTS",
        special,
        "",
        "We look forward to receiving your best rates.",
        "",
        "Regards,",
        "sri",
        "+91 7305669082",
        "sales operation team",
        "Indian Rounds",
    ]
    return "\n".join(lines)


async def send_email(
    to: List[str],
    subject: str,
    body: str,
    cc: Optional[List[str]] = None,
    bcc: Optional[List[str]] = None,
    from_addr: Optional[str] = None,
) -> dict:
    """Send plain-text email via Resend. Returns {status, provider, message_id?, error?}."""
    api_key = os.environ.get("RESEND_API_KEY", "").strip()
    sender = (from_addr or os.environ.get("RESEND_FROM", "")).strip()

    if not api_key or not sender:
        logger.info("Resend not configured — simulating email send to %s", to)
        return {"status": "simulated_sent", "provider": "mock",
                "note": "RESEND_API_KEY or RESEND_FROM not set — no real email dispatched."}

    try:
        import resend
        resend.api_key = api_key
        params = {
            "from": sender,
            "to": to,
            "subject": subject,
            "text": body or "",
        }
        if cc: params["cc"] = cc
        if bcc: params["bcc"] = bcc
        result = await asyncio.to_thread(resend.Emails.send, params)
        return {"status": "sent", "provider": "resend",
                "message_id": (result or {}).get("id")}
    except Exception as e:
        logger.exception("Resend send failed")
        return {"status": "failed", "provider": "resend", "error": str(e)}
