<?php

namespace App\Console\Commands;

use App\Models\CompanyDocument;
use App\Services\Documents\DocumentReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('documents:remind
    {--force : Trimite chiar dacă azi a mai plecat una}')]
#[Description('Aduce aminte, cu o zi înainte de termen, de deciziile și procedurile care așteaptă luarea la cunoștință.')]
class MailDocumentReminders extends Command
{
    public function handle(DocumentReminder $reminder): int
    {
        if (! config('documents.consent.remind', true)) {
            $this->warn('Aducerile aminte sunt oprite din configurare.');

            return self::SUCCESS;
        }

        $papers = CompanyDocument::query()
            ->whereNotNull('published_at')
            ->whereNotNull('token')
            ->whereNull('archived_at')
            ->whereNotNull('sent_to')
            // Cu o zi înainte: destul cât să mai apuce să citească, destul de
            // aproape cât să nu uite iar.
            ->whereDate('consent_due_at', now()->addDay()->toDateString())
            // Una pe zi, oricât de des ar rula programul. Una trimisă
            // săptămâna trecută de pe buton nu oprește aducerea aminte de
            // dinaintea termenului.
            ->when(! $this->option('force'), fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->whereNull('consent_reminded_at')
                    ->orWhereDate('consent_reminded_at', '<', now()->toDateString()),
            ))
            ->get();

        if ($papers->isEmpty()) {
            $this->info('Niciun termen nu se încheie mâine.');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($papers as $paper) {
            $sent = $reminder->send($paper);

            $this->line(sprintf(
                '%s %s → %s',
                $paper->label('one'),
                $paper->number,
                $sent === [] ? 'n-a plecat nimic' : implode(', ', $sent),
            ));

            $count += $sent === [] ? 0 : 1;
        }

        $this->info($count.' din '.$papers->count().' au plecat.');

        return self::SUCCESS;
    }
}
