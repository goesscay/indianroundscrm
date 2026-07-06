"""Resend email service. Sends real emails if RESEND_API_KEY is set; else returns a simulated result."""
import os
import asyncio
import logging
from datetime import datetime
from typing import List, Optional

logger = logging.getLogger("ir-crm.email")


def _fmt_date(iso: str) -> str:
    if not iso: return "TBD"
    try:
        d = datetime.fromisoformat(iso.replace("Z", "+00:00")) if "T" in iso else datetime.strptime(iso[:10], "%Y-%m-%d")
        return d.strftime("%d/%m/%Y")
    except Exception:
        return iso[:10] or "TBD"


def render_supplier_rfq_html(lead: dict, intro_text: str = "") -> str:
    """Branded HTML for a supplier RFQ."""
    esc = lambda v: (str(v) if v is not None else "").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;") or "—"
    lead_id = esc(lead.get("lead_id"))
    destination = esc(lead.get("destination"))
    travel_date = _fmt_date(lead.get("travel_date") or "")
    hotel = esc(lead.get("hotel_category"))
    meal = esc(lead.get("meal_plan"))
    pkg = esc(lead.get("package_type"))
    adults = esc(lead.get("adults"))
    children = esc(lead.get("children"))
    child_age = esc(lead.get("child_ages") or "Nil")
    rooms = esc(lead.get("rooms"))
    room_type = esc(lead.get("room_type"))
    special = esc(lead.get("special_requests") or "Nil")
    intro = esc(intro_text) if intro_text else "Please share your best quotation for the travel requirement detailed below."

    row = lambda label, val: (
        f'<tr>'
        f'<td width="42%" style="padding:10px 15px;border:1px solid #ddd;">{label}</td>'
        f'<td width="58%" style="padding:10px 15px;border:1px solid #ddd;"><b>{val}</b></td>'
        f'</tr>'
    )
    section_hdr = lambda title: (
        '<tr><td colspan="2" style="background:#106EBE;color:white;padding:10px 15px;'
        f'font-size:15px;font-weight:bold;">{title}</td></tr>'
    )

    return f"""
<div style="max-width:820px;margin:auto;font-family:Arial,sans-serif;border:1px solid #ddd;border-radius:12px;overflow:hidden;background:#ffffff;">
  <div style="background:#01265c;padding:12px 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;border-collapse:collapse;">
      <tr>
        <td width="110" align="center" valign="middle" style="padding:0;">
          <img src="https://customer-assets.emergentagent.com/job_journey-crm-hub-1/artifacts/i3r285or_Indian%20rounds_logo.png"
               alt="Indian Rounds"
               height="80"
               style="display:block;border:0;background:#01265c;padding:4px;border-radius:8px;">
        </td>
        <td width="1" style="background:#ffffff;"></td>
        <td valign="middle" style="padding-left:16px;white-space:nowrap;">
          <div style="font-size:22px;line-height:28px;font-weight:bold;color:white;letter-spacing:0.5px;">INDIAN ROUNDS</div>
          <div style="font-size:12px;letter-spacing:3px;color:#0FFCBE;margin-top:2px;">TRAVEL ENQUIRY</div>
        </td>
      </tr>
    </table>
  </div>
  <div style="padding:22px 26px;">
    <p style="font-size:16px;margin:0 0 10px 0;">Dear Team,</p>
    <p style="margin:0 0 10px 0;"><b style="font-size:18px;color:#001d66;">Greetings from Indian Rounds!</b></p>
    <p style="font-size:15px;margin:0 0 15px 0;">{intro}</p>

    <table width="100%" style="table-layout:fixed;border-collapse:collapse;margin-top:15px;">
      {section_hdr('TRIP DETAILS')}
      {row('Lead ID', lead_id)}
      {row('Destination', destination)}
      {row('Travel Date', travel_date)}
      {row('Package Type', pkg)}
    </table>
    <br>
    <table width="100%" style="table-layout:fixed;border-collapse:collapse;">
      {section_hdr('ACCOMMODATION DETAILS')}
      {row('Hotel Category', hotel)}
      {row('Meal Plan', meal)}
      {row('No of Rooms', rooms)}
      {row('Room Type', room_type)}
    </table>
    <br>
    <table width="100%" style="table-layout:fixed;border-collapse:collapse;">
      {section_hdr('GUEST DETAILS')}
      {row('No of Adults', adults)}
      {row('No of Children', children)}
      {row('Child Age(s)', child_age)}
    </table>
    <br>
    <div style="border:2px solid #ff6d6d;border-radius:8px;padding:12px 16px;">
      <div style="color:#d10000;font-size:16px;font-weight:bold;">⭐ SPECIAL REQUESTS</div>
      <div style="margin-top:4px;font-size:15px;white-space:pre-wrap;">{special}</div>
    </div>
    <p style="margin-top:26px;font-size:16px;">We look forward to receiving your best rates at the earliest.</p>
    <div style="border-left:4px solid #01265c;padding-left:15px;margin-top:20px;">
      Regards,<br><br>
      <b>Sri</b><br>
      +91 73056 69082<br>
      Sales &amp; Operations Team<br>
      <b style="color:#001d66;">Indian Rounds</b>
    </div>
  </div>
  <div style="background:#f5f7fb;color:#6b7280;padding:10px 24px;font-size:11px;text-align:center;border-top:1px solid #e5e7eb;">
    This is an automated enquiry from Indian Rounds CRM.
  </div>
</div>
"""


def _body_to_html(text: str) -> str:
    esc = (text or "").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    return (
        '<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #0F172A; '
        'line-height: 1.6; white-space: pre-wrap;">'
        f'{esc}'
        '</div>'
    )


async def send_email(
    to: List[str],
    subject: str,
    body: str,
    cc: Optional[List[str]] = None,
    bcc: Optional[List[str]] = None,
    from_addr: Optional[str] = None,
    html_override: Optional[str] = None,
) -> dict:
    """Returns {status, provider, message_id?, error?}. html_override, if provided, is used instead of body."""
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
            "html": html_override or _body_to_html(body),
        }
        if cc: params["cc"] = cc
        if bcc: params["bcc"] = bcc
        result = await asyncio.to_thread(resend.Emails.send, params)
        return {"status": "sent", "provider": "resend",
                "message_id": (result or {}).get("id")}
    except Exception as e:
        logger.exception("Resend send failed")
        return {"status": "failed", "provider": "resend", "error": str(e)}
