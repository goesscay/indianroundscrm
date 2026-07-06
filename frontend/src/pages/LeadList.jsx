import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { useNavigate } from "react-router-dom";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import StatusPill, { LEAD_STATUSES } from "@/components/app/StatusPill";
import { Search, Download, Phone, MessageCircle } from "lucide-react";
import { useAuth } from "@/context/AuthContext";

const INR = (n) => "₹" + (Number(n) || 0).toLocaleString("en-IN");

export default function LeadList() {
  const [leads, setLeads] = useState([]);
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("all");
  const [err, setErr] = useState("");
  const [loading, setLoading] = useState(true);
  const nav = useNavigate();
  const { user } = useAuth();

  const load = async () => {
    setLoading(true);
    try {
      const params = { q };
      if (status !== "all") params.status = status;
      const { data } = await api.get("/leads", { params });
      setLeads(data);
    } catch (e) { setErr(formatApiError(e)); }
    finally { setLoading(false); }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, [status]);

  const exportCsv = () => {
    const headers = ["Lead ID", "Customer", "Phone", "Destination", "Travel Date", "Status", "Booking", "Supplier ₹", "Selling ₹", "Profit"];
    const rows = leads.map(l => [l.lead_id, l.customer_name, l.phone, l.destination, l.travel_date, l.lead_status, l.booking_status, l.supplier_quote_amount || 0, l.selling_amount || 0, l.profit || 0]);
    const csv = [headers, ...rows].map(r => r.map(v => `"${(v ?? "").toString().replace(/"/g, '""')}"`).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const a = document.createElement("a"); a.href = URL.createObjectURL(blob);
    a.download = `leads-${Date.now()}.csv`; a.click();
  };

  return (
    <div className="space-y-6" data-testid="leads-page">
      <div className="flex items-end justify-between flex-wrap gap-4">
        <div>
          <div className="label-caps">Pipeline</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">
            {user.role === "sales" ? "My Leads" : "All Leads"}
          </h1>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={exportCsv} data-testid="export-csv">
            <Download size={16} /> Export CSV
          </Button>
          {user.role !== "operations" && (
            <Button onClick={() => nav("/leads/new")} style={{ background: "#106EBE" }} data-testid="new-lead-btn">+ New Lead</Button>
          )}
        </div>
      </div>

      <Card className="p-4 rounded-lg">
        <div className="flex flex-wrap gap-3 items-center">
          <div className="relative flex-1 min-w-[220px]">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              onKeyDown={(e) => e.key === "Enter" && load()}
              placeholder="Search lead ID, customer, phone, destination…"
              className="pl-9 h-10"
              data-testid="search-input"
            />
          </div>
          <Select value={status} onValueChange={setStatus}>
            <SelectTrigger className="w-[220px] h-10" data-testid="filter-status">
              <SelectValue placeholder="All statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              {LEAD_STATUSES.map(s => <SelectItem key={s} value={s}>{s}</SelectItem>)}
            </SelectContent>
          </Select>
          <Button onClick={load} variant="outline" data-testid="search-btn">Search</Button>
        </div>
      </Card>

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Lead ID</TableHead>
                <TableHead>Customer</TableHead>
                <TableHead>Phone</TableHead>
                <TableHead>Destination</TableHead>
                <TableHead>Travel</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Selling ₹</TableHead>
                <TableHead className="text-right">Profit</TableHead>
                <TableHead>Staff</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {loading && <TableRow><TableCell colSpan={9} className="text-center text-muted-foreground py-8">Loading…</TableCell></TableRow>}
              {!loading && leads.length === 0 && (
                <TableRow><TableCell colSpan={9} className="text-center text-muted-foreground py-12">No leads yet. Create one to get started.</TableCell></TableRow>
              )}
              {leads.map((l) => {
                const digits = (l.phone || "").replace(/\D/g, "");
                const tenDigit = digits.slice(-10);
                const waPhone = tenDigit.length === 10 ? `91${tenDigit}` : digits;
                return (
                <TableRow key={l.lead_id} className="cursor-pointer hover:bg-secondary/50"
                          onClick={() => nav(`/leads/${l.lead_id}`)}
                          data-testid={`lead-row-${l.lead_id}`}>
                  <TableCell className="font-mono font-semibold">{l.lead_id}</TableCell>
                  <TableCell className="font-medium">{l.customer_name}</TableCell>
                  <TableCell className="text-muted-foreground" onClick={(e) => e.stopPropagation()}>
                    <div className="flex items-center gap-1.5">
                      <span className="text-xs">{l.phone}</span>
                      {tenDigit.length === 10 && (
                        <>
                          <a href={`tel:+91${tenDigit}`} title="Call"
                             className="p-1 rounded hover:bg-[#106EBE]/10 text-[#106EBE]"
                             data-testid={`row-call-${l.lead_id}`}>
                            <Phone size={13} />
                          </a>
                          <a href={`https://wa.me/${waPhone}`} target="_blank" rel="noreferrer" title="WhatsApp"
                             className="p-1 rounded hover:bg-emerald-100 text-emerald-600"
                             data-testid={`row-wa-${l.lead_id}`}>
                            <MessageCircle size={13} />
                          </a>
                        </>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>{l.destination}</TableCell>
                  <TableCell className="text-muted-foreground text-xs">{(l.travel_date || "").slice(0, 10)}</TableCell>
                  <TableCell><StatusPill status={l.lead_status} /></TableCell>
                  <TableCell className="text-right font-semibold">{INR(l.selling_amount)}</TableCell>
                  <TableCell className="text-right" style={{ color: (l.profit || 0) >= 0 ? "#0F766E" : "#B91C1C" }}>
                    {INR(l.profit)}
                  </TableCell>
                  <TableCell className="text-xs text-muted-foreground">{l.staff_name}</TableCell>
                </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
      </Card>
      {err && <div className="text-sm text-destructive">{err}</div>}
    </div>
  );
}
