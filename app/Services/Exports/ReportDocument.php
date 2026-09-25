<?php

namespace App\Services\Exports;

/**
 * Un raport gata de scos din aplicație, în forma din care se pot face și
 * fișierul Excel, și PDF-ul: titlu, antet de coloane și rânduri de celule.
 *
 * Există ca să nu se scrie raportul de două ori. Cele două formate diferă doar
 * prin cum desenează aceleași rânduri, iar când se schimbă o linie din raport,
 * se schimbă într-un singur loc.
 */
class ReportDocument
{
    /** Rând de titlu de secțiune (Venituri, Cheltuieli). */
    public const STYLE_SECTION = 'section';

    /** Rând de grupă, care adună liniile de sub el. */
    public const STYLE_GROUP = 'group';

    /** Rând de total, îngroșat. */
    public const STYLE_TOTAL = 'total';

    /** Rând obișnuit. */
    public const STYLE_NORMAL = 'normal';

    /**
     * @param  list<array{label: string, width?: float, align?: string}>  $columns
     * @param  list<array{cells: list<mixed>, style?: string, indent?: bool}>  $rows
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $notes = [],
        public readonly string $sheet = 'Raport',
        public readonly string $filename = 'raport',
    ) {}

    /**
     * O celulă numerică: valoarea și cum se scrie.
     *
     * @return array{value: ?float, type: string}
     */
    public static function number(?float $value, string $type = 'number'): array
    {
        return ['value' => $value, 'type' => $type];
    }

    /**
     * @return array{value: string, type: string}
     */
    public static function text(string $value): array
    {
        return ['value' => $value, 'type' => 'text'];
    }
}
