<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractCreate;
use App\Livewire\Contract\ContractRenewModal;
use App\Livewire\Employee\Leave\AllLeaveRequestModule;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Support\UnknownOutcome;
use Tests\TestCase;

/**
 * X1-I: the "result unknown" contract shared by every reconcilable write.
 * The behaviour of each reconcileOperation() is tested next to the workflow it
 * belongs to (leave submit/decision, contract create, renewal).
 */
class OperationRecoveryTest extends TestCase
{
    public function test_every_registered_write_has_a_reconcile_lookup(): void
    {
        foreach (array_keys(UnknownOutcome::WRITES) as $class) {
            $this->assertTrue(method_exists($class, 'reconcileOperation'), "$class must define reconcileOperation()");
        }

        foreach (UnknownOutcome::WRITES as $class => $actions) {
            foreach ($actions as $action) {
                $this->assertTrue(method_exists($class, $action), "$class::$action must exist");
            }
        }
    }

    public function test_the_browser_registry_uses_livewire_component_names(): void
    {
        $registry = UnknownOutcome::registry();

        $this->assertSame(['processSave'], $registry['employee.leave.my-leave-request-module']);
        $this->assertSame(['submitDecision'], $registry['employee.leave.all-leave-request-module']);
        $this->assertSame(['submit'], $registry['contract.contract-create']);
        $this->assertSame(['renewAsIs'], $registry['contract.contract-renew-modal']);

        foreach ([MyLeaveRequestModule::class, AllLeaveRequestModule::class, ContractCreate::class, ContractRenewModal::class] as $class) {
            $this->assertArrayHasKey(
                app('livewire.factory')->resolveComponentName($class),
                $registry,
            );
        }
    }

    public function test_the_unknown_result_strings_exist_in_english_and_arabic(): void
    {
        foreach (['message', 'check', 'applied', 'not-applied', 'decided', 'undecided'] as $key) {
            $en = trans("general.unknown-result.$key", [], 'en');
            $ar = trans("general.unknown-result.$key", [], 'ar');

            $this->assertNotSame("general.unknown-result.$key", $en);
            $this->assertNotSame("general.unknown-result.$key", $ar);
            $this->assertNotSame($en, $ar, "$key must be translated");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar);
        }
    }

    public function test_the_layout_hands_the_browser_the_text_and_the_registry(): void
    {
        $html = view('components.confirm-dialog')->render();

        $this->assertStringContainsString('unknownResult', $html);
        $this->assertStringContainsString('checkResult', $html);
        $this->assertStringContainsString('contract.contract-create', $html);
    }
}
