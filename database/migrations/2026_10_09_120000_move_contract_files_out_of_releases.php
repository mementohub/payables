<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mută fișierele contractelor din `storage` al aplicației în discul lor.
 *
 * `storage` se face din nou la fiecare punere pe server, deci un contract
 * încărcat azi n-ar mai fi găsit mâine — nici de om, nici de lucrătorul care-l
 * citește. Cele încărcate până acum se caută prin toate release-urile și se
 * mută o dată pentru totdeauna.
 */
return new class extends Migration
{
    public function up(): void
    {
        $root = rtrim((string) config('filesystems.disks.contracts.root'), '/');

        if ($root === '' || ! is_dir($root)) {
            @mkdir($root, 0700, true);
        }

        foreach (DB::table('contract_files')->get(['id', 'contract_id', 'path']) as $file) {
            $to = $root.'/'.$file->contract_id.'/'.basename($file->path);

            if (is_file($to)) {
                $this->rewrite($file->id, $file->contract_id, $to, $root);

                continue;
            }

            $from = $this->find((string) $file->path);

            if ($from === null) {
                continue;
            }

            @mkdir(dirname($to), 0700, true);

            if (@copy($from, $to)) {
                $this->rewrite($file->id, $file->contract_id, $to, $root);
            }
        }
    }

    public function down(): void
    {
        // Fișierele rămân unde sunt: mutarea înapoi n-ar aduce niciun folos.
    }

    /** Unde o fi fișierul: în release-ul de acum sau în oricare altul. */
    private function find(string $path): ?string
    {
        foreach (glob(dirname(base_path()).'/*/storage/app/private/'.$path) ?: [] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $here = storage_path('app/private/'.$path);

        return is_file($here) ? $here : null;
    }

    private function rewrite(int $id, int $contractId, string $to, string $root): void
    {
        DB::table('contract_files')->where('id', $id)->update([
            'path' => trim(str_replace($root, '', $to), '/'),
        ]);
    }
};
