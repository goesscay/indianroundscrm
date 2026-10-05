import type Echo from "laravel-echo";
import type { ChannelAuthorizationCallback } from "pusher-js";
import { ensureCsrfCookie } from "@/lib/api";

/** Realtime is on only when a Reverb/Pusher app key is configured. */
export function realtimeEnabled(): boolean {
  return !!(process.env.NEXT_PUBLIC_REVERB_KEY || "").trim();
}

function readXsrf(): string | null {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : null;
}

/** Channel authorizer: POST /api/broadcasting/auth with the Sanctum session cookie + XSRF header. */
function authorizer(channel: { name: string }) {
  return {
    authorize: (socketId: string, callback: ChannelAuthorizationCallback) => {
      (async () => {
        await ensureCsrfCookie();
        const post = () =>
          fetch("/api/broadcasting/auth", {
            method: "POST",
            credentials: "include",
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              "X-Requested-With": "XMLHttpRequest",
              ...(readXsrf() ? { "X-XSRF-TOKEN": readXsrf() as string } : {}),
            },
            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
          });
        let res = await post();
        if (res.status === 419) {
          await ensureCsrfCookie(true);
          res = await post();
        }
        if (!res.ok) throw new Error(`Broadcast auth failed (${res.status})`);
        callback(null, await res.json());
      })().catch((e) => callback(e instanceof Error ? e : new Error(String(e)), null));
    },
  };
}

let instance: Echo<"reverb"> | null = null;

/** Lazily create the single Echo instance (client-only). Returns null when realtime is disabled. */
export async function getEcho(): Promise<Echo<"reverb"> | null> {
  if (typeof window === "undefined" || !realtimeEnabled()) return null;
  if (instance) return instance;
  const [{ default: EchoCtor }, { default: Pusher }] = await Promise.all([import("laravel-echo"), import("pusher-js")]);
  if (instance) return instance; // another caller finished first
  const scheme = (process.env.NEXT_PUBLIC_REVERB_SCHEME || window.location.protocol.replace(":", "")).toLowerCase();
  const tls = scheme === "https" || scheme === "wss";
  const host = process.env.NEXT_PUBLIC_REVERB_HOST || window.location.hostname;
  const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT || window.location.port || (tls ? 443 : 80));
  instance = new EchoCtor({
    broadcaster: "reverb",
    Pusher,
    key: process.env.NEXT_PUBLIC_REVERB_KEY,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: tls,
    enabledTransports: ["ws", "wss"],
    disableStats: true,
    withoutInterceptors: true,
    authorizer,
  });
  return instance;
}

/** Disconnect and drop the singleton (logout / user change / unmount). */
export function destroyEcho(): void {
  if (!instance) return;
  try {
    instance.leaveAllChannels();
    instance.disconnect();
  } catch {
    /* ignore */
  }
  instance = null;
}
