<?php

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Extends the Symfony Console Table to make it adaptive to the terminal width.
 */
class AdaptiveTable extends Table
{
    /** @var int */
    protected $maxTableWidth;

    /** @var int */
    protected $minColumnWidth;

    // The following 3 properties are copies of the private properties in the
    // parent Table class.
    protected $rowsCopy = [];
    protected $headersCopy = [];
    protected $outputCopy;

    /**
     * AdaptiveTable constructor.
     *
     * @param OutputInterface $output
     * @param int|null        $maxTableWidth
     * @param int|null        $minColumnWidth
     */
    public function __construct(OutputInterface $output, $maxTableWidth = null, $minColumnWidth = 10)
    {
        $this->outputCopy = $output;
        $this->maxTableWidth = $maxTableWidth !== null
            ? $maxTableWidth
            : (new Terminal())->getWidth();
        $this->minColumnWidth = $minColumnWidth;

        parent::__construct($output);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->addRow() so the row content can be accessed.
     */
    public function addRow($row)
    {
        if ($row instanceof TableSeparator) {
            $this->rowsCopy[] = $row;

            return parent::addRow($row);
        }

        if (!is_array($row)) {
            throw new \InvalidArgumentException('A row must be an array or a TableSeparator instance.');
        }

        $this->rowsCopy[] = array_values($row);

        return parent::addRow($row);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->setHeaders() so the header content can be accessed.
     */
    public function setHeaders(array $headers)
    {
        $headers = array_values($headers);
        if (!empty($headers) && !is_array($headers[0])) {
            $headers = array($headers);
        }

        $this->headersCopy = $headers;

        return parent::setHeaders($headers);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->render(), to adapt all the cells to the table width.
     */
    public function render()
    {
        $this->adaptRows();
        parent::render();
    }

    /**
     * Adapt rows based on the terminal width.
     */
    protected function adaptRows()
    {
        // Go through all headers and rows, wrapping their cells until each
        // column meets the max column width.
        $maxColumnWidths = $this->getMaxColumnWidths();
        $this->setRows($this->adaptCells($this->rowsCopy, $maxColumnWidths));
    }

    /**
     * Modify table rows, wrapping their cells' content to the max column width.
     *
     * @param array $rows
     * @param array $maxColumnWidths
     *
     * @return array
     */
    protected function adaptCells(array $rows, array $maxColumnWidths)
    {
        foreach ($rows as $rowNum => &$row) {
            if ($row instanceof TableSeparator) {
                continue;
            }
            foreach ($row as $column => &$cell) {
                $cellWidth = $this->getCellWidth($cell);
                if ($cellWidth > $maxColumnWidths[$column]) {
                    $cell = $this->wrapCell($cell, $maxColumnWidths[$column]);
                }
            }
        }

        return $rows;
    }

    /**
     * Word-wrap the contents of a cell, so that they fit inside a max width.
     *
     * @param string $contents
     * @param int    $width
     *
     * @return string
     */
    protected function wrapCell($contents, $width)
    {
        // Account for left-indented cells.
        if (strpos($contents, ' ') === 0) {
            $trimmed = ltrim($contents, ' ');
            $indent = Helper::strlen($contents) - Helper::strlen($trimmed);

            return str_repeat(' ', $indent) . $this->wrapWithDecoration($contents, $width - $indent);
        }

        return $this->wrapWithDecoration($contents, $width);
    }

    /**
     * Word-wraps the contents of a cell, accounting for decoration.
     *
     * @param string $formattedText
     * @param int    $maxWidth
     *
     * @return string
     */
    public function wrapWithDecoration($formattedText, $maxWidth)
    {
        $formatter = $this->outputCopy->getFormatter();
        $plainText = Helper::removeDecoration($formatter, $formattedText);
        $plainTextWrapped = wordwrap($plainText, $maxWidth, "\n", true);
        if ($plainText === $formattedText || $plainTextWrapped === $plainText) {
            return $plainTextWrapped;
        }

        // Find all open and closing tags in the formatted text.
        $tagRegex = '[a-z][a-z0-9,_=;-]*+';
        preg_match_all("#</?($tagRegex)?>#ix", $formattedText, $matches, PREG_OFFSET_CAPTURE);

        // Find all the plain-text chunks and their lengths in the text.
        $chunks = [];
        $lastTagClose = 0;
        foreach ($matches[0] as $match) {
            $chunks[$lastTagClose] = $match[1] - $lastTagClose;
            $lastTagClose = $match[1] + strlen($match[0]);
        }
        $chunks[$lastTagClose] = Helper::strlen($formattedText) - $lastTagClose;

        // Go through all the positions of new lines in the word-wrapped plain
        // text, and insert corresponding new lines in the formatted text.
        for ($offset = 0; ($position = strpos($plainTextWrapped, "\n", $offset)) !== false; $offset = $position + 1) {
            $currentChunkWidth = 0;
            foreach ($chunks as $chunkOffset => $chunkWidth) {
                if ($currentChunkWidth + $chunkWidth > $position) {
                    $formattedText = substr_replace($formattedText, "\n", $position - $currentChunkWidth + $chunkOffset, 0);
                    // PHP's wordwrap() trims each line of the text: we need to
                    // do the same.
                    $formattedText = preg_replace('#[ \t]*\n[ \t]*#', "\n", $formattedText);
                    continue 2;
                }
                $currentChunkWidth += $chunkWidth;
            }
        }

        $formattedText = preg_replace("#(<$tagRegex>)([^\n<]*)\n#", "\$1\$2</>\n\$1", $formattedText);

        return $formattedText;
    }

    /**
     * @return array
     *   An array of the maximum column widths that fit into the table width,
     *   keyed by the column number.
     */
    protected function getMaxColumnWidths()
    {
        // Loop through the table rows and headers, building multidimensional
        // arrays of the 'original' and 'minimum' column widths. In the same
        // loop, build a count of the number of columns.
        $originalColumnWidths = [];
        $minColumnWidths = [];
        $columnCounts = [0];
        foreach (array_merge($this->rowsCopy, $this->headersCopy) as $rowNum => $row) {
            if ($row instanceof TableSeparator) {
                continue;
            }
            $columnCount = 0;
            foreach ($row as $column => $cell) {
                $columnCount += $column instanceof TableCell ? $column->getColspan() - 1 : 1;

                // The column width is the width of the widest cell.
                $cellWidth = $this->getCellWidth($cell);
                if (!isset($originalColumnWidths[$column]) || $originalColumnWidths[$column] < $cellWidth) {
                    $originalColumnWidths[$column] = $cellWidth;
                }

                // Find the minimum width of the cell. The default is configured
                // in minColumnWidth, but this is overridden for non-wrapping
                // cells and very narrow cells. Additionally, table headers are
                // never wrapped.
                $minCellWidth = $this->minColumnWidth;
                if ($cellWidth < $this->minColumnWidth
                    || ($cell instanceof AdaptiveTableCell && !$cell->canWrap())
                    || !isset($this->rowsCopy[$rowNum])) {
                    $minCellWidth = $cellWidth;
                }

                // The minimum column width is the greatest minimum cell width.
                if (!isset($minColumnWidths[$column]) || $minColumnWidths[$column] < $minCellWidth) {
                    $minColumnWidths[$column] = $minCellWidth;
                }
            }
            $columnCounts[] = $columnCount;
        }

        // Find the number of columns in the table. This uses the same process
        // as the parent private method Table->calculateNumberOfColumns().
        $columnCount = max($columnCounts);

        // Find the maximum width for each column's content, to fit into the
        // calculated maximum content width.
        $maxContentWidth = $this->getMaxContentWidth($columnCount);
        $maxColumnWidths = [];
        $totalWidth = array_sum($originalColumnWidths);
        asort($originalColumnWidths, SORT_NUMERIC);
        foreach ($originalColumnWidths as $column => $columnWidth) {
            $columnRatio = ($maxContentWidth / $totalWidth) * $columnWidth;
            $maxColumnWidth = round($columnRatio);

            // Do not change the width of columns which are already narrower
            // than the minimum.
            if (isset($minColumnWidths[$column]) && $maxColumnWidth < $minColumnWidths[$column]) {
                $maxColumnWidth = $minColumnWidths[$column];
            }

            $maxColumnWidths[$column] = $maxColumnWidth;
            $totalWidth -= $columnWidth;
            $maxContentWidth -= $maxColumnWidth;
        }

        return $maxColumnWidths;
    }

    /**
     * Find the maximum content width (excluding decoration) for each table row.
     *
     * @param int $columnCount
     *   The number of columns in the table.
     *
     * @return int
     *   The maximum table width, minus the width taken up by decoration.
     */
    protected function getMaxContentWidth($columnCount)
    {
        $style = $this->getStyle();
        $verticalBorderQuantity = $columnCount + 1;
        $paddingQuantity = $columnCount * 2;

        return $this->maxTableWidth
            - $verticalBorderQuantity * strlen($style->getVerticalBorderChar())
            - $paddingQuantity * strlen($style->getPaddingChar());
    }

    /**
     * Get the default width of a table cell (the length of its longest line).
     *
     * This is inspired by Table->getCellWidth(), but this also accounts for
     * multi-line cells.
     *
     * @param string|TableCell $cell
     *
     * @return float|int
     */
    private function getCellWidth($cell)
    {
        $lineWidths = [0];
        foreach (explode(PHP_EOL, $cell) as $line) {
            $lineWidths[] = Helper::strlenWithoutDecoration($this->outputCopy->getFormatter(), $line);
        }
        $cellWidth = max($lineWidths);
        if ($cell instanceof TableCell && $cell->getColspan() > 1) {
            $cellWidth /= $cell->getColspan();
        }

        return $cellWidth;
    }
}
