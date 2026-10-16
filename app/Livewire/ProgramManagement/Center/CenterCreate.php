<?php

namespace App\Livewire\ProgramManagement\Center;

use App\Livewire\Concerns\ScopesToAuthorizedCity;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Center;
use App\Models\City;
use App\Models\User;
use App\Notifications\NewCenterCreated;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class CenterCreate extends Component
{
    use ScopesToAuthorizedCity;
    use WithFeedback;

    public array $item = [
        'name_en' => '',
        'name_ar' => '',
        'city_id' => '',
        'address_ar' => '',
        'address_en' => '',
        'is_active' => true,
        'weekends' => [],
        'open_at' => null,
        'close_at' => null,
    ];

    protected $rules = [
        'item.name_en' => 'required|string|max:255',
        'item.name_ar' => 'required|string|max:255',
        'item.city_id' => 'required|exists:cities,id',
        'item.address_en' => 'nullable|string|max:255',
        'item.address_ar' => 'nullable|string|max:255',
        'item.weekends' => 'required|array',
        'item.open_at' => 'required',
        'item.close_at' => 'required',
    ];

    public function save()
    {
        $this->validate();

        // 'exists:cities,id' only proves the city exists, not that this user
        // may create centers in it.
        $this->assertCityIsAuthorized($this->item['city_id']);
        Gate::authorize('create', [Center::class, (int) $this->item['city_id']]);

        $this->item['weekends'] = implode(',', $this->item['weekends']);
        $center = Center::create($this->item);
        $center->load('city');

        User::sendBroadcast(new NewCenterCreated($center), $center->city_id);

        $this->toast();

        return redirect()->route('centers.index');
    }

    public function render()
    {
        $cityIds = $this->authorizedCityIds();

        $cities = ($cityIds === null ? City::query() : City::query()->whereKey($cityIds))
            ->get(['id', transColumn('name')]);

        return view('livewire.program-management.center.center-create', compact('cities'));
    }
}
