/** Ways to get a person's attention when a chat message arrives: a sound, a flashing tab title and a desktop notification. */

export interface ChatAlertSettings {
  /** Open the sender's chat window at the bottom of the screen. */
  autoOpen: boolean;
  /** Play a short ping. */
  sound: boolean;
  /** Show a system notification when the tab is in the background. */
  desktop: boolean;
}

export const DEFAULT_ALERTS: ChatAlertSettings = { autoOpen: true, sound: true, desktop: true };

const key = (userId: string) => `chat-alerts:${userId}`;

export function loadAlertSettings(userId: string): ChatAlertSettings {
  try {
    const raw = localStorage.getItem(key(userId));
    if (raw) return { ...DEFAULT_ALERTS, ...(JSON.parse(raw) as Partial<ChatAlertSettings>) };
  } catch { /* storage blocked: use the defaults */ }
  return DEFAULT_ALERTS;
}

export function saveAlertSettings(userId: string, s: ChatAlertSettings): void {
  try { localStorage.setItem(key(userId), JSON.stringify(s)); } catch { /* ignore */ }
}

// ---------------------------------------------------------------- sound

let audio: AudioContext | null = null;

/** Two short tones. Browsers only allow sound after the person has interacted with the page once, so failures are ignored. */
export function playPing(): void {
  try {
    const AC = window.AudioContext || (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!AC) return;
    audio ??= new AC();
    if (audio.state === "suspended") void audio.resume();
    const t0 = audio.currentTime;
    [[880, 0], [1175, 0.14]].forEach(([freq, delay]) => {
      const osc = audio!.createOscillator();
      const gain = audio!.createGain();
      osc.type = "sine";
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, t0 + delay);
      gain.gain.exponentialRampToValueAtTime(0.25, t0 + delay + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, t0 + delay + 0.22);
      osc.connect(gain).connect(audio!.destination);
      osc.start(t0 + delay);
      osc.stop(t0 + delay + 0.24);
    });
  } catch { /* no sound */ }
}

// ---------------------------------------------------------------- tab title

let flashTimer: ReturnType<typeof setInterval> | undefined;
let baseTitle = "";
let stopListener: (() => void) | undefined;

/** Alternate the tab title with a message notice until the person comes back to the tab. */
export function flashTitle(text: string): void {
  if (typeof document === "undefined" || document.visibilityState === "visible") return;
  if (flashTimer) { clearInterval(flashTimer); }
  else baseTitle = document.title;
  let on = false;
  const tick = () => { on = !on; document.title = on ? text : baseTitle; };
  tick();
  flashTimer = setInterval(tick, 1000);
  if (!stopListener) {
    const stop = () => {
      if (document.visibilityState !== "visible") return;
      if (flashTimer) clearInterval(flashTimer);
      flashTimer = undefined;
      document.title = baseTitle;
      document.removeEventListener("visibilitychange", stop);
      window.removeEventListener("focus", stop);
      stopListener = undefined;
    };
    document.addEventListener("visibilitychange", stop);
    window.addEventListener("focus", stop);
    stopListener = stop;
  }
}

// ---------------------------------------------------------------- desktop notifications

export type DesktopState = NotificationPermission | "unsupported";

export function desktopState(): DesktopState {
  return typeof window !== "undefined" && "Notification" in window ? Notification.permission : "unsupported";
}

export async function requestDesktopPermission(): Promise<DesktopState> {
  if (desktopState() === "unsupported") return "unsupported";
  try { return await Notification.requestPermission(); } catch { return desktopState(); }
}

/** Only while the tab is in the background; clicking it brings the person back. */
export function notifyDesktop(title: string, body: string, tag: string, onClick: () => void): void {
  if (desktopState() !== "granted" || document.visibilityState === "visible") return;
  try {
    const n = new Notification(title, { body: body.slice(0, 160), tag, icon: "/icons/icon-192.png" });
    n.onclick = () => { window.focus(); onClick(); n.close(); };
  } catch { /* some browsers only allow notifications from a service worker */ }
}
