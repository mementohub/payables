<?php

namespace App\Services\Demo;

use App\Models\Department;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\InvoiceEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SeedDemoActivity
{
    /** @var array<int, string> */
    private const COMMENTS = [
        'Verificat scadența — totul ok.',
        'Atașez confirmarea de la furnizor.',
        'Lipsește anexa, am cerut-o pe email.',
        'Reverificat sumele cu contractul.',
        'Discutat cu ordonatorul, e ok de plătit.',
        'Trimit pe semnat la contabilitate.',
        'Verificat IBAN furnizor — corect.',
        'Refacturat pe firma asociată.',
        'Plata urmează în următorul ciclu.',
        'Confirmat cu departamentul de plăți.',
        'Marcat pentru revizuire — diferență de curs.',
        'Aprobat după clarificările telefonice.',
    ];

    /**
     * @return array{invoices: int, approvals: int, comments: int, payments: int}
     */
    public function run(): array
    {
        $ordonator = Department::ordonatori()->where('name', 'Ordonator')->first();
        $platiDept = Department::plati()->first();

        $usersByDepartment = Department::with('members:id,name')
            ->get()
            ->mapWithKeys(fn (Department $d) => [$d->id => $d->members->all()]);

        $invoices = Invoice::query()
            ->furnizor()
            ->whereHas('partner.responsabilDepartments')
            ->with(['partner.responsabilDepartments'])
            ->get();

        $stats = ['invoices' => $invoices->count(), 'approvals' => 0, 'comments' => 0, 'payments' => 0];

        if ($invoices->isEmpty()) {
            return $stats;
        }

        DB::transaction(function () use (
            $invoices,
            $ordonator,
            $platiDept,
            $usersByDepartment,
            &$stats,
        ) {
            foreach ($invoices as $invoice) {
                $stats['approvals'] += $this->applyApprovalStage(
                    $invoice,
                    $this->rollStage(),
                    $ordonator,
                    $usersByDepartment,
                );

                if ($invoice->is_fully_approved && (float) $invoice->val_mon_paid + 0.01 >= (float) $invoice->val_mon) {
                    $stats['payments'] += $this->recordPaymentEvent($invoice, $platiDept, $usersByDepartment);
                }

                $stats['comments'] += $this->maybeAddComments($invoice, $usersByDepartment);
            }
        });

        return $stats;
    }

    private function rollStage(): string
    {
        $r = mt_rand(1, 100);

        return match (true) {
            $r <= 25 => 'pending',
            $r <= 45 => 'partial_responsabil',
            $r <= 60 => 'responsabili_ok',
            default => 'bun_de_plata',
        };
    }

    /**
     * @param  Collection<int, array<int, User>>  $usersByDepartment
     */
    private function applyApprovalStage(
        Invoice $invoice,
        string $stage,
        ?Department $ordonator,
        Collection $usersByDepartment,
    ): int {
        if ($stage === 'pending') {
            return 0;
        }

        $depts = $invoice->partner?->responsabilDepartments ?? collect();

        if ($depts->isEmpty()) {
            return 0;
        }

        $approveDepts = $stage === 'partial_responsabil'
            ? $depts->shuffle()->take(max(1, (int) floor($depts->count() / 2)))
            : $depts;

        $base = ($invoice->data_doc ?? Carbon::now()->subDays(30))
            ->copy()
            ->addDays(mt_rand(1, 5))
            ->setTime(9 + mt_rand(0, 7), mt_rand(0, 59));

        $created = 0;
        $lastApprovedAt = null;

        foreach ($approveDepts as $offset => $dept) {
            $user = $this->pickUser($dept, $usersByDepartment);

            if ($user === null) {
                continue;
            }

            $approvedAt = $base->copy()->addMinutes($offset * 17 + mt_rand(0, 30));

            $approval = InvoiceApproval::create([
                'invoice_id' => $invoice->id,
                'department_id' => $dept->id,
                'user_id' => $user->id,
                'role' => InvoiceApproval::ROLE_RESPONSABIL,
                'approved_at' => $approvedAt,
                'created_at' => $approvedAt,
                'updated_at' => $approvedAt,
            ]);

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'department_id' => $dept->id,
                'type' => InvoiceEvent::TYPE_APPROVED,
                'payload' => [
                    'approval_id' => $approval->id,
                    'role' => $approval->role,
                    'department_name' => $dept->name,
                ],
                'created_at' => $approvedAt,
                'updated_at' => $approvedAt,
            ]);

            $created++;
            $lastApprovedAt = $approvedAt;
        }

        if ($stage === 'partial_responsabil' || $lastApprovedAt === null) {
            return $created;
        }

        $invoice->forceFill(['responsabili_approved_at' => $lastApprovedAt])->save();

        if ($stage === 'responsabili_ok' || $ordonator === null) {
            return $created;
        }

        $ordUser = $this->pickUser($ordonator, $usersByDepartment);

        if ($ordUser === null) {
            return $created;
        }

        $ordApprovedAt = $lastApprovedAt->copy()->addHours(mt_rand(1, 18))->addMinutes(mt_rand(0, 59));

        $ordApproval = InvoiceApproval::create([
            'invoice_id' => $invoice->id,
            'department_id' => $ordonator->id,
            'user_id' => $ordUser->id,
            'role' => InvoiceApproval::ROLE_ORDONATOR,
            'approved_at' => $ordApprovedAt,
            'created_at' => $ordApprovedAt,
            'updated_at' => $ordApprovedAt,
        ]);

        InvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'user_id' => $ordUser->id,
            'department_id' => $ordonator->id,
            'type' => InvoiceEvent::TYPE_APPROVED,
            'payload' => [
                'approval_id' => $ordApproval->id,
                'role' => $ordApproval->role,
                'department_name' => $ordonator->name,
            ],
            'created_at' => $ordApprovedAt,
            'updated_at' => $ordApprovedAt,
        ]);

        $invoice->forceFill([
            'is_fully_approved' => true,
            'fully_approved_at' => $ordApprovedAt,
        ])->save();

        return $created + 1;
    }

    /**
     * @param  Collection<int, array<int, User>>  $usersByDepartment
     */
    private function recordPaymentEvent(Invoice $invoice, ?Department $platiDept, Collection $usersByDepartment): int
    {
        if ($platiDept === null || $invoice->payment_status !== Invoice::PAYMENT_PAID) {
            return 0;
        }

        $platiUser = $this->pickUser($platiDept, $usersByDepartment);

        if ($platiUser === null) {
            return 0;
        }

        $eventAt = ($invoice->fully_approved_at ?? Carbon::now()->subDays(7))
            ->copy()
            ->addHours(mt_rand(2, 36));

        $invoice->forceFill([
            'payment_status_updated_at' => $eventAt,
            'payment_status_updated_by_id' => $platiUser->id,
        ])->save();

        InvoiceEvent::create([
            'invoice_id' => $invoice->id,
            'user_id' => $platiUser->id,
            'type' => InvoiceEvent::TYPE_PAYMENT_STATUS_CHANGED,
            'body' => 'Plată înregistrată din extras bancar.',
            'payload' => ['from' => Invoice::PAYMENT_UNPAID, 'to' => Invoice::PAYMENT_PAID],
            'created_at' => $eventAt,
            'updated_at' => $eventAt,
        ]);

        return 1;
    }

    /**
     * @param  Collection<int, array<int, User>>  $usersByDepartment
     */
    private function maybeAddComments(Invoice $invoice, Collection $usersByDepartment): int
    {
        if (mt_rand(1, 100) > 30) {
            return 0;
        }

        $candidates = $this->candidateCommentUsers($invoice, $usersByDepartment);

        if (empty($candidates)) {
            return 0;
        }

        $count = mt_rand(1, 3);
        $base = ($invoice->data_doc ?? Carbon::now()->subDays(30))
            ->copy()
            ->addDays(mt_rand(1, 14));

        for ($i = 0; $i < $count; $i++) {
            $user = $candidates[array_rand($candidates)];
            $createdAt = $base->copy()->addHours($i * 6 + mt_rand(0, 5))->addMinutes(mt_rand(0, 59));

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'type' => InvoiceEvent::TYPE_COMMENTED,
                'body' => self::COMMENTS[array_rand(self::COMMENTS)],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $count;
    }

    /**
     * @param  Collection<int, array<int, User>>  $usersByDepartment
     * @return array<int, User>
     */
    private function candidateCommentUsers(Invoice $invoice, Collection $usersByDepartment): array
    {
        $departments = $invoice->partner?->responsabilDepartments ?? collect();
        $users = [];

        foreach ($departments as $dept) {
            foreach ($usersByDepartment->get($dept->id) ?? [] as $user) {
                $users[$user->id] = $user;
            }
        }

        if (empty($users)) {
            $users = User::query()->limit(5)->get()->keyBy('id')->all();
        }

        return array_values($users);
    }

    /**
     * @param  Collection<int, array<int, User>>  $usersByDepartment
     */
    private function pickUser(Department $dept, Collection $usersByDepartment): ?User
    {
        $users = $usersByDepartment->get($dept->id) ?? [];

        return empty($users) ? null : $users[array_rand($users)];
    }
}
