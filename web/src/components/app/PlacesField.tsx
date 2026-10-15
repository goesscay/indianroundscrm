"use client";

import { Star, X } from "lucide-react";
import { Combobox } from "@/components/ui/combobox";
import { tidyPlace } from "@/lib/places";

/**
 * A short ordered list of places (a person's destinations). The first one is the primary: it is the one filled in
 * wherever a single destination is pre-filled. Pick or type to add; × removes; the star makes one the primary.
 */
export default function PlacesField({ value, onChange, options, testId, placeholder = "Add a destination…" }: {
  value: string[];
  onChange: (v: string[]) => void;
  options: string[];
  testId: string;
  placeholder?: string;
}) {
  const has = (p: string) => value.some((v) => v.toLowerCase() === p.toLowerCase());
  const add = (raw: string) => {
    const p = tidyPlace(raw);
    if (p && !has(p)) onChange([...value, p]);
  };
  const remove = (p: string) => onChange(value.filter((v) => v !== p));
  const makePrimary = (p: string) => onChange([p, ...value.filter((v) => v !== p)]);

  return (
    <div className="space-y-2" data-testid={testId}>
      {value.length > 0 && (
        <ul className="flex flex-wrap gap-1.5" aria-label="Destinations">
          {value.map((p, i) => (
            <li key={p} className="inline-flex items-center gap-1 rounded-full border border-border bg-secondary py-0.5 pl-2 pr-1 text-xs" data-testid={`${testId}-chip-${p}`}>
              {i === 0
                ? <span title="Primary: pre-filled wherever only one destination fits"><Star size={11} className="fill-current text-amber-600" aria-label="Primary" /></span>
                : <button type="button" onClick={() => makePrimary(p)} title="Make this the primary" aria-label={`Make ${p} the primary`} className="rounded p-0.5 text-muted-foreground hover:text-foreground"><Star size={11} /></button>}
              <span className="font-medium">{p}</span>
              <button type="button" onClick={() => remove(p)} aria-label={`Remove ${p}`} className="rounded-full p-0.5 text-muted-foreground hover:bg-background hover:text-foreground"><X size={11} /></button>
            </li>
          ))}
        </ul>
      )}
      <Combobox
        allowCustom options={options.filter((o) => !has(o))} value="" onChange={add}
        placeholder={value.length ? "Add another destination…" : placeholder} searchPlaceholder="Search, or type a new place…" testId={`${testId}-add`}
      />
    </div>
  );
}
