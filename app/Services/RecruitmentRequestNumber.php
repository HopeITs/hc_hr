<?php

namespace App\Services;

use App\Models\City;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use Illuminate\Support\Str;

/**
 * A request number short enough to say out loud.
 *
 * The old numbers were a ULID behind a prefix — unique, sortable, and
 * impossible to read over the phone or write on a form. These are the
 * governorate's initial, the programme's initial, and a counter:
 *
 *     HS-001    Homs, Study Zone, the first
 *     DL-014    Damascus, LEAP, the fourteenth
 *
 * The counter runs per pair rather than globally, so each governorate's
 * numbering stays its own and small numbers stay small. Three digits is the
 * shape, not the limit: a pair that reaches a thousand gets four, because a
 * number that cannot be issued is worse than one that is a character longer.
 *
 * Numbers already issued are never rewritten. Whatever is printed, filed or
 * quoted in an email still has to find its request.
 */
class RecruitmentRequestNumber
{
    /** Where a letter cannot be derived. */
    private const UNKNOWN = 'X';

    /**
     * The next number for this request's governorate and programme.
     *
     * Generated inside the caller's transaction and under a lock on the rows
     * that share its prefix, so two requests raised in the same instant cannot
     * be handed the same counter.
     */
    public function next(?int $cityId, ?int $programId): string
    {
        $prefix = $this->initial(City::query()->find($cityId)?->name_en)
            .$this->initial(Program::query()->find($programId)?->name);

        $last = RecruitmentRequest::query()
            ->where('request_number', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByRaw('LENGTH(request_number) desc')
            ->orderBy('request_number', 'desc')
            ->value('request_number');

        $next = $last === null ? 1 : ((int) Str::afterLast($last, '-')) + 1;

        // Three digits normally; wider only once the pair outgrows them.
        return $prefix.'-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * The programme a request belongs to, for numbering.
     *
     * A new post names its programme on the form. Every other type takes it
     * from the post being recruited for, which is where the programme actually
     * lives.
     *
     * @param  array<string, mixed>  $payload
     */
    public function programIdFor(array $payload, ?int $targetPositionId): ?int
    {
        if (! empty($payload['program_id'])) {
            return (int) $payload['program_id'];
        }

        return Position::query()->whereKey($targetPositionId)->value('program_id');
    }

    /**
     * The first Latin letter of a name, upper-cased.
     *
     * Only A–Z: a name that begins with a digit, a bracket or Arabic gives no
     * usable initial, and a number nobody can pronounce defeats the point.
     */
    private function initial(?string $name): string
    {
        if (! is_string($name)) {
            return self::UNKNOWN;
        }

        return preg_match('/[A-Za-z]/', $name, $match) === 1
            ? Str::upper($match[0])
            : self::UNKNOWN;
    }
}
