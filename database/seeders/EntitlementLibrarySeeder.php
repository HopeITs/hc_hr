<?php

namespace Database\Seeders;

use App\Models\Entitlement;
use App\Models\JobLevel;
use App\Models\MailList;
use App\Models\Position;
use App\Models\PositionEntitlement;
use Illuminate\Database\Seeder;

/**
 * Starter items for the Permissions Library (meeting 19/11, track 5), from the
 * meeting's example: an IT Specialist's databases, Telegram groups, the it@
 * list and IT maintenance, and the center's cameras for managers. Run by hand
 * (php artisan db:seed --class=EntitlementLibrarySeeder); safe to run again.
 * Every name stays editable on the library page.
 */
class EntitlementLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $itList = MailList::query()->where('address', 'like', 'it@%')->first();

        $it = [
            $this->item('HR system database', 'قاعدة بيانات نظام الموارد البشرية', 'database'),
            $this->item('IT team Telegram group', 'مجموعة تيليغرام فريق IT', 'telegram'),
            $this->item('it@ mail list', 'قائمة البريد it@', 'mail_list', $itList?->id),
            $this->item('IT maintenance: printers and ink', 'صيانة IT: الطابعات والأحبار', 'it_maintenance'),
        ];
        $cameras = $this->item("Center's cameras", 'كاميرات المركز', 'camera');

        Position::query()
            ->where(fn ($q) => $q->where('title_en', 'like', '%IT Specialist%')->orWhere('title_ar', 'like', '%أخصائي IT%'))
            ->pluck('id')
            ->each(function (int $positionId) use ($it): void {
                foreach ($it as $entitlement) {
                    PositionEntitlement::query()->firstOrCreate(['entitlement_id' => $entitlement->id, 'position_id' => $positionId]);
                }
            });

        JobLevel::query()
            ->where('name_en', 'like', '%Manager%')
            ->pluck('id')
            ->each(fn (int $levelId) => PositionEntitlement::query()->firstOrCreate(['entitlement_id' => $cameras->id, 'job_level_id' => $levelId]));
    }

    private function item(string $en, string $ar, string $category, ?int $mailListId = null): Entitlement
    {
        return Entitlement::query()->firstOrCreate(
            ['name_en' => $en],
            ['name_ar' => $ar, 'category' => $category, 'mail_list_id' => $mailListId, 'is_active' => true],
        );
    }
}
