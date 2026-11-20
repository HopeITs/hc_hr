<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Reads the attendance report exported from ZKTeco's Attendance Management program
 * (columns AC-No, Name, Time, State, ...), as .xls, .xlsx or .csv, and returns one entry per
 * punch: the device number and the time.
 *
 * The program's own .xls export is an old Excel 5 stream whose cells are all text; neither
 * PhpSpreadsheet nor current Excel will open it, so it is read directly (see readRawLabels()).
 * Everything else goes through PhpSpreadsheet.
 */
class DeviceReportReader
{
    private const DEVICE_HEADERS = ['ac-no', 'ac no', 'acno', 'ac-no.', 'badgenumber', 'user id', 'userid', 'person id', 'employee id'];

    private const TIME_HEADERS = ['time', 'datetime', 'date/time', 'date time', 'checktime', 'punch time'];

    private const FORMATS = ['d/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i', 'd-m-Y H:i:s', 'm/d/Y H:i', 'Y/m/d H:i:s', 'Y/m/d H:i'];

    /**
     * @return array{punches: list<array{device_no: string, at: CarbonImmutable}>, invalid: int}
     *
     * @throws UnreadableReport when the file is not a recognisable attendance report
     */
    public function read(string $path): array
    {
        $rows = $this->rows($path);
        [$headerAt, $deviceCol, $timeCol] = $this->findHeader($rows);

        $punches = [];
        $invalid = 0;

        foreach (array_slice($rows, $headerAt + 1) as $row) {
            $device = trim((string) ($row[$deviceCol] ?? ''));
            $rawTime = $row[$timeCol] ?? null;

            if ($device === '' && ($rawTime === null || $rawTime === '')) {
                continue;
            }

            $at = $this->parseTime($rawTime);

            if ($device === '' || $at === null) {
                $invalid++;

                continue;
            }

            $punches[] = ['device_no' => $device, 'at' => $at];
        }

        return ['punches' => $punches, 'invalid' => $invalid];
    }

    /** @return list<array<int, mixed>> */
    private function rows(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        // A raw Excel 5 stream starts with a BOF record (0x0809 / 0x0409 / 0x0209), not with an
        // OLE container (D0 CF 11 E0) or a zip (PK).
        if (strlen($head) >= 4 && in_array(unpack('v', $head)[1], [0x0809, 0x0409, 0x0209], true)) {
            return $this->readRawLabels($path);
        }

        try {
            return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable $e) {
            throw new UnreadableReport('The file could not be read as a spreadsheet.', 0, $e);
        }
    }

    /**
     * Cells of a raw Excel stream made only of text records (0x0004): row, column, three attribute
     * bytes, a one byte length and the text. Text with Arabic letters is Windows-1256.
     *
     * @return list<array<int, string>>
     */
    private function readRawLabels(string $path): array
    {
        $data = (string) file_get_contents($path);
        $size = strlen($data);
        $pos = 0;
        $table = [];

        while ($pos + 4 <= $size) {
            $id = unpack('v', substr($data, $pos, 2))[1];
            $len = unpack('v', substr($data, $pos + 2, 2))[1];
            $body = substr($data, $pos + 4, $len);
            $pos += 4 + $len;

            if ($id !== 0x0004 || strlen($body) < 8) {
                continue;
            }

            $row = unpack('v', substr($body, 0, 2))[1];
            $col = unpack('v', substr($body, 2, 2))[1];
            $text = substr($body, 8, ord($body[7]));

            $table[$row][$col] = preg_match('/[\x80-\xFF]/', $text)
                ? mb_convert_encoding($text, 'UTF-8', 'Windows-1256')
                : $text;
        }

        ksort($table);

        return array_values(array_map(function (array $cells): array {
            ksort($cells);

            return $cells;
        }, $table));
    }

    /** @return array{0: int, 1: int, 2: int} the header row and the device and time columns */
    private function findHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 15, true) as $at => $row) {
            $device = $time = null;

            foreach ($row as $col => $cell) {
                $name = mb_strtolower(trim((string) $cell));

                if ($device === null && in_array($name, self::DEVICE_HEADERS, true)) {
                    $device = $col;
                }

                if ($time === null && in_array($name, self::TIME_HEADERS, true)) {
                    $time = $col;
                }
            }

            if ($device !== null && $time !== null) {
                return [$at, $device, $time];
            }
        }

        throw new UnreadableReport('No "AC-No" and "Time" columns were found. Is this the attendance report?');
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $zone = config('app.timezone');

        if (is_numeric($value) && (float) $value > 20000) {
            // An Excel serial is a wall-clock time with no zone: read it as the app's own zone.
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value, $zone));
        }

        $text = trim((string) $value);

        foreach (self::FORMATS as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $text, $zone);
            } catch (Throwable) {
                continue; // Carbon throws on text that does not fit the format
            }

            if ($parsed !== false && $parsed->format($format) === $text) {
                return $parsed;
            }
        }

        return null;
    }
}
