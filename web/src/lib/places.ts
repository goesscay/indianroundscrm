/** Helpers for place names typed by hand (destination, departure city). */

/** Trim, collapse spaces, and capitalise words when the whole thing was typed in lower case ("phu quoc" -> "Phu Quoc"). Names with capitals ("UAE") are left alone. */
export function tidyPlace(raw: string): string {
  const s = raw.replace(/\s+/g, " ").trim();
  if (!s || s !== s.toLowerCase()) return s;
  return s.replace(/(^|[\s\-\/(])(\p{L})/gu, (_m, pre: string, ch: string) => pre + ch.toUpperCase());
}

/** The Master Data list first, then places already used on leads that are not in it (case-insensitive, no repeats). */
export function mergePlaces(master: string[] | undefined, used: string[] | undefined): string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const v of [...(master ?? []), ...(used ?? [])]) {
    const k = v.trim().toLowerCase();
    if (!k || seen.has(k)) continue;
    seen.add(k);
    out.push(v);
  }
  return out;
}

/** A person's destinations, primary first (the signed-in user, or a row of the users list). */
export function myDestinations(u: { destinations?: string[]; default_destination?: string | null }): string[] {
  const list = u.destinations?.length ? u.destinations : u.default_destination ? [u.default_destination] : [];
  return list.map((d) => d.trim()).filter(Boolean);
}

/** Put the given places first (in their order), then the rest as they were. */
export function placesFirst(list: string[], first: string[]): string[] {
  const lower = new Set(first.map((f) => f.toLowerCase()));
  return [...first.filter((f) => list.some((l) => l.toLowerCase() === f.toLowerCase())).map((f) => list.find((l) => l.toLowerCase() === f.toLowerCase())!), ...list.filter((l) => !lower.has(l.toLowerCase()))];
}
