/** Emoji and stickers for the team chat. Stickers are sent by id; the picture lives here, not on the server. */

export interface EmojiGroup { key: string; label: string; icon: string; emojis: string[] }

const e = (s: string) => s.trim().split(/\s+/);

export const EMOJI_GROUPS: EmojiGroup[] = [
  { key: "faces", label: "Smileys", icon: "😀", emojis: e("😀 😃 😄 😁 😆 😅 😂 🤣 🙂 😉 😊 😇 🥰 😍 🤩 😘 😋 😛 😜 🤪 🤗 🤔 🤨 😐 😑 😶 🙄 😏 😌 😔 😴 🤤 😷 🤒 🤕 🥳 😎 🤓 😕 😟 🙁 😮 😲 😳 🥺 😢 😭 😤 😠 😡 🤯 😱 😰 😓 🫡 🫠") },
  { key: "hands", label: "People", icon: "👍", emojis: e("👍 👎 👌 ✌️ 🤞 🤝 🙏 👏 🙌 👋 🤙 💪 ✋ 👀 🧑‍💼 💁 🙋 🤷 🤦 🕺 💃 🧳") },
  { key: "travel", label: "Travel", icon: "✈️", emojis: e("✈️ 🛫 🛬 🚆 🚗 🚌 🚢 🏨 🏖️ 🏝️ 🌴 🏔️ ⛰️ 🗺️ 🧭 🛕 🕌 🕍 🏰 🗼 🎡 📸 🌅 🌄 🌊 ⛱️ 🎒 🧳 🛂 🎫 ☀️ 🌧️ ❄️ 🍹 🍽️ ☕") },
  { key: "things", label: "Work", icon: "📞", emojis: e("📞 📱 💬 📧 📝 📅 ⏰ ⏳ ✅ ❌ ⚠️ ❗ ❓ 💰 💵 💳 🧾 📈 📉 📌 📎 🔔 🔥 ⭐ 🌟 💯 🎯 🏆 🎉 🎊 🎁 🚀 💡") },
  { key: "hearts", label: "Hearts", icon: "❤️", emojis: e("❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 ❣️ 💕 💖 💗 💘") },
];

/** The six quick reactions shown when you react to a message. */
export const QUICK_REACTIONS = ["👍", "❤️", "😂", "😮", "🙏", "🎉"];

export interface Sticker { id: string; emoji: string; label: string; tone: string }

// tone: soft background of the sticker tile
export const STICKERS: Sticker[] = [
  { id: "thank-you", emoji: "🙏", label: "Thank you", tone: "bg-amber-100 text-amber-950" },
  { id: "done", emoji: "✅", label: "Done!", tone: "bg-emerald-100 text-emerald-950" },
  { id: "on-it", emoji: "🫡", label: "On it", tone: "bg-sky-100 text-sky-950" },
  { id: "well-done", emoji: "🙌", label: "Well done", tone: "bg-yellow-100 text-yellow-950" },
  { id: "congrats", emoji: "🎉", label: "Congrats", tone: "bg-pink-100 text-pink-950" },
  { id: "deal", emoji: "🤝", label: "Deal!", tone: "bg-teal-100 text-teal-950" },
  { id: "booked", emoji: "🎫", label: "Booked!", tone: "bg-emerald-100 text-emerald-950" },
  { id: "payment-received", emoji: "💰", label: "Payment received", tone: "bg-lime-100 text-lime-950" },
  { id: "hot-lead", emoji: "🔥", label: "Hot lead", tone: "bg-orange-100 text-orange-950" },
  { id: "call-me", emoji: "📞", label: "Call me", tone: "bg-blue-100 text-blue-950" },
  { id: "waiting", emoji: "⏳", label: "Waiting", tone: "bg-stone-200 text-stone-900" },
  { id: "approved", emoji: "👍", label: "Approved", tone: "bg-green-100 text-green-950" },
  { id: "cancelled", emoji: "❌", label: "Cancelled", tone: "bg-red-100 text-red-950" },
  { id: "oops", emoji: "😅", label: "Oops", tone: "bg-orange-100 text-orange-950" },
  { id: "haha", emoji: "😂", label: "Haha", tone: "bg-yellow-100 text-yellow-950" },
  { id: "love-it", emoji: "😍", label: "Love it", tone: "bg-rose-100 text-rose-950" },
  { id: "thinking", emoji: "🤔", label: "Thinking", tone: "bg-slate-200 text-slate-900" },
  { id: "sleepy", emoji: "😴", label: "Sleepy", tone: "bg-indigo-100 text-indigo-950" },
  { id: "lets-go", emoji: "🚀", label: "Let's go", tone: "bg-violet-100 text-violet-950" },
  { id: "chai-break", emoji: "☕", label: "Chai break", tone: "bg-amber-100 text-amber-950" },
  { id: "flight-booked", emoji: "✈️", label: "Flight booked", tone: "bg-sky-100 text-sky-950" },
  { id: "hotel-confirmed", emoji: "🏨", label: "Hotel confirmed", tone: "bg-cyan-100 text-cyan-950" },
  { id: "itinerary-ready", emoji: "🗺️", label: "Itinerary ready", tone: "bg-teal-100 text-teal-950" },
  { id: "holiday-mode", emoji: "🏖️", label: "Holiday mode", tone: "bg-yellow-100 text-yellow-950" },
];

export const stickerById = (id: string) => STICKERS.find((s) => s.id === id);
