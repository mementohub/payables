<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Scoate pozele lipite în coloană.
 *
 * Microsoft întoarce uneori fotografia de profil ca `data:image/jpeg;base64,…`,
 * de zeci de kilobytes, iar noi o salvam ca atare. Una mai mare decât încape
 * în coloană pica autentificarea cu 500, iar celelalte călătoreau cu fiecare
 * pagină, fiindcă `auth.user` se trimite la fiecare cerere. Rămân inițialele;
 * adresele http(s) normale nu se ating.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('avatar', 'like', 'data:%')->update(['avatar' => null]);
    }

    public function down(): void
    {
        // Pozele lipite nu se pun la loc: nu aveau ce căuta acolo.
    }
};
