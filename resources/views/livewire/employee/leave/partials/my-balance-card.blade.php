{{-- One balance on "My leaves", in whole hours (LeaveBalance::*_rounded): the employee's own view. --}}
<x-leave-card
    class="{{ $balance->isAnnual() ? 'col-span-1 sm:col-span-2' : 'col-span-1' }}"
    :title="$balance->leaveType->name"
    :total="$balance->deserved_rounded"
    :remaining="$balance->available_rounded"
    :total-days="$balance->intTotal"
    :remaining-days="$balance->int_available"
    :is-annual="$balance->isAnnual()"
    :used="$balance->used_rounded"
    :pending="$balance->pending_rounded"
/>
