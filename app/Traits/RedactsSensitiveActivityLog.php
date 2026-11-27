<?php

namespace App\Traits;

use Spatie\Activitylog\Contracts\Activity;

/**
 * Records *that* a sensitive field changed without recording its value.
 *
 * The activity log was copying salaries, national ID numbers, home addresses,
 * dates of birth, relatives' phone numbers, and free-text notes into a table
 * with a much broader audience and a much longer life than the record itself.
 * The audit requirement is "who changed what, when" — the changed field name
 * satisfies that; the value does not, and the current value is one join away
 * for anyone actually authorized to see it.
 *
 * Usage: list the field names in $sensitiveLogAttributes. Their names then
 * appear under the `sensitive_fields_changed` property on the activity record.
 * A sensitive field may also be in logOnly() — needed when a change to it
 * alone must still write an entry (logOnlyDirty + dontSubmitEmptyLogs would
 * otherwise drop it): its value under `attributes` / `old` is replaced with
 * the same marker `security:purge-activity-log` writes.
 */
trait RedactsSensitiveActivityLog
{
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $sensitive = $this->sensitiveLogAttributes ?? [];

        if ($sensitive === []) {
            return;
        }

        $properties = $activity->properties ?? collect();

        foreach (['attributes', 'old'] as $bucket) {
            $values = $properties->get($bucket);

            if (! is_array($values)) {
                continue;
            }

            foreach ($sensitive as $field) {
                if (array_key_exists($field, $values) && $values[$field] !== null) {
                    $values[$field] = '[redacted]';
                }
            }

            $properties = $properties->put($bucket, $values);
        }

        if ($eventName !== 'deleted') {
            $changed = array_values(array_intersect($sensitive, array_keys($this->getDirty())));

            if ($changed !== []) {
                $properties = $properties->put('sensitive_fields_changed', $changed);
            }
        }

        $activity->properties = $properties;
    }
}
