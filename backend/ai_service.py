"""AI service using Emergent LLM key (Claude Sonnet 4.5)."""
import os
from emergentintegrations.llm.chat import LlmChat, UserMessage


async def generate_itinerary(destination: str, days: int, budget: str, package_type: str,
                             adults: int, children: int, hotel_category: str, meal_plan: str) -> str:
    key = os.environ["EMERGENT_LLM_KEY"]
    chat = LlmChat(
        api_key=key,
        session_id=f"itinerary-{destination}",
        system_message=(
            "You are an expert travel itinerary planner for Indian Rounds, an Indian travel agency. "
            "Create concise, day-by-day itineraries. Use INR for currency mentions. Keep responses "
            "under 500 words, formatted with clear day headings."
        ),
    ).with_model("anthropic", "claude-sonnet-4-5-20250929")

    prompt = (
        f"Create a {days}-day travel itinerary for {destination}.\n"
        f"Package type: {package_type}\n"
        f"Guests: {adults} adults, {children} children\n"
        f"Hotel category: {hotel_category}\n"
        f"Meal plan: {meal_plan}\n"
        f"Budget: {budget}\n\n"
        "Provide day-wise activities, recommended experiences, and dining suggestions."
    )
    result = await chat.send_message(UserMessage(text=prompt))
    return result


async def recommend_quote(destination: str, budget: str, hotel_category: str,
                          adults: int, children: int, days: int) -> str:
    key = os.environ["EMERGENT_LLM_KEY"]
    chat = LlmChat(
        api_key=key,
        session_id=f"quote-{destination}",
        system_message=(
            "You are a travel pricing expert for Indian Rounds. Provide realistic INR quote "
            "recommendations based on destination and budget. Include a range and breakdown."
        ),
    ).with_model("anthropic", "claude-sonnet-4-5-20250929")

    prompt = (
        f"Recommend a supplier quote and selling price range for:\n"
        f"Destination: {destination}\n"
        f"Duration: {days} days\n"
        f"Guests: {adults} adults, {children} children\n"
        f"Hotel category: {hotel_category}\n"
        f"Customer budget: {budget}\n\n"
        "Give: 1) Suggested supplier cost range (INR), 2) Recommended selling price (INR), "
        "3) Expected profit margin, 4) Brief pricing rationale."
    )
    return await chat.send_message(UserMessage(text=prompt))
