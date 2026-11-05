<?php

namespace App\Services\Exports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes an .xlsx file to a temporary file, one row at a time: memory stays small
 * however big the report is. Numbers are real numbers (they can be summed in Excel).
 */
final class SpreadsheetBuilder
{
    private Writer $writer;

    private string $path;

    private bool $open = true;

    private Style $titleStyle;

    private Style $headerStyle;

    private Style $boldStyle;

    private Style $moneyStyle;

    private Style $boldMoneyStyle;

    /**
     * @param array<int, int|float> $widths column widths, in order
     * @param array<int, int> $moneyColumns zero-based columns whose numbers get thousands separators
     */
    public function __construct(string $sheetName, array $widths, private readonly array $moneyColumns)
    {
        set_time_limit(0);

        $options = new Options();
        foreach (array_values($widths) as $index => $width) {
            $options->setColumnWidth((float) $width, $index + 1);
        }

        $this->path = (string) tempnam(sys_get_temp_dir(), 'acc');
        $this->writer = new Writer($options);
        $this->writer->openToFile($this->path);
        $this->writer->getCurrentSheet()->setName($this->safeName($sheetName));

        $this->titleStyle = self::style([['FontBold', true], ['FontSize', 14]]);
        $this->headerStyle = self::style([['FontBold', true], ['BackgroundColor', 'D9D9D9']]);
        $this->boldStyle = self::style([['FontBold', true]]);
        $this->moneyStyle = self::style([['Format', '#,##0']]);
        $this->boldMoneyStyle = self::style([['FontBold', true], ['Format', '#,##0']]);
    }

    /**
     * Builds a style with either naming of the library: "withFontBold" (immutable, newer)
     * or "setFontBold" (mutable, older). The first one that exists is used.
     *
     * @param array<int, array{0: string, 1: mixed}> $settings
     */
    private static function style(array $settings): Style
    {
        $style = new Style();

        foreach ($settings as [$name, $value]) {
            foreach (['with', 'set'] as $prefix) {
                $method = $prefix.$name;

                if (method_exists($style, $method)) {
                    $result = $style->{$method}($value);
                    if ($result instanceof Style) {
                        $style = $result;
                    }

                    continue 2;
                }
            }

            throw new \RuntimeException("The spreadsheet library has no style setting \"{$name}\" (tried with{$name} and set{$name}).");
        }

        return $style;
    }

    public function title(string $text): void
    {
        $this->writer->addRow(Row::fromValues([$text], $this->titleStyle));
    }

    public function line(string $text): void
    {
        $this->writer->addRow(Row::fromValues([$text]));
    }

    public function blank(): void
    {
        $this->writer->addRow(Row::fromValues(['']));
    }

    /** A bold row with a single text (the name of a section). */
    public function section(string $text): void
    {
        $this->writer->addRow(Row::fromValues([$text], $this->boldStyle));
    }

    /** @param array<int, string> $labels */
    public function header(array $labels): void
    {
        $this->writer->addRow(Row::fromValues($labels, $this->headerStyle));
    }

    /** @param array<int, mixed> $values */
    public function row(array $values): void
    {
        $this->add($values, null, $this->moneyStyle);
    }

    /** @param array<int, mixed> $values */
    public function total(array $values): void
    {
        $this->add($values, $this->boldStyle, $this->boldMoneyStyle);
    }

    /** Closes the file and returns its path. */
    public function finish(): string
    {
        $this->writer->close();
        $this->open = false;

        return $this->path;
    }

    /** Throws the half-written file away. */
    public function discard(): void
    {
        if ($this->open) {
            $this->open = false;

            try {
                $this->writer->close();
            } catch (\Throwable) {
                // nothing to do: the file is deleted anyway
            }
        }

        @unlink($this->path);
    }

    public function __destruct()
    {
        // an error half way must not leave temporary files behind
        if ($this->open) {
            $this->discard();
        }
    }

    /** @param array<int, mixed> $values */
    private function add(array $values, ?Style $textStyle, Style $moneyStyle): void
    {
        $cells = [];

        foreach (array_values($values) as $index => $value) {
            $isMoney = in_array($index, $this->moneyColumns, true) && (is_int($value) || is_float($value));
            $cells[] = Cell::fromValue($value ?? '', $isMoney ? $moneyStyle : $textStyle);
        }

        $this->writer->addRow(new Row($cells));
    }

    /** Sheet names: at most 31 characters, without \ / ? * [ ] : */
    private function safeName(string $name): string
    {
        return mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name), 0, 31);
    }
}