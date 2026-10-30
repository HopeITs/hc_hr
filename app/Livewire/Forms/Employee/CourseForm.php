<?php

namespace App\Livewire\Forms\Employee;

use App\Models\Course;
use App\Models\Employee;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

class CourseForm extends Form
{
    public ?Employee $employee = null;

    public ?Course $course = null;

    public array $courses = [];

    public function setEmployee(Employee $employee): void
    {
        $this->employee = $employee->load('courses');

        $this->courses = $employee
            ->courses()
            ->select('id', 'category_id', 'title', 'organization', 'place', 'granted_by', 'notes', 'start_date', 'end_date', 'has_certificate', 'duration', 'cost', 'status')
            ->get()
            ->map(
                fn ($c) => [
                    'id' => $c->id,
                    'category_id' => $c->category_id,
                    'title' => $c->title,
                    'organization' => $c->organization,
                    'place' => $c->place,
                    'granted_by' => $c->granted_by,
                    'notes' => $c->notes,
                    'end_date' => optional($c->end_date)->toDateString(),
                    'has_certificate' => (int) $c->has_certificate,
                    'duration' => $c->duration,
                    'status' => $c->status,
                    'course_attachment' => $c->getFirstMedia('file')
                        ? route('employee-files.download', $c->getFirstMedia('file'))
                        : null,
                    'file_name' => $c->getFirstMedia('file')->file_name ?? null,
                ],
            )
            ->toArray();
    }

    public function rules(): array
    {
        $rules = [
            'courses' => ['array'],
            'courses.*.id' => ['nullable', 'exists:courses,id'],
            'courses.*.category_id' => ['nullable', 'exists:course_categories,id'],
            'courses.*.title' => ['required', 'string', 'max:255'],
            'courses.*.organization' => ['required', 'string', 'max:255'],
            'courses.*.place' => ['nullable', 'integer', 'min:1', 'max:2'],
            'courses.*.granted_by' => ['nullable', 'string', 'max:255'],
            'courses.*.notes' => ['nullable', 'string'],
            'courses.*.end_date' => ['nullable', 'date'],
            'courses.*.has_certificate' => ['nullable', 'boolean'],
            'courses.*.duration' => ['nullable', 'string', 'max:255'],
            'courses.*.status' => ['required', 'integer', 'min:1', 'max:5'],
            'courses.*.course_attachment' => ['nullable'],
        ];

        foreach ($this->courses as $index => $course) {
            if (($course['course_attachment'] ?? null) instanceof TemporaryUploadedFile) {
                $rules["courses.{$index}.course_attachment"] = ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];
            }
        }

        return $rules;
    }

    public function addCourse(): void
    {
        $this->courses[] = [
            'id' => null,
            'category_id' => null,
            'title' => '',
            'organization' => '',
            'place' => null,
            'granted_by' => null,
            'notes' => null,
            'end_date' => null,
            'has_certificate' => 0,
            'duration' => null,
            'status' => 5,
            'course_attachment' => null,
            'file_name' => null,
        ];
    }

    public function remove(int $index, $id = null): void
    {
        if (! array_key_exists($index, $this->courses)) {
            return;
        }

        unset($this->courses[$index]);

        if (isset($id)) {
            $this->employee->courses()->findOrFail($id)->delete();
        }
        $this->courses = array_values($this->courses);
    }

    public function save(): void
    {
        $this->validate();

        foreach ($this->courses as $index => $course) {
            $data = collect($course)
                ->except(['course_attachment', 'file_name', 'id'])
                ->map(fn ($value) => $value === '' ? null : $value)
                ->toArray();

            if (array_key_exists('id', $course) && isset($course['id'])) {
                $this->course = $this->employee->courses()->findOrFail($course['id']);
                $this->course->update($data);
            } else {
                $data['employee_id'] = $this->employee->id;
                $this->course = Course::create($data);
            }

            if ($course['course_attachment'] instanceof TemporaryUploadedFile) {
                $this->course->clearMediaCollection('file');

                $this->course
                    ->addMedia($course['course_attachment']->getRealPath())
                    ->usingFileName('course-file-'.$this->course->id.'.'.$course['course_attachment']->getClientOriginalExtension())
                    ->preservingOriginal()
                    ->toMediaCollection('file', 'local');
            }
        }

        $this->setEmployee($this->employee);
    }
}
