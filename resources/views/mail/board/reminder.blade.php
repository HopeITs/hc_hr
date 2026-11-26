<x-mail::message>
<div dir="rtl" style="text-align:right">

تذكير باجتماع مجلس الإدارة رقم {{ $number }} غداً، {{ $dateAr }} الساعة {{ $time }}@if($place) في {{ $place }}@endif.

إن لم تستطع الحضور، أرسل طلب التغيّب من صفحة الاجتماع. الدعوة مرفقة لإضافتها إلى رزنامتك.

</div>

Reminder: board meeting no. {{ $number }} is tomorrow, {{ $dateEn }} at {{ $time }}. The invitation is attached for your calendar.

<x-mail::button :url="$url">
صفحة الاجتماع | Open the meeting
</x-mail::button>
</x-mail::message>
