<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Două departamente noi, cu locurile de cheltuială care erau deja în OMC:
 *
 *   Transport BUS & Turism Școlar   ia flota de autocare (locul „trans”,
 *     numerele de înmatriculare B ### XXX, taxele DKV și Eurowag, autogara)
 *     și „turism scolar”, care stăteau la Administrativ, respectiv la Cazări
 *     Individuale;
 *   Hello Romania – Turism Intern   ia „hello” (era la Marketing), „turism
 *     intern” și „romania” (erau la Cazări Individuale).
 *
 * Restul regulii de flotă — diurna, ghizii, „opt”, mașina personală și ASM —
 * rămâne la Administrativ, unde era.
 *
 * Regulile se citesc în ordine și prima care se potrivește decide, iar ultima
 * („~.”) prinde orice loc rămas. Până acum ordinea era dată de id, deci o
 * regulă nouă ajungea după prinzătoarea aia și nu se aplica niciodată; de
 * aceea capătă o coloană de ordine, iar prinzătoarea trece explicit la urmă.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort')->default(0)->after('department_id');
        });

        DB::table('assignment_rules')->where('kind', 'loc')->where('pattern', '~.')->update(['sort' => 100]);

        $now = now();
        $sort = (int) DB::table('departments')->max('sort');

        $transport = DB::table('departments')->insertGetId([
            'code' => 'transport_bus_scolar',
            'name' => 'Transport BUS & Turism Scolar',
            'group' => 'product',
            'parent_id' => null,
            'sort' => ++$sort,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $hello = DB::table('departments')->insertGetId([
            'code' => 'hello_romania_intern',
            'name' => 'Hello Romania - Turism Intern',
            'group' => 'product',
            'parent_id' => null,
            'sort' => ++$sort,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rule = fn (string $pattern) => DB::table('assignment_rules')->where('kind', 'loc')->where('pattern', $pattern);

        // Flota de autocare pleacă la Transport, cu tot cu turismul școlar.
        $rule('~^(trans|taxa dkv|taxa eurowag|diurna&cazare|\.autogara|ghizi|opt)$|^b ?[0-9]+ ?[a-z]{3}|^masina|^asm ')
            ->update([
                'pattern' => '~^(trans|taxa dkv|taxa eurowag|\.autogara|turism scolar)$|^b ?[0-9]+ ?[a-z]{3}',
                'department_id' => $transport,
                'note' => 'flota de autocare și turismul școlar',
                'updated_at' => $now,
            ]);

        // Ce nu ține de autocare rămâne la Administrativ, unde era.
        $rule('~^(hq|adm|management|hr|it|operational|operatiuni|director comercial|pricing /yield|productie|christian academy|rezervari|nerepartizat|amortizare|ru)$')
            ->update([
                'pattern' => '~^(hq|adm|management|hr|it|operational|operatiuni|director comercial|pricing /yield|productie|christian academy|rezervari|nerepartizat|amortizare|ru|diurna&cazare|ghizi|opt)$|^masina|^asm ',
                'updated_at' => $now,
            ]);

        // Cazările individuale rămân cu „M. Rooms”.
        $rule('~^(m\. rooms \(cazari\)|turism intern|turism scolar|romania)$')
            ->update(['pattern' => '~^m\. rooms \(cazari\)$', 'updated_at' => $now]);

        // „hello” nu e cheltuială de marketing, e departamentul.
        $rule('~^(mark|marketing|sales&marketing|targ turism.*|doraly expo|digi|hello)$')
            ->update(['pattern' => '~^(mark|marketing|sales&marketing|targ turism.*|doraly expo|digi)$', 'updated_at' => $now]);

        DB::table('assignment_rules')->insert([
            'kind' => 'loc',
            'pattern' => '~^(hello|turism intern|romania)$',
            'department_id' => $hello,
            'sort' => 0,
            'note' => 'turismul intern și Hello Romania',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $now = now();
        $ids = DB::table('departments')->whereIn('code', ['transport_bus_scolar', 'hello_romania_intern'])->pluck('id', 'code');
        $administration = DB::table('departments')->where('code', 'administration')->value('id');

        DB::table('assignment_rules')->where('kind', 'loc')->where('pattern', '~^(hello|turism intern|romania)$')->delete();

        DB::table('assignment_rules')->where('kind', 'loc')->where('pattern', '~^(trans|taxa dkv|taxa eurowag|\.autogara|turism scolar)$|^b ?[0-9]+ ?[a-z]{3}')
            ->update([
                'pattern' => '~^(trans|taxa dkv|taxa eurowag|diurna&cazare|\.autogara|ghizi|opt)$|^b ?[0-9]+ ?[a-z]{3}|^masina|^asm ',
                'department_id' => $administration,
                'note' => 'flota de autocare',
                'updated_at' => $now,
            ]);

        DB::table('assignment_rules')->where('kind', 'loc')->where('pattern', '~^(hq|adm|management|hr|it|operational|operatiuni|director comercial|pricing /yield|productie|christian academy|rezervari|nerepartizat|amortizare|ru|diurna&cazare|ghizi|opt)$|^masina|^asm ')
            ->update(['pattern' => '~^(hq|adm|management|hr|it|operational|operatiuni|director comercial|pricing /yield|productie|christian academy|rezervari|nerepartizat|amortizare|ru)$', 'updated_at' => $now]);

        DB::table('assignment_rules')->where('kind', 'loc')->where('pattern', '~^m\. rooms \(cazari\)$')
            ->update(['pattern' => '~^(m\. rooms \(cazari\)|turism intern|turism scolar|romania)$', 'updated_at' => $now]);

        DB::table('assignment_rules')->where('kind', 'loc')->where('pattern', '~^(mark|marketing|sales&marketing|targ turism.*|doraly expo|digi)$')
            ->update(['pattern' => '~^(mark|marketing|sales&marketing|targ turism.*|doraly expo|digi|hello)$', 'updated_at' => $now]);

        DB::table('invoice_line_departments')->whereIn('department_id', $ids->values())->update(['department_id' => null]);
        DB::table('invoices')->whereIn('department_id', $ids->values())->update(['department_id' => null]);
        DB::table('departments')->whereIn('id', $ids->values())->delete();

        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};
