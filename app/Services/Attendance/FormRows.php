<?php

namespace App\Services\Attendance;

/**
 * The days of a timesheet as the printed form words them (Arabic), for the page, the PDF and the
 * Excel file alike, so the three never disagree.
 */
class FormRows
{
    /**
     * @param  array<string, mixed>  $sheet  from Timesheet::forEmployee()
     * @return list<array{day: string, date: string, type: string, leave_hours: float, overtime: float, working_days: ?float, note: string, off: bool, undecided: bool}>
     */
    public static function for(array $sheet): array
    {
        $t = fn (string $key, array $replace = []) => __('attendance.form.'.$key, $replace, 'ar');
        $weekdays = trans('attendance.form.weekdays', [], 'ar');

        return array_map(function (array $row) use ($t, $weekdays) {
            $kind = $row['kind'];
            $leaveName = $row['leave_type']?->name_ar ?: $row['leave_type']?->name_en;

            $type = match ($kind) {
                Timesheet::WEEKEND => $t('weekly-holiday'),
                Timesheet::HOLIDAY => $t('official-holiday'),
                Timesheet::OUTSIDE => '—',
                Timesheet::LEAVE, Timesheet::DECIDED_ABSENT => (string) $leaveName,
                default => $row['leave_hours'] > 0 ? (string) $leaveName : $t('none'),
            };

            $note = match ($kind) {
                Timesheet::WEEKEND => $t('weekly-holiday-note'),
                Timesheet::HOLIDAY => (string) $row['note'],
                Timesheet::OUTSIDE => $t('outside'),
                Timesheet::DECIDED_WORKED => trim($t('confirmed').' '.($row['note'] ?? '')),
                Timesheet::DECIDED_ABSENT => trim($t('absent').' '.($row['note'] ?? '')),
                default => '',
            };

            $undecided = in_array($kind, [Timesheet::ONE_PUNCH, Timesheet::NO_RECORD], true);

            return [
                'day' => $weekdays[$row['date']->dayOfWeek],
                'date' => $row['date']->format('d/m/Y'),
                'type' => $type,
                'leave_hours' => (float) $row['leave_hours'],
                'overtime' => round($row['overtime_minutes'] / 60, 2),
                'working_days' => $undecided ? null : round((float) $row['working_days'], 2),
                'note' => $note,
                'off' => in_array($kind, [Timesheet::WEEKEND, Timesheet::HOLIDAY], true),
                'undecided' => $undecided,
            ];
        }, $sheet['rows']);
    }
}
