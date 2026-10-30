<?php

namespace Tests\Feature\Assistant;

use App\Enums\LeaveRequestStatus;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Folder;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\EmployeeTools;
use App\Services\Assistant\PolicyLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The assistant against a faked Gemini. The rule that matters most: every
 * tool reads the signed-in employee's own record and nobody else's, whatever
 * the model asks for.
 */
class AssistantTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'assistant.enabled' => true,
            'assistant.gemini.key' => 'gemini-key',
            'assistant.share_salary' => true,
            'assistant.history_turns' => 10,
            'assistant.policy_char_budget' => 150000,
        ]);
    }

    public function test_a_plain_answer_is_returned_with_the_policies_and_the_employee_in_the_instruction(): void
    {
        PolicyDocument::query()->create(['source' => 'panel', 'title' => 'سياسة الإجازات', 'content' => 'يستحق الموظف ٢١ يوماً.']);
        $user = $this->employeeUser(['leaves.view-own']);

        Http::fake([self::GEMINI => Http::sequence()->push($this->textTurn('Twenty-one days.'))]);

        $answer = app(Assistant::class)->reply($user, [], 'How many leave days do I get?');

        $this->assertSame('Twenty-one days.', $answer);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $system = $request['systemInstruction']['parts'][0]['text'];

            return str_contains($system, 'Christian Hope Center')
                && str_contains($system, 'Test Person')
                && str_contains($system, now()->format('Y-m-d'))
                && str_contains($system, "### سياسة الإجازات\nيستحق الموظف ٢١ يوماً.")
                && $request['contents'] === [['role' => 'user', 'parts' => [['text' => 'How many leave days do I get?']]]]
                && collect($request['tools'][0]['functionDeclarations'])->pluck('name')->contains('get_my_profile');
        });
    }

    public function test_a_function_call_is_answered_and_fed_back_unchanged(): void
    {
        $user = $this->employeeUser(['leaves.view-own']);
        $this->balanceFor($user->profile, 'Annual', 160, 16);

        $call = ['role' => 'model', 'parts' => [[
            'functionCall' => ['name' => 'get_my_leave_balances', 'args' => []],
            'thoughtSignature' => 'sig-123',
        ]]];

        Http::fake([self::GEMINI => Http::sequence()
            ->push(['candidates' => [['content' => $call]]])
            ->push($this->textTurn('You have 18 days left.'))]);

        $answer = app(Assistant::class)->reply($user, [], 'How much leave do I have?');

        $this->assertSame('You have 18 days left.', $answer);
        Http::assertSentCount(2);

        $second = Http::recorded()[1][0];
        $this->assertSame($call, $second['contents'][1]);
        $this->assertSame('user', $second['contents'][2]['role']);

        $response = $second['contents'][2]['parts'][0]['functionResponse'];
        $this->assertSame('get_my_leave_balances', $response['name']);
        $this->assertSame('Annual', $response['response']['balances'][0]['leave_type']);
    }

    public function test_tools_never_return_another_employees_data(): void
    {
        $user = $this->employeeUser(['leaves.view-own', 'archives.view']);
        $mine = $this->balanceFor($user->profile, 'Annual', 160, 0);
        $this->requestOn($mine, '2026-03-01');

        $other = $this->makeEmployee($user->profile->city, 'colleague', [
            'first_name_en' => 'Secret', 'last_name_en' => 'Colleague',
            'first_name_ar' => 'زميل', 'last_name_ar' => 'سري',
        ]);
        $theirs = $this->balanceFor($other, 'Sick', 80, 8, ['salary' => 9999]);
        $this->requestOn($theirs, '2026-04-01');
        $otherUser = User::factory()->create(['employee_id' => $other->id]);

        Storage::fake('local');
        $shared = Folder::query()->create(['name' => 'Shared with me', 'user_id' => $otherUser->id, 'is_shared' => true]);
        $shared->users()->attach($user->id);
        $shared->addMediaFromString('x')->usingFileName('handbook.docx')->toMediaCollection('archived_files', 'local');
        $private = Folder::query()->create(['name' => 'Their folder', 'user_id' => $otherUser->id, 'is_shared' => true]);
        $private->users()->attach($otherUser->id);
        $private->addMediaFromString('x')->usingFileName('their-secret.docx')->toMediaCollection('archived_files', 'local');

        $tools = app(EmployeeTools::class);
        $everything = json_encode(collect(['get_my_profile', 'get_my_leave_balances', 'get_my_leave_requests', 'get_my_salary', 'get_my_files'])
            ->mapWithKeys(fn (string $name) => [$name => $tools->call($user, $name)])
            ->all(), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Test Person', $everything);
        $this->assertStringContainsString('2026-03-01', $everything);
        $this->assertStringContainsString('1500', $everything);
        $this->assertStringContainsString('handbook.docx', $everything);

        foreach (['Secret', 'Colleague', 'Sick', '2026-04-01', '9999', 'Their folder', 'their-secret.docx'] as $leak) {
            $this->assertStringNotContainsString($leak, $everything);
        }

        // Whatever the model passes, the tool reads the asking user's record.
        Http::fake([self::GEMINI => Http::sequence()
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'get_my_salary', 'args' => ['employee_id' => $other->id]]],
            ]]]]])
            ->push($this->textTurn('Done.'))]);

        app(Assistant::class)->reply($user, [], 'What is the salary of my colleague?');

        $response = Http::recorded()[1][0]['contents'][2]['parts'][0]['functionResponse']['response'];
        $this->assertSame(1500, (int) $response['salary']);
    }

    public function test_the_salary_tool_is_absent_when_salary_sharing_is_off(): void
    {
        config(['assistant.share_salary' => false]);
        $user = $this->employeeUser(['leaves.view-own']);
        $this->makeContractFor($user->profile);

        $tools = app(EmployeeTools::class);

        $this->assertNotContains('get_my_salary', collect($tools->declarations($user))->pluck('name'));
        $this->assertArrayHasKey('error', $tools->call($user, 'get_my_salary'));
    }

    public function test_an_unreadable_salary_is_reported_unavailable(): void
    {
        $user = $this->employeeUser();
        $contract = $this->makeContractFor($user->profile);
        // A figure encrypted under another key reads back as null.
        Contract::query()->whereKey($contract->id)->toBase()->update(['salary' => 'not-an-envelope']);

        $result = app(EmployeeTools::class)->call($user, 'get_my_salary');

        $this->assertNull($result['salary']);
        $this->assertStringContainsString('unavailable', $result['note']);
    }

    public function test_leave_tools_are_absent_without_the_permission(): void
    {
        $user = $this->employeeUser();
        $tools = app(EmployeeTools::class);
        $names = collect($tools->declarations($user))->pluck('name');

        $this->assertContains('get_my_profile', $names);
        $this->assertNotContains('get_my_leave_balances', $names);
        $this->assertNotContains('get_my_leave_requests', $names);
        $this->assertArrayHasKey('error', $tools->call($user, 'get_my_leave_requests'));
        $this->assertArrayHasKey('error', $tools->call($user, 'drop_tables'));
    }

    public function test_a_user_without_an_employee_record_gets_no_tools(): void
    {
        $user = User::factory()->create(['employee_id' => null]);

        $this->assertSame([], app(EmployeeTools::class)->declarations($user));

        Http::fake([self::GEMINI => Http::sequence()->push($this->textTurn('Hello.'))]);
        app(Assistant::class)->reply($user, [], 'Hi');

        Http::assertSent(fn (Request $request) => ! isset($request['tools']));
    }

    public function test_all_policies_go_in_when_they_fit(): void
    {
        PolicyDocument::query()->create(['source' => 'panel', 'title' => 'B policy', 'content' => 'Second.']);
        PolicyDocument::query()->create(['source' => 'panel', 'title' => 'A policy', 'content' => 'First.']);
        PolicyDocument::query()->create(['source' => 'panel', 'title' => 'Empty', 'content' => '']);

        $this->assertSame("### A policy\nFirst.\n\n### B policy\nSecond.", app(PolicyLibrary::class)->contextFor('anything'));

        PolicyDocument::query()->delete();
        $this->assertSame('', app(PolicyLibrary::class)->contextFor('anything'));
    }

    public function test_over_budget_the_best_matching_policy_goes_first_and_the_last_is_cut(): void
    {
        $travel = PolicyDocument::query()->create(['source' => 'panel', 'title' => 'سياسة السفر', 'content' => str_repeat('بدل السفر اليومي. ', 40)]);
        $leave = PolicyDocument::query()->create(['source' => 'panel', 'title' => 'سياسة الإجازات', 'content' => 'إجازة الأمومة مدتها تسعون يوماً. '.str_repeat('نص. ', 40)]);

        $leaveBlock = "### سياسة الإجازات\n".trim($leave->content);
        config(['assistant.policy_char_budget' => mb_strlen($leaveBlock) + 100]);

        // Diacritics, tatweel and alef/teh-marbuta variants still match.
        $context = app(PolicyLibrary::class)->contextFor('كم مدة اِجـازه الامومه؟');

        $this->assertStringStartsWith($leaveBlock, $context);
        $this->assertStringContainsString('### سياسة السفر', $context);
        $this->assertStringEndsWith('…', $context);
        $this->assertLessThanOrEqual(mb_strlen($leaveBlock) + 100, mb_strlen($context));

        $this->assertStringStartsWith('### سياسة السفر', app(PolicyLibrary::class)->contextFor('بدل السفر'));
        $this->assertNotNull($travel);
    }

    public function test_history_is_trimmed_and_assistant_turns_become_model_turns(): void
    {
        config(['assistant.history_turns' => 2]);
        $user = $this->employeeUser();

        Http::fake([self::GEMINI => Http::sequence()->push($this->textTurn('Third answer.'))]);

        app(Assistant::class)->reply($user, [
            ['role' => 'user', 'text' => 'First question'],
            ['role' => 'assistant', 'text' => 'First answer'],
            ['role' => 'user', 'text' => 'Second question'],
            ['role' => 'assistant', 'text' => 'Second answer'],
        ], 'Third question');

        Http::assertSent(fn (Request $request) => $request['contents'] === [
            ['role' => 'user', 'parts' => [['text' => 'Second question']]],
            ['role' => 'model', 'parts' => [['text' => 'Second answer']]],
            ['role' => 'user', 'parts' => [['text' => 'Third question']]],
        ]);
    }

    public function test_an_empty_answer_falls_back_in_the_questions_language(): void
    {
        $user = $this->employeeUser();

        Http::fake([self::GEMINI => Http::sequence()->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => '']]]]]])]);

        $this->assertStringContainsString('الموارد البشرية', app(Assistant::class)->reply($user, [], 'ما هي سياسة الإجازات؟'));
    }

    private function employeeUser(array $permissions = []): User
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'me');

        // A role of its own: the migrations give 'employee' its access-control.role_baselines.
        return $this->makeUser($city, 'assistant-test-staff', $permissions, $employee);
    }

    private function balanceFor(Employee $employee, string $type, float $deserved, float $used, array $contract = []): LeaveBalance
    {
        $contract = $this->makeContractFor($employee, $contract);
        $leaveType = LeaveType::query()->firstOrCreate(['name_en' => $type], ['name_ar' => $type, 'base_days' => 20]);

        return LeaveBalance::query()->create([
            'contract_id' => $contract->id,
            'type_id' => $leaveType->id,
            'deserved' => $deserved,
            'used' => $used,
        ]);
    }

    private function requestOn(LeaveBalance $balance, string $day): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id,
            'type' => 'day',
            'start_at' => $day,
            'end_at' => $day,
            'status' => LeaveRequestStatus::Pending,
        ]);
    }

    private function textTurn(string $text): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]]];
    }
}
