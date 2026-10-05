<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Imports the JSON export of the legacy (FastAPI/MongoDB) CRM into the relational schema.
 *
 *   php artisan crm:import-legacy /path/to/export-dir [--dry-run] [--credentials-out=/root/file.txt]
 *
 * Runs in one transaction. Refuses to run if leads already exist. Legacy password hashes were never exported, so
 * imported users get a random temporary password and must reset it at first login (written to --credentials-out).
 */
class ImportLegacy extends Command
{
    protected $signature = 'crm:import-legacy {dir : Directory with the exported *.json files}
                            {--dry-run : Do everything, then roll back}
                            {--credentials-out= : File to write temporary passwords for imported users (mode 600)}';

    protected $description = 'Import the legacy MongoDB export into PostgreSQL';

    /** @var array<string,int> old id => new id */
    private array $userMap = [];
    private array $supplierMap = [];
    private array $warnings = [];
    private array $report = [];

    public function handle(): int
    {
        $dir = rtrim($this->argument('dir'), '/\\');
        if (! is_dir($dir)) {
            $this->error("No such directory: $dir");

            return self::FAILURE;
        }
        if (DB::table('leads')->exists() && ! $this->option('dry-run')) {
            $this->error('The leads table is not empty; refusing to import over existing data.');

            return self::FAILURE;
        }

        $data = [];
        foreach (['users', 'suppliers', 'master', 'destination_cities', 'allocations', 'leads', 'lead_notes',
                  'contact_events', 'lead_suppliers', 'mail_history', 'rsq_requests', 'activity_log'] as $name) {
            $file = "$dir/$name.json";
            if (! is_file($file)) {
                $this->error("Missing $file");

                return self::FAILURE;
            }
            $data[$name] = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }

        $creds = [];
        DB::beginTransaction();
        try {
            $creds = $this->importUsers($data['users']);
            $this->importSuppliers($data['suppliers']);
            $this->importMaster($data['master']);
            $this->importDestinationCities($data['destination_cities']);
            $this->importLeads($data['leads']);
            $this->importPerLead('lead_notes', $data['lead_notes'], 'lead_notes');
            $this->importPerLead('contact_events', $data['contact_events'], 'contact_events');
            $this->importLeadSuppliers($data['lead_suppliers']);
            $this->importMailHistory($data['mail_history']);
            $this->importRsq($data['rsq_requests']);
            $this->importActivity($data['activity_log']);
            $this->rebuildCounters();
            $this->resetSequences();

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('Dry run: rolled back.');
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import failed, rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('dry-run') && $creds && ($out = $this->option('credentials-out'))) {
            $old = umask(0077);
            file_put_contents($out, "Temporary passwords (change forced at first login)\n".implode("\n", $creds)."\n");
            umask($old);
            $this->info('Temporary passwords written to '.$out);
        }

        $this->table(['table', 'imported'], collect($this->report)->map(fn ($n, $t) => [$t, $n])->values());
        foreach ($this->warnings as $w) {
            $this->warn($w);
        }

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- helpers

    private function ts(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : $v;
    }

    private function count(string $table, int $n = 1): void
    {
        $this->report[$table] = ($this->report[$table] ?? 0) + $n;
    }

    private function only(string $table, array $row): array
    {
        $cols = Schema::getColumnListing($table);

        return array_intersect_key($row, array_flip($cols));
    }

    // ---------------------------------------------------------------- importers

    private function importUsers(array $users): array
    {
        $creds = [];
        foreach ($users as $u) {
            $email = strtolower(trim($u['email']));
            $existing = DB::table('users')->where('email', $email)->first();
            if ($existing) {
                $this->userMap[$u['id']] = $existing->id;
                $this->warnings[] = "User $email already exists; kept as is.";

                continue;
            }
            $temp = Str::password(16, symbols: false).'9a';
            $id = DB::table('users')->insertGetId([
                'name' => $u['name'] ?? $email,
                'email' => $email,
                'password' => Hash::make($temp),
                'role' => $u['role'] === 'ops' ? 'operations' : $u['role'],
                'staff_code' => $u['staff_code'] ?? '',
                'consultant_code' => $u['consultant_code'] ?? '',
                'active' => (bool) ($u['active'] ?? true),
                'account_status' => 'password_reset_required',
                'created_at' => $this->ts($u['created_at'] ?? null) ?? now(),
                'updated_at' => now(),
            ]);
            $this->userMap[$u['id']] = $id;
            $creds[] = "$email  $temp";
            $this->count('users');
        }

        return $creds;
    }

    private function importSuppliers(array $suppliers): void
    {
        foreach ($suppliers as $s) {
            $email = strtolower(trim($s['email']));
            $existing = DB::table('suppliers')->where('email', $email)->first();
            if ($existing) {
                $this->supplierMap[$s['id']] = $existing->id;

                continue;
            }
            $this->supplierMap[$s['id']] = DB::table('suppliers')->insertGetId([
                'name' => $s['name'], 'email' => $email, 'phone' => $s['phone'] ?? '',
                'destination' => $s['destination'] ?? '', 'country' => $s['country'] ?? '',
                'created_at' => $this->ts($s['created_at'] ?? null) ?? now(), 'updated_at' => now(),
            ]);
            $this->count('suppliers');
        }
    }

    /** Old supplier id -> new id; falls back to matching by email, then creates a stub so history is not lost. */
    private function resolveSupplier(string $oldId, string $email, string $name): int
    {
        if (isset($this->supplierMap[$oldId])) {
            return $this->supplierMap[$oldId];
        }
        $email = strtolower(trim($email));
        $id = DB::table('suppliers')->where('email', $email)->value('id');
        if (! $id) {
            $id = DB::table('suppliers')->insertGetId([
                'name' => $name ?: $email, 'email' => $email, 'phone' => '', 'destination' => '', 'country' => '',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->warnings[] = "Supplier $email ($name) was referenced by history but missing from the supplier list; created.";
            $this->count('suppliers');
        }

        return $this->supplierMap[$oldId] = $id;
    }

    private function importMaster(array $rows): void
    {
        foreach ($rows as $m) {
            $n = DB::table('master')->insertOrIgnore([
                'category' => $m['category'], 'value' => $m['value'],
                'created_at' => $this->ts($m['created_at'] ?? null) ?? now(),
            ]);
            $this->count('master', $n);
        }
    }

    private function importDestinationCities(array $map): void
    {
        foreach ($map as $destination => $cities) {
            $id = DB::table('destination_cities')->where('destination', $destination)->value('id')
                ?? DB::table('destination_cities')->insertGetId(['destination' => $destination, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('destination_city_items')->where('destination_city_id', $id)->delete();
            foreach (array_values($cities) as $pos => $city) {
                DB::table('destination_city_items')->insert(['destination_city_id' => $id, 'city' => $city, 'position' => $pos]);
            }
            $this->count('destination_cities');
        }
    }

    private function importLeads(array $leads): void
    {
        $skip = ['id', 'profit', 'quote_pending_days', 'stay_cities', 'staff_id'];
        foreach ($leads as $l) {
            $row = array_diff_key($l, array_flip($skip));
            $row['staff_id'] = $this->userMap[$l['staff_id'] ?? ''] ?? null;
            if ($row['staff_id'] === null && ! empty($l['staff_id'])) {
                $this->warnings[] = "Lead {$l['lead_id']}: staff user no longer exists; staff_id left empty (name/code kept).";
            }
            foreach (['supplier_mail_date', 'created_at', 'updated_at'] as $f) {
                $row[$f] = $this->ts($row[$f] ?? null);
            }
            $row['created_at'] ??= now();
            $row['updated_at'] ??= $row['created_at'];
            $ignored = array_diff(array_keys($row), Schema::getColumnListing('leads'));
            if ($ignored) {
                $this->warnings[] = "Lead {$l['lead_id']}: ignored unknown field(s) ".implode(',', $ignored);
            }
            DB::table('leads')->insert($this->only('leads', $row));
            foreach (array_values($l['stay_cities'] ?? []) as $pos => $c) {
                DB::table('lead_stay_cities')->insert([
                    'lead_id' => $l['lead_id'], 'position' => $pos, 'city' => $c['city'], 'nights' => $c['nights'] ?? null,
                ]);
            }
            $this->count('leads');
        }
    }

    /** Collections exported as {lead_id: [rows]} */
    private function importPerLead(string $name, array $byLead, string $table): void
    {
        foreach ($byLead as $leadId => $rows) {
            foreach ($rows as $r) {
                unset($r['id']);
                foreach (['created_by_id', 'user_id'] as $f) {
                    if (array_key_exists($f, $r)) {
                        $r[$f] = $this->userMap[$r[$f] ?? ''] ?? null;
                    }
                }
                DB::table($table)->insert($this->only($table, $r));
                $this->count($table);
            }
        }
    }

    private function importLeadSuppliers(array $byLead): void
    {
        foreach ($byLead as $rows) {
            foreach ($rows as $r) {
                unset($r['id'], $r['hours_since_email']);
                $r['supplier_id'] = $this->resolveSupplier($r['supplier_id'] ?? '', $r['supplier_email'] ?? '', $r['supplier_name'] ?? '');
                DB::table('lead_suppliers')->insert($this->only('lead_suppliers', $r));
                $this->count('lead_suppliers');
            }
        }
    }

    private function importMailHistory(array $byLead): void
    {
        foreach ($byLead as $rows) {
            foreach ($rows as $m) {
                $recipients = [];
                foreach ($m['to_suppliers'] ?? [] as $s) {
                    $recipients[] = ['type' => 'to', 'supplier_id' => $this->resolveSupplier($s['id'] ?? '', $s['email'] ?? '', $s['name'] ?? ''), 'name' => $s['name'] ?? null, 'email' => $s['email']];
                }
                foreach (['cc', 'bcc'] as $t) {
                    foreach ($m[$t] ?? [] as $e) {
                        $recipients[] = ['type' => $t, 'supplier_id' => null, 'name' => null, 'email' => is_array($e) ? ($e['email'] ?? '') : $e];
                    }
                }
                $row = array_diff_key($m, array_flip(['id', 'to_suppliers', 'cc', 'bcc']));
                $mid = DB::table('mail_history')->insertGetId($this->only('mail_history', $row));
                foreach ($recipients as $rcp) {
                    DB::table('mail_history_recipients')->insert($rcp + ['mail_history_id' => $mid]);
                }
                $this->count('mail_history');
            }
        }
    }

    private function importRsq(array $rows): void
    {
        $known = DB::table('leads')->pluck('lead_id')->flip();
        foreach ($rows as $r) {
            if (! isset($known[$r['lead_id']])) {
                $this->warnings[] = "RSQ request for unknown lead {$r['lead_id']} skipped.";

                continue;
            }
            unset($r['id']);
            $r['created_at'] ??= now();
            $r['updated_at'] ??= $r['created_at'];
            $r['note'] ??= '';
            DB::table('rsq_requests')->insert($this->only('rsq_requests', $r));
            $this->count('rsq_requests');
        }
    }

    private function importActivity(array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            $batch = [];
            foreach ($chunk as $a) {
                $batch[] = [
                    'user_id' => $this->userMap[$a['user_id'] ?? ''] ?? null,
                    'user_email' => $a['user_email'] ?? null, 'user_name' => $a['user_name'] ?? null,
                    'action' => $a['action'], 'entity' => $a['entity'], 'entity_id' => (string) ($a['entity_id'] ?? ''),
                    'old_value' => isset($a['old_value']) ? json_encode($a['old_value'], JSON_UNESCAPED_UNICODE) : null,
                    'new_value' => isset($a['new_value']) ? json_encode($a['new_value'], JSON_UNESCAPED_UNICODE) : null,
                    'timestamp' => $this->ts($a['timestamp'] ?? null) ?? now(),
                ];
            }
            DB::table('activity_log')->insert($batch);
            $this->count('activity_log', count($batch));
        }
    }

    /** Lead ids look like IR<CC><MM><seq>; rebuild the per-consultant/month/year counters so new ids continue the sequence. */
    private function rebuildCounters(): void
    {
        $max = [];
        foreach (DB::table('leads')->select('lead_id', 'created_at')->get() as $l) {
            if (! preg_match('/^IR([A-Z]{2})(\d{2})(\d{3,})$/', $l->lead_id, $m)) {
                continue;
            }
            $key = "lead_seq_{$m[1]}_".substr($l->created_at, 0, 4)."_{$m[2]}";
            $max[$key] = max($max[$key] ?? 0, (int) $m[3]);
        }
        foreach ($max as $key => $seq) {
            DB::table('counters')->updateOrInsert(['key' => $key], ['seq' => $seq]);
        }
        $this->count('counters', count($max));
    }

    /** Explicit-id inserts are not used, but keep sequences safe for the tables we filled. */
    private function resetSequences(): void
    {
        foreach (['users', 'suppliers', 'leads'] as $t) {
            DB::statement("SELECT setval(pg_get_serial_sequence('$t', 'id'), COALESCE((SELECT MAX(id) FROM $t), 1))");
        }
    }
}
