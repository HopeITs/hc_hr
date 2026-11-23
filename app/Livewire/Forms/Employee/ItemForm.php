<?php

namespace App\Livewire\Forms\Employee;

use App\Models\Employee;
use Livewire\Form;

class ItemForm extends Form
{
    public ?Employee $employee = null;

    public array $items = [];

    public function setEmployee(Employee $employee): void
    {
        $this->employee = $employee;
        $this->items = $employee->items()->get()->map(fn ($item) => [
            'id' => $item->id,
            'item' => $item->item,
            'brand' => $item->brand,
            'model' => $item->model,
            'serial_number' => $item->serial_number,
            'status' => $item->status,
            'notes' => $item->notes,
        ])->toArray();
    }

    public function addItem(): void
    {
        $this->items[] = [
            'id' => null,
            'item' => '',
            'brand' => '',
            'model' => '',
            'serial_number' => '',
            'status' => '',
            'notes' => '',
        ];
    }

    public function removeItem(int $index, ?int $id = null): void
    {
        // Within this employee's own items, like the other employee forms (HR-014).
        if ($id) {
            $this->employee?->items()->find($id)?->delete();
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(): void
    {
        if (! $this->employee) {
            return;
        }

        foreach ($this->items as $data) {
            // Through the relation: an id that belongs to another employee is not found
            // here, so it can never rewrite (or move) that employee's item.
            $this->employee->items()->updateOrCreate(
                ['id' => $data['id']],
                [
                    'item' => $data['item'],
                    'brand' => $data['brand'],
                    'model' => $data['model'],
                    'serial_number' => $data['serial_number'],
                    'status' => $data['status'],
                    'notes' => $data['notes'],
                ],
            );
        }
    }
}
