"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { ChatUser, Conversation } from "@/types";
import UserPicker from "./UserPicker";

interface Props {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  onOpened: (c: Conversation) => void;
}

export default function NewChatDialog({ open, onOpenChange, onOpened }: Props) {
  const [users, setUsers] = useState<ChatUser[]>([]);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return;
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
  }, [open]);

  const pick = async (u: ChatUser) => {
    if (busy) return;
    setBusy(true);
    try {
      const { data } = await api.post<Conversation>("/chat/conversations/direct", { user_id: u.id });
      onOpened(data);
      onOpenChange(false);
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md rounded" data-testid="new-chat-dialog">
        <DialogHeader>
          <DialogTitle>New chat</DialogTitle>
          <DialogDescription>Pick a teammate to start a direct conversation.</DialogDescription>
        </DialogHeader>
        <UserPicker users={users} loading={loading} onToggle={pick} testId="new-chat-picker" />
      </DialogContent>
    </Dialog>
  );
}
