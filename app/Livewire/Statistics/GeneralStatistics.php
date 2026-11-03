<?php

namespace App\Livewire\Statistics;

use Livewire\Component;

/** The statistics page: a frame for the statistics cards, each of which loads its own data. */
class GeneralStatistics extends Component
{
    public function render()
    {
        return view('livewire.general-statistics.general-statistics');
    }
}
