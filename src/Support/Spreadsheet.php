<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Un classeur que l'on ouvre dans Excel, sans bibliothèque.
 *
 * Le CSV exporté jusqu'ici n'était pas un tableau : un séparateur, des
 * guillemets, et à l'ouverture une colonne unique où les montants se
 * confondaient avec les libellés. On relisait un rapport en le redécoupant à
 * la main.
 *
 * Ce format-ci (SpreadsheetML, le XML qu'Excel lit nativement depuis 2003)
 * donne de vraies cellules : des colonnes larges de ce qu'elles contiennent,
 * un en-tête figé qui reste visible en défilant, des nombres qu'Excel
 * additionne, et des couleurs pour séparer le titre, l'en-tête et le total.
 * Le tout en chaînes de caractères, sans rien ajouter au projet.
 *
 * Ce qu'il ne fait pas : ni formules, ni graphiques, ni plusieurs feuilles.
 * Un export est une photographie de ce qu'on voit à l'écran ; ce qu'on veut
 * en calculer ensuite appartient à celui qui l'ouvre.
 */
final class Spreadsheet
{
    /** Bleu de l'en-tête, blanc sur fond soutenu : la ligne des titres. */
    private const HEAD = '#1D4ED8';

    /** Le liseré des cellules, assez clair pour ne pas manger le texte. */
    private const LINE = '#CBD5E1';

    /** Une ligne sur deux, pour suivre une ligne longue sans se perdre. */
    private const BAND = '#F1F5F9';

    /** Le total : un fond sable, qui l'arrête du reste. */
    private const TOTAL = '#FEF3C7';

    /**
     * Rend le classeur en téléchargement.
     *
     * @param  list<string>  $columns
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, mixed>|null  $total  la ligne de clôture, s'il y en a une
     */
    public static function download(
        string $filename,
        string $title,
        array $columns,
        iterable $rows,
        ?array $total = null,
        ?string $subtitle = null,
    ): StreamedResponse {
        $xml = self::build($title, $columns, $rows, $total, $subtitle);

        return response()->streamDownload(
            static fn () => print $xml,
            str_ends_with($filename, '.xls') ? $filename : $filename.'.xls',
            [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache',
            ],
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, mixed>|null  $total
     */
    public static function build(
        string $title,
        array $columns,
        iterable $rows,
        ?array $total = null,
        ?string $subtitle = null,
    ): string {
        // Les lignes sont parcourues une fois : on les retient pour mesurer
        // les colonnes avant d'écrire.
        $body = [];

        foreach ($rows as $row) {
            $body[] = array_values((array) $row);
        }

        $widths = self::widths($columns, $body, $total);
        $span = max(1, count($columns));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<?mso-application progid="Excel.Sheet"?>'."\n"
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            .' xmlns:o="urn:schemas-microsoft-com:office:office"'
            .' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            .' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            .self::styles()
            .'<Worksheet ss:Name="'.self::attr(self::sheetName($title)).'">'
            .'<Table>';

        foreach ($widths as $width) {
            $xml .= '<Column ss:AutoFitWidth="0" ss:Width="'.$width.'"/>';
        }

        $xml .= self::merged('sTitle', $title, $span);

        if ($subtitle !== null && $subtitle !== '') {
            $xml .= self::merged('sSub', $subtitle, $span);
        }

        // Une ligne vide entre le titre et le tableau : à l'impression comme à
        // l'écran, le tableau commence alors franchement.
        $xml .= '<Row/>';

        $xml .= '<Row ss:Height="22">';

        foreach ($columns as $column) {
            $xml .= self::cell('sHead', $column, false);
        }

        $xml .= '</Row>';

        foreach ($body as $index => $row) {
            $band = $index % 2 === 1;
            $xml .= '<Row>';

            foreach ($row as $value) {
                $number = self::number($value);
                $style = $number === null
                    ? ($band ? 'sTextBand' : 'sText')
                    : self::numberStyle($number, $band);

                $xml .= self::cell($style, $number ?? $value, $number !== null);
            }

            $xml .= '</Row>';
        }

        if ($total !== null && $total !== []) {
            $xml .= '<Row ss:Height="20">';

            foreach (array_values($total) as $value) {
                $number = self::number($value);
                $style = $number === null
                    ? 'sTotal'
                    : (is_float($number) ? 'sTotalDec' : 'sTotalNum');

                $xml .= self::cell($style, $number ?? $value, $number !== null);
            }

            $xml .= '</Row>';
        }

        // L'en-tête reste à l'écran quand on descend dans les lignes : sans
        // cela, on ne sait plus quelle colonne on lit.
        $xml .= '</Table>'
            .'<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            .'<PageSetup><Layout x:Orientation="Landscape"/>'
            .'<PageMargins x:Bottom="0.5" x:Left="0.4" x:Right="0.4" x:Top="0.5"/></PageSetup>'
            .'<Print><ValidPrinterInfo/><FitWidth>1</FitWidth><FitHeight>0</FitHeight>'
            .'<Scale>100</Scale><HorizontalResolution>600</HorizontalResolution></Print>'
            .'<FreezePanes/><FrozenNoSplit/>'
            .'<SplitHorizontal>'.($subtitle === null ? 4 : 5).'</SplitHorizontal>'
            .'<TopRowBottomPane>'.($subtitle === null ? 4 : 5).'</TopRowBottomPane>'
            .'<ActivePane>2</ActivePane>'
            .'</WorksheetOptions>'
            .'</Worksheet></Workbook>';

        return $xml;
    }

    /**
     * Les styles, déclarés une fois : titre, en-tête, corps, total.
     */
    private static function styles(): string
    {
        $border = '<Borders>'
            .'<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="'.self::LINE.'"/>'
            .'<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="'.self::LINE.'"/>'
            .'<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="'.self::LINE.'"/>'
            .'<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="'.self::LINE.'"/>'
            .'</Borders>';

        $styles = '<Styles>'
            .'<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/>'
            .'<Font ss:FontName="Calibri" ss:Size="11" ss:Color="#0F172A"/></Style>'

            .'<Style ss:ID="sTitle"><Font ss:FontName="Calibri" ss:Size="14" ss:Bold="1" ss:Color="#0F172A"/>'
            .'<Alignment ss:Vertical="Center"/></Style>'

            .'<Style ss:ID="sSub"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#64748B"/>'
            .'<Alignment ss:Vertical="Center"/></Style>'

            .'<Style ss:ID="sHead"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>'
            .'<Interior ss:Color="'.self::HEAD.'" ss:Pattern="Solid"/>'
            .'<Alignment ss:Vertical="Center" ss:WrapText="1"/>'.$border.'</Style>'

            .'<Style ss:ID="sText"><Alignment ss:Vertical="Center"/>'.$border.'</Style>'
            .'<Style ss:ID="sTextBand"><Alignment ss:Vertical="Center"/>'
            .'<Interior ss:Color="'.self::BAND.'" ss:Pattern="Solid"/>'.$border.'</Style>'

            .'<Style ss:ID="sNum"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>'
            .'<NumberFormat ss:Format="#,##0"/>'.$border.'</Style>'
            .'<Style ss:ID="sNumBand"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>'
            .'<NumberFormat ss:Format="#,##0"/>'
            .'<Interior ss:Color="'.self::BAND.'" ss:Pattern="Solid"/>'.$border.'</Style>'

            .'<Style ss:ID="sTotal"><Font ss:Bold="1"/><Alignment ss:Vertical="Center"/>'
            .'<Interior ss:Color="'.self::TOTAL.'" ss:Pattern="Solid"/>'.$border.'</Style>'
            .'<Style ss:ID="sTotalNum"><Font ss:Bold="1"/>'
            .'<Alignment ss:Horizontal="Right" ss:Vertical="Center"/>'
            .'<NumberFormat ss:Format="#,##0"/>'
            .'<Interior ss:Color="'.self::TOTAL.'" ss:Pattern="Solid"/>'.$border.'</Style>'

            // Les memes cellules, a deux decimales : une consommation
            // journaliere de 1,5 boite ne doit pas s'afficher « 2 ».
            .'<Style ss:ID="sDec"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>'
            .'<NumberFormat ss:Format="#,##0.00"/>'.$border.'</Style>'
            .'<Style ss:ID="sDecBand"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>'
            .'<NumberFormat ss:Format="#,##0.00"/>'
            .'<Interior ss:Color="'.self::BAND.'" ss:Pattern="Solid"/>'.$border.'</Style>'
            .'<Style ss:ID="sTotalDec"><Font ss:Bold="1"/>'
            .'<Alignment ss:Horizontal="Right" ss:Vertical="Center"/>'
            .'<NumberFormat ss:Format="#,##0.00"/>'
            .'<Interior ss:Color="'.self::TOTAL.'" ss:Pattern="Solid"/>'.$border.'</Style>'
            .'</Styles>';

        return $styles;
    }

    /**
     * Le style d'une cellule de nombre : entier ou decimal, bande ou non.
     */
    private static function numberStyle(int|float $number, bool $band): string
    {
        if (is_float($number)) {
            return $band ? 'sDecBand' : 'sDec';
        }

        return $band ? 'sNumBand' : 'sNum';
    }

    private static function merged(string $style, string $text, int $span): string
    {
        return '<Row ss:Height="'.($style === 'sTitle' ? 24 : 18).'">'
            .'<Cell ss:StyleID="'.$style.'"'.($span > 1 ? ' ss:MergeAcross="'.($span - 1).'"' : '').'>'
            .'<Data ss:Type="String">'.self::text($text).'</Data></Cell></Row>';
    }

    private static function cell(string $style, mixed $value, bool $numeric): string
    {
        return '<Cell ss:StyleID="'.$style.'"><Data ss:Type="'.($numeric ? 'Number' : 'String').'">'
            .self::text((string) $value).'</Data></Cell>';
    }

    /**
     * Un nombre, ou null si la cellule est du texte.
     *
     * Les exports portent des montants deja mis en forme (« 15 080 ») : Excel
     * les prendrait pour du texte et refuserait de les additionner. On leur
     * rend leur nature, sans toucher a ce qui n'en est pas : un code patient
     * « PAT-00001 » ou une adresse IP restent du texte.
     */
    private static function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $clean = str_replace(["\u{202f}", "\u{00a0}", ' '], '', $value);

        if (preg_match('/^-?\d+$/', $clean) === 1) {
            return (int) $clean;
        }

        if (preg_match('/^-?\d+[.,]\d+$/', $clean) === 1) {
            return (float) str_replace(',', '.', $clean);
        }

        return null;
    }

    /**
     * La largeur de chaque colonne, en points, d'apres ce qu'elle contient.
     *
     * @param  list<string>  $columns
     * @param  list<array<int, mixed>>  $rows
     * @param  array<int, mixed>|null  $total
     * @return list<int>
     */
    private static function widths(array $columns, array $rows, ?array $total): array
    {
        $widths = [];

        foreach ($columns as $index => $column) {
            $widths[$index] = mb_strlen((string) $column);
        }

        foreach (array_merge($rows, $total === null ? [] : [array_values($total)]) as $row) {
            foreach (array_values($row) as $index => $value) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen((string) $value));
            }
        }

        // Sept points par caractere, borne pour qu'une colonne ne devienne ni
        // illisible ni demesuree.
        return array_map(
            static fn (int $chars): int => (int) max(60, min(320, ($chars + 2) * 7)),
            $widths,
        );
    }

    /** Excel refuse certains caracteres dans un nom de feuille, et 31 au plus. */
    private static function sheetName(string $title): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', ' ', $title) ?? 'Export';

        return mb_substr(trim($clean) ?: 'Export', 0, 31);
    }

    private static function text(string $value): string
    {
        // Les caracteres de controle font refuser le fichier entier par Excel.
        $value = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
