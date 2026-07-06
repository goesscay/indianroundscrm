"""Resend email service. Sends real emails if RESEND_API_KEY is set; else returns a simulated result."""
import os
import asyncio
import logging
from typing import List, Optional

logger = logging.getLogger("ir-crm.email")


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
) -> dict:
    """Returns {status, provider, message_id?, error?}."""
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
            "html": _body_to_html(body),
        }
        if cc: params["cc"] = cc
        if bcc: params["bcc"] = bcc
        result = await asyncio.to_thread(resend.Emails.send, params)
        return {"status": "sent", "provider": "resend",
                "message_id": (result or {}).get("id")}
    except Exception as e:
        logger.exception("Resend send failed")
        return {"status": "failed", "provider": "resend", "error": str(e)}
