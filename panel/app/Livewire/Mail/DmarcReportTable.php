<?php

namespace App\Livewire\Mail;

use App\Models\DmarcRecord;
use App\Models\DmarcReport;
use App\Models\MailDomain;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Filters\TernaryFilter;
use NyonCode\WireTable\Table;

/**
 * DMARC aggregate reports about a mail domain: which servers sent mail as
 * the domain, how much, and whether it passed DKIM/SPF (the reports other
 * mail servers send to dmarc-reports@<domain>).
 */
class DmarcReportTable extends Component
{
    use WithTable;

    public int $domain;

    /** The summary covers this many days. */
    public const SUMMARY_DAYS = 30;

    public function mailDomain(): MailDomain
    {
        return MailDomain::findOrFail($this->domain);
    }

    /** @return array{reports: int, messages: int, failed: int, sources: int} */
    public function summary(): array
    {
        $reports = DmarcReport::query()->where('mail_domain_id', $this->mailDomain()->id)
            ->where('ends_at', '>=', now()->subDays(self::SUMMARY_DAYS));
        $failing = DmarcRecord::query()->whereIn('dmarc_report_id', (clone $reports)->select('id'))
            ->where('dkim', '!=', 'pass')->where('spf', '!=', 'pass');

        return [
            'reports' => (clone $reports)->count(),
            'messages' => (int) (clone $reports)->sum('messages'),
            'failed' => (int) (clone $reports)->sum('failed'),
            'sources' => $failing->distinct()->count('source_ip'),
        ];
    }

    public function table(Table $table): Table
    {
        $domain = $this->mailDomain();
        $result = fn (string $state): string => $state === 'pass' ? 'success' : 'danger';

        return $table
            ->stackedOnMobile()
            ->query(DmarcRecord::query()->with('report')
                ->whereHas('report', fn (Builder $q) => $q->where('mail_domain_id', $domain->id)))
            ->columns([
                TextColumn::make('source_ip')->label(__('Odesílající server'))->fontFamily('mono')->searchable(),
                TextColumn::make('count')->label(__('Zpráv'))->sortable(),
                BadgeColumn::make('dkim')->label('DKIM')->colorUsing($result),
                BadgeColumn::make('spf')->label('SPF')->colorUsing($result),
                BadgeColumn::make('disposition')->label(__('Příjemce naložil'))
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'none' => __('doručil'), 'quarantine' => __('do spamu'), 'reject' => __('odmítl'), default => $state,
                    })
                    ->colors(['none' => 'gray', 'quarantine' => 'warning', 'reject' => 'danger']),
                TextColumn::make('report.org_name')->label(__('Report od')),
                TextColumn::make('report.ends_at')->label(__('Za den'))->dateTime('j. n. Y'),
            ])
            ->filters([
                TernaryFilter::make('failed')->label(__('Výsledek DMARC'))
                    ->trueLabel(__('neprošlo (podvržená nebo špatně nastavená pošta)'))->falseLabel(__('prošlo'))
                    ->query(fn (Builder $q, bool $failed) => $failed
                        ? $q->where('dkim', '!=', 'pass')->where('spf', '!=', 'pass')
                        : $q->where(fn (Builder $w) => $w->where('dkim', 'pass')->orWhere('spf', 'pass'))),
            ])
            ->defaultSort('count', 'desc')
            ->emptyState(__('Zatím žádné reporty'), __('Poštovní servery (Gmail, Outlook, Seznam…) posílají přehled jednou denně na dmarc-reports@').$domain->domain.__(', když to DMARC záznam domény říká.'), 'outline:shield-check');
    }

    public function render(): View
    {
        return view('livewire.mail.dmarc-table', ['summary' => $this->summary(), 'days' => self::SUMMARY_DAYS]);
    }
}
