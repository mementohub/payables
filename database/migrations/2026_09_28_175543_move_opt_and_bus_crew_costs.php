<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trei locuri de cheltuială își găsesc departamentul, după lămuririle
 * finanțelor:
 *
 *   „opt”                furnizorii sunt hoteluri din țară (Turism Felix,
 *                        Aluniș, Bibi Touring), deci turism intern;
 *   „diurna&cazare”      diurna și cazarea echipajelor de autocar;
 *   „ghizi”              merg cu autocarul.
 *
 * Toate trei stăteau la Administrativ, fiindcă acolo ajunsese vechea regulă
 * a flotei.
 */
return new class extends Migration
{
    private const ADMIN_BEFORE = '~^(hq|adm|management|hr|it|operational|operatiuni|director comercial|pricing /yield|productie|christian academy|rezervari|nerepartizat|amortizare|ru|diurna&cazare|ghizi|opt)$|^masina|^asm ';

    private const ADMIN_AFTER = '~^(hq|adm|management|hr|it|operational|operatiuni|director comercial|pricing /yield|productie|christian academy|rezervari|nerepartizat|amortizare|ru)$|^masina|^asm ';

    private const TRANSPORT_BEFORE = '~^(trans|taxa dkv|taxa eurowag|\.autogara|turism scolar)$|^b ?[0-9]+ ?[a-z]{3}';

    private const TRANSPORT_AFTER = '~^(trans|taxa dkv|taxa eurowag|diurna&cazare|ghizi|\.autogara|turism scolar)$|^b ?[0-9]+ ?[a-z]{3}';

    private const HELLO_BEFORE = '~^(hello|turism intern|romania)$';

    private const HELLO_AFTER = '~^(hello|turism intern|romania|opt)$';

    public function up(): void
    {
        $this->swap(self::ADMIN_BEFORE, self::ADMIN_AFTER);
        $this->swap(self::TRANSPORT_BEFORE, self::TRANSPORT_AFTER);
        $this->swap(self::HELLO_BEFORE, self::HELLO_AFTER);
    }

    public function down(): void
    {
        $this->swap(self::ADMIN_AFTER, self::ADMIN_BEFORE);
        $this->swap(self::TRANSPORT_AFTER, self::TRANSPORT_BEFORE);
        $this->swap(self::HELLO_AFTER, self::HELLO_BEFORE);
    }

    private function swap(string $from, string $to): void
    {
        DB::table('assignment_rules')->where('kind', 'loc')->where('pattern', $from)->update(['pattern' => $to, 'updated_at' => now()]);
    }
};
