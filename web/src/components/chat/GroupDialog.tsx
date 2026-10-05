"use client";

import { useEffect, useMemo, useState } from "react";
import { Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { ChatUser, Conversation } from "@/types";
import UserPicker from "./UserPicker";

interface Props {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  /** Present => manage mode; absent => create mode. */
  group?: Conversation | null;
  meId: string;
  onSaved: (c: Conversation) => void;
  onDeleted?: (id: string) => void;
}

export default function GroupDialog({ open, onOpenChange, group, meId, onSaved, onDeleted }: Props) {
  const manage = !!group;
  const [users, setUsers] = useState<ChatUser[]>([]);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [name, setName] = useState("");
  // Chosen member ids (never includes me).
  const [ids, setIds] = useState<string[]>([]);

  const groupId = group?.id;
  useEffect(() => {
    if (!open) return;
    setName(group?.name ?? "");
    setIds(group ? group.members.map((m) => m.id).filter((i) => i !== meId) : []);
    let alive = true;
    setLoading(true);
    api
      .get<ChatUser[]>("/chat/users")
      .then(({ data }) => {
        if (alive) setUsers(data);
      })
      .catch((e) => toast.error(formatApiError(e)))
      .finally(() => {
        if (alive) setLoading(false);
      });
    return () => {
      alive = false;
    };
    // Re-seed only when the dialog opens or targets another group (not on every poll refresh).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, groupId, meId]);

  // Known people = active users plus current members (members may be inactive).
  const known = useMemo(() => {
    const map = new Map<string, ChatUser>();
    users.forEach((u) => map.set(u.id, u));
    group?.members.forEach((m) => {
      if (m.id !== meId && !map.has(m.id)) map.set(m.id, m);
    });
    return Array.from(map.values());
  }, [users, group, meId]);

  const toggle = (u: ChatUser) => setIds((cur) => (cur.includes(u.id) ? cur.filter((i) => i !== u.id) : [...cur, u.id]));

  const save = async () => {
    const trimmed = name.trim();
    if (!trimmed) {
      toast.error("Group name is required");
      return;
    }
    setBusy(true);
    try {
      if (group) {
        const original = group.members.map((m) => m.id).filter((i) => i !== meId);
        const add = ids.filter((i) => !original.includes(i));
        const remove = original.filter((i) => !ids.includes(i));
        const body: Record<string, unknown> = {};
        if (trimmed !== group.name) body.name = trimmed;
        if (add.length) body.add_member_ids = add;
        if (remove.length) body.remove_member_ids = remove;
        if (Object.keys(body).length === 0) {
          onOpenChange(false);
          return;
        }
        const { data } = await api.patch<Conversation>(`/chat/groups/${group.id}`, body);
        onSaved(data);
        toast.success("Group updated");
      } else {
        const { data } = await api.post<Conversation>("/chat/groups", { name: trimmed, member_ids: ids });
        onSaved(data);
        toast.success("Group created");
      }
      onOpenChange(false);
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!group) return;
    if (!window.confirm(`Delete group "${group.name}" and all its messages? This cannot be undone.`)) return;
    setBusy(true);
    try {
      await api.delete(`/chat/groups/${group.id}`);
      toast.success("Group deleted");
      onDeleted?.(group.id);
      onOpenChange(false);
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setBusy(false);
    }
  };

  const chosen = ids.map((i) => known.find((u) => u.id === i)).filter((u): u is ChatUser => !!u);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md rounded max-h-[90vh] overflow-y-auto" data-testid={manage ? "manage-group-dialog" : "new-group-dialog"}>
        <DialogHeader>
          <DialogTitle>{manage ? "Manage group" : "New group"}</DialogTitle>
          <DialogDescription>{manage ? "Rename the group and add or remove members." : "Name the group and choose its members."}</DialogDescription>
        </DialogHeader>
        <div>
          <Label htmlFor="group-name" className="text-xs">Group name</Label>
          <Input id="group-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={80} className="mt-1" data-testid="group-name-input" />
        </div>
        {chosen.length > 0 && (
          <div className="flex flex-wrap gap-1.5" data-testid="group-selected">
            {chosen.map((u) => (
              <span key={u.id} className="inline-flex items-center gap-1 border border-border bg-neutral-50 pl-2 pr-1 py-0.5 text-xs rounded">
                {u.name}
                <button type="button" onClick={() => toggle(u)} aria-label={`Remove ${u.name}`} className="p-0.5 hover:bg-neutral-200 rounded">
                  <X size={12} />
                </button>
              </span>
            ))}
          </div>
        )}
        <div>
          <Label className="text-xs">Members</Label>
          <div className="mt-1">
            <UserPicker users={known} selected={ids} onToggle={toggle} multi loading={loading} testId="group-picker" />
          </div>
        </div>
        <DialogFooter className="gap-2 sm:justify-between">
          {manage ? (
            <Button type="button" variant="outline" onClick={remove} disabled={busy} data-testid="delete-group-btn" className="text-red-700 border-red-200 hover:bg-red-50">
              <Trash2 /> Delete group
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>Cancel</Button>
            <Button type="button" onClick={save} disabled={busy || !name.trim()} data-testid="group-save-btn">
              {manage ? "Save" : "Create group"}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
