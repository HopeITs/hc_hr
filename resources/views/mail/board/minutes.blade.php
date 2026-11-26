<x-mail::message>
<div dir="rtl" style="text-align:right">

محضر اجتماع مجلس الإدارة رقم {{ $number }} المنعقد يوم {{ $dateAr }} مرفق بصيغة PDF، ويمكن قراءته أيضاً في النظام.

</div>

The minutes of board meeting no. {{ $number }} ({{ $dateEn }}) are attached as a PDF and can also be read in the system.

<x-mail::button :url="$url">
قراءة المحضر | Read the minutes
</x-mail::button>
</x-mail::message>
