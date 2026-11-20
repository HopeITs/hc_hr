<?php

namespace Database\Seeders;

use App\Models\ChecklistItem;
use Illuminate\Database\Seeder;

class CheckListItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = config('samples.sampleData.checkListsItems');

        foreach ($items as $item) {
            ChecklistItem::create([
                'name' => $item['name'],
                'order' => $item['order'],
                'onboarding_group' => $item['order'] === 3
                    ? ChecklistItem::GROUP_EXIT
                    : (ChecklistItem::GROUP_BY_NAME[$item['name']] ?? ChecklistItem::GROUP_OTHER),
            ]);
        }
    }
}
