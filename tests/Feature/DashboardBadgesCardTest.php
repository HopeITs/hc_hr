<?php

namespace Tests\Feature;

use App\Enums\BadgeType;
use App\Livewire\Dashboard\BadgesCard;
use App\Models\Badge;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The dashboard's "Badges and warnings" card is personal: every signed-in
 * user, whatever their role, sees their own badges and nobody else's. The
 * organisation-wide view, scoped by role, is the Badges page.
 */
class DashboardBadgesCardTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_every_role_sees_only_their_own_badges(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $colleagueBadge = $this->badgeFor($this->makeEmployee($aleppo, 'colleague'));

        foreach (['employee', 'manager', 'HR-ALP', 'HR-SYR', 'general-manager'] as $role) {
            $self = $this->makeEmployee($aleppo, 'self.'.strtolower($role));
            $ownBadge = $this->badgeFor($self);

            Livewire::actingAs($this->makeUser($aleppo, $role, [], $self))
                ->test(BadgesCard::class)
                ->assertSee(__('general.badges-warnings'))
                ->assertViewHas('myBadges', fn ($badges) => $badges->pluck('id')->all() === [$ownBadge->id])
                ->assertViewHas('myBadges', fn ($badges) => ! $badges->contains('id', $colleagueBadge->id));
        }
    }

    public function test_the_card_shows_for_a_user_without_badges(): void
    {
        $aleppo = $this->makeCity('ALP', '001');

        Livewire::actingAs($this->makeUser($aleppo, 'employee'))
            ->test(BadgesCard::class)
            ->assertSee(__('general.badges-warnings'))
            ->assertViewHas('myBadges', fn ($badges) => $badges->isEmpty());
    }

    private function badgeFor(Employee $employee): Badge
    {
        return $employee->badges()->create([
            'type' => BadgeType::Warning,
            'date' => now(),
            'reason' => 'Badge reason',
        ]);
    }
}
