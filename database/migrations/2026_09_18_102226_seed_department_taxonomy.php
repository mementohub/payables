<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The departments invoices are routed to, replacing the seeded demo
     * ones (and their demo approvals), with the rules that turn OMC's cost
     * centres (doc_poz.loc) and offices into those departments. Rules are
     * data: they can be changed from the app.
     */
    public function up(): void
    {
        DB::table('invoice_events')->whereIn('type', ['approved', 'approval_revoked'])->delete();
        DB::table('invoice_approvals')->delete();
        DB::table('invoices')->update(['responsabili_approved_at' => null, 'is_fully_approved' => false, 'fully_approved_at' => null]);
        DB::table('departments')->delete();

        $now = now();
        $departments = [
            ['charters', 'Charters', 'product', null],
            ['circuite_culturale', 'Circuite Culturale', 'product', null],
            ['circuite_exotice', 'Circuite Exotice', 'product', null],
            ['sejururi_exotice', 'Sejururi Exotice', 'product', null],
            ['croaziere', 'Croaziere', 'product', null],
            ['senior_voyage', 'Senior Voyage', 'product', null],
            ['cazari_individuale', 'Cazări Individuale', 'product', null],
            ['ticketing', 'Ticketing', 'product', null],
            ['corporate', 'Corporate', 'product', null],
            ['sales_b2c', 'Sales B2C', 'channel', null],
            ['b2c_franchise', 'Francize', 'channel', 'sales_b2c'],
            ['b2c_retail', 'Agenții retail', 'channel', 'sales_b2c'],
            ['b2c_site', 'Site', 'channel', 'sales_b2c'],
            ['sales_b2b', 'Sales B2B – agenții partenere', 'channel', null],
            ['marketing', 'Marketing', 'support', null],
            ['financial', 'Financiar', 'support', null],
            ['administration', 'Administrativ', 'support', null],
            ['quality_compliance', 'Calitate & Conformitate', 'support', null],
        ];

        $ids = [];

        foreach ($departments as $sort => [$code, $name, $group, $parent]) {
            $ids[$code] = DB::table('departments')->insertGetId([
                'code' => $code,
                'name' => $name,
                'type' => $group,
                'group' => $group,
                'parent_id' => $parent !== null ? $ids[$parent] : null,
                'sort' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Cost centres: exact names first, then patterns (~, case-insensitive),
        // the last pattern catching the ~80 retail agency names.
        $rules = [
            ['loc', 'B2C', 'sales_b2c', null],
            ['loc', '~^(chartere|wintersun|fly bus&rooms|mementoair|sejururi)$', 'charters', null],
            ['loc', '~^(circuite|ciurcuite|pelerinaje|experienta&aventura|city break)$', 'circuite_culturale', null],
            ['loc', '~^circuite exotice$', 'circuite_exotice', null],
            ['loc', '~^(exotice|groups&exotics|overseas)$', 'sejururi_exotice', null],
            ['loc', '~^senior voyage$', 'senior_voyage', null],
            ['loc', '~^(m\. rooms \(cazari\)|turism intern|turism scolar|romania)$', 'cazari_individuale', null],
            ['loc', '~^(ticketing|tk)$', 'ticketing', null],
            ['loc', '~^(corporate|corporation)$', 'corporate', null],
            ['loc', '~^(b2b|vacanza)$|^bv\. vacanza', 'sales_b2b', null],
            ['loc', '~^(mark|marketing|sales&marketing|targ turism.*|doraly expo|digi|hello)$', 'marketing', null],
            ['loc', '~^(financiar|conta)$', 'financial', null],
            ['loc', '~^(compleance|compliance|calitate)$', 'quality_compliance', null],
            ['loc', '~^(hq|adm|management|hr|it|operational|operatiuni|director comercial|pricing /yield|productie|christian academy|rezervari|nerepartizat|amortizare|ru)$', 'administration', null],
            ['loc', '~^(trans|taxa dkv|taxa eurowag|diurna&cazare|\.autogara|ghizi|opt)$|^b ?[0-9]+ ?[a-z]{3}|^masina|^asm ', 'administration', 'flota de autocare'],
            ['loc', '~^franciza', 'b2c_franchise', null],
            ['loc', '~.', 'b2c_retail', 'orice alt loc de cheltuială: agențiile proprii'],
            ['office', 'Corporate', 'corporate', null],
            ['office', 'Marketing si promovare', 'marketing', null],
            ['office', '~^targ turism', 'marketing', null],
        ];

        foreach ($rules as [$kind, $pattern, $department, $note]) {
            DB::table('assignment_rules')->insert([
                'kind' => $kind,
                'pattern' => $pattern,
                'department_id' => $ids[$department],
                'note' => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('assignment_rules')->delete();
        DB::table('departments')->whereNotNull('code')->delete();
    }
};
