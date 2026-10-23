<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * A4: the Arabic interface carries the same meanings as the English one.
 *
 * The parity check is only a guard. The other tests check what an Arabic
 * reader actually gets: plural forms, durations, validation sentences and
 * server errors, none of which may surface as a raw translation key.
 */
class A4BilingualTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    /**
     * Keys that exist in one language only, on purpose. general.team1 is a
     * line of the Arabic-only volunteer contract body (the same family as
     * general.text*), which is printed in Arabic whatever the UI language.
     */
    private const ONE_LANGUAGE_ONLY = ['general.team1'];

    public function test_every_php_lang_group_has_the_same_keys_in_both_languages(): void
    {
        $missing = [];

        foreach (glob(lang_path('en/*.php')) as $enFile) {
            $group = basename($enFile, '.php');
            $arFile = lang_path("ar/{$group}.php");
            $this->assertFileExists($arFile, "lang/ar/{$group}.php is missing");

            $en = array_keys(Arr::dot(require $enFile));
            $ar = array_keys(Arr::dot(require $arFile));

            foreach (array_diff($en, $ar) as $key) {
                $missing[] = "ar lacks {$group}.{$key}";
            }
            foreach (array_diff($ar, $en) as $key) {
                $missing[] = "en lacks {$group}.{$key}";
            }
        }

        $missing = array_values(array_filter(
            $missing,
            fn (string $line) => ! in_array(substr($line, strpos($line, 'lacks ') + 6), self::ONE_LANGUAGE_ONLY, true),
        ));

        $this->assertSame([], $missing);
    }

    public function test_arabic_counts_use_all_six_plural_forms(): void
    {
        app()->setLocale('ar');

        $expected = [
            'days' => [0 => '0 يوم', 1 => 'يوم واحد', 2 => 'يومان', 3 => '3 أيام', 11 => '11 يومًا', 100 => '100 يوم'],
            'years' => [0 => '0 سنة', 1 => 'سنة واحدة', 2 => 'سنتان', 3 => '3 سنوات', 11 => '11 سنة', 100 => '100 سنة'],
            'hours' => [0 => '0 ساعة', 1 => 'ساعة واحدة', 2 => 'ساعتان', 3 => '3 ساعات', 11 => '11 ساعة', 100 => '100 ساعة'],
        ];

        foreach ($expected as $unit => $cases) {
            foreach ($cases as $n => $text) {
                $this->assertSame($text, trans_choice("general.count.{$unit}", $n), "{$unit} × {$n}");
            }
        }
    }

    public function test_english_counts_use_singular_and_plural(): void
    {
        app()->setLocale('en');

        $this->assertSame('1 Day', trans_choice('general.count.days', 1));
        $this->assertSame('2 Days', trans_choice('general.count.days', 2));
        $this->assertSame('1 Year', trans_choice('general.count.years', 1));
        $this->assertSame('2 Hours', trans_choice('general.count.hours', 2));
    }

    public function test_leave_balance_figures_read_as_natural_arabic(): void
    {
        $balance = $this->makeAnnualBalance();
        app()->setLocale('ar');

        // 40 weekly hours = 8 hours a day.
        $this->assertSame('يوم واحد', $this->plain($balance->fill(['deserved' => 8])->deserved_formatted));
        $this->assertSame('يومان', $this->plain($balance->fill(['deserved' => 16])->deserved_formatted));
        $this->assertSame('3 أيام', $this->plain($balance->fill(['deserved' => 24])->deserved_formatted));
        $this->assertSame('12 يومًا', $this->plain($balance->fill(['deserved' => 96])->deserved_formatted));
        $this->assertSame('يومان و3 ساعات', $this->plain($balance->fill(['deserved' => 19])->deserved_formatted));
        $this->assertSame('0 يوم', $this->plain($balance->fill(['used' => 0])->used_formatted));

        app()->setLocale('en');
        $this->assertSame('1 Day', $this->plain($balance->fill(['deserved' => 8])->deserved_formatted));
        $this->assertSame('3 Days', $this->plain($balance->fill(['deserved' => 24])->deserved_formatted));
    }

    public function test_leave_request_duration_reads_as_natural_arabic(): void
    {
        $balance = $this->makeAnnualBalance();
        app()->setLocale('ar');

        // Monday to Wednesday: three working days.
        $monday = now()->next('Monday')->startOfDay();
        $this->assertSame('3 أيام', $this->requestOn($balance, 'day', $monday, $monday->copy()->addDays(2))->duration);
        $this->assertSame('يوم واحد', $this->requestOn($balance, 'day', $monday, $monday->copy())->duration);
        $this->assertSame('ساعتان', $this->requestOn($balance, 'hour', $monday->copy()->setTime(9, 0), $monday->copy()->setTime(11, 0))->duration);
        $this->assertSame('ساعة واحدة و30 دقيقة', $this->requestOn($balance, 'hour', $monday->copy()->setTime(9, 0), $monday->copy()->setTime(10, 30))->duration);
    }

    public function test_years_of_service_read_as_natural_arabic(): void
    {
        $city = $this->makeCity('ALP', '001');
        app()->setLocale('ar');

        $one = $this->makeEmployee($city, 'one-year', ['joined_at' => today()->subYear()]);
        $this->assertSame('سنة واحدة', $one->years_of_service_formatted);

        $longer = $this->makeEmployee($city, 'two-years', ['joined_at' => today()->subYears(2)->subMonths(3)]);
        $this->assertSame('سنتان و3 أشهر', $longer->years_of_service_formatted);

        app()->setLocale('en');
        $this->assertSame('2 Years 3 Months', $longer->fresh()->years_of_service_formatted);
    }

    public function test_an_empty_leave_request_is_rejected_with_arabic_sentences(): void
    {
        $balance = $this->makeAnnualBalance();
        $user = $this->makeUser($balance->contract->employee->city, 'employee', ['leaves.create'], $balance->contract->employee);
        app()->setLocale('ar');

        $component = Livewire::actingAs($user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.reason', '')
            ->call('processSave')
            ->assertHasErrors(['form.reason', 'form.leave_balance_id']);

        $messages = $component->errors()->all();
        $this->assertNotEmpty($messages);

        foreach ($messages as $message) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message, "not Arabic: {$message}");
            $this->assertDoesNotMatchRegularExpression('/\b(validation|contracts|leaves|general)\.[\w.-]+/', $message, "raw key: {$message}");
            $this->assertStringNotContainsString('form.', $message, "field path leaked: {$message}");
        }

        // An empty field says it is required, naming the field in Arabic.
        $this->assertSame(
            __('validation.required', ['attribute' => __('general.reason')]),
            $component->errors()->first('form.reason'),
        );
        $this->assertSame(
            __('validation.required', ['attribute' => trans_choice('general.leave-type', 1)]),
            $component->errors()->first('form.leave_balance_id'),
        );
    }

    public function test_a_full_position_is_refused_with_a_translated_sentence(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'capacity');
        $position = $this->makePosition($city, ['max_emp' => 1, 'exist_emp' => 1]);
        $type = ContractType::query()->firstOrCreate(['name_en' => 'Volunteer Contract'], ['name_ar' => 'عقد تطوّع']);

        // The observer skips its capacity check for console imports; this
        // request path is the one a user reaches from the browser.
        $this->runAsWebRequest();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            try {
                Contract::query()->create([
                    'employee_id' => $employee->id,
                    'position_id' => $position->id,
                    'contract_type_id' => $type->id,
                    'start_date' => '2026-01-01',
                    'end_date' => '2026-12-31',
                    'currency' => 2,
                    'weekly_hours' => 40,
                ]);
                $this->fail('A contract on a full position was accepted.');
            } catch (ValidationException $e) {
                $message = $e->errors()['form.position_id'][0] ?? '';
                $this->assertSame(__('contracts.errors.position-full'), $message);
                $this->assertStringNotContainsString('contracts.errors', $message);
            }
        }

        app()->setLocale('ar');
        $this->assertSame('لا توجد شواغر متبقية في هذا المسمى الوظيفي.', __('contracts.errors.position-full'));
    }

    public function test_leave_status_names_follow_the_interface_language(): void
    {
        $cases = [
            LeaveRequestStatus::Pending => ['قيد المراجعة', 'Pending'],
            LeaveRequestStatus::Accepted => ['مقبول', 'Accepted'],
            LeaveRequestStatus::Rejected => ['مرفوض', 'Rejected'],
        ];

        foreach ($cases as $status => [$ar, $en]) {
            app()->setLocale('ar');
            $this->assertSame($ar, LeaveRequestStatus::getLocalizedName($status)['value']);
            app()->setLocale('en');
            $this->assertSame($en, LeaveRequestStatus::getLocalizedName($status)['value']);
        }
    }

    private function makeAnnualBalance(): LeaveBalance
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'leave-'.uniqid());
        $contract = $this->makeContractFor($employee, ['start_date' => now()->subMonth(), 'end_date' => now()->addYear()]);

        DB::table('leave_types')->insertOrIgnore([
            'id' => LeaveTypeCases::Annual,
            'name_en' => 'Annual Leave',
            'name_ar' => 'إجازة سنوية',
            'base_days' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contract->id,
            'type_id' => LeaveTypeCases::Annual,
            'deserved' => 160,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return LeaveBalance::query()->with('contract.employee.city')->findOrFail($id);
    }

    private function requestOn(LeaveBalance $balance, string $type, $start, $end): LeaveRequest
    {
        $id = DB::table('leave_requests')->insertGetId([
            'leave_balance_id' => $balance->id,
            'type' => $type,
            'start_at' => $start,
            'end_at' => $end,
            'reason' => 'A4 duration check.',
            'status' => LeaveRequestStatus::Pending,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return LeaveRequest::query()->findOrFail($id);
    }

    private function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
    }

    private function runAsWebRequest(): void
    {
        $property = new ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, false);
    }
}
