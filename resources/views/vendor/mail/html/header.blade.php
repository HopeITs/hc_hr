@props(['url'])
{{-- The logo is embedded in the message itself (App\Listeners\EmbedMailLogo), so
     Outlook and Gmail show it without asking to load remote images. --}}
<tr>
<td class="header" align="center">
<a href="{{ $url }}" target="_blank" rel="noopener" style="display: inline-block;">
<img src="cid:{{ App\Listeners\EmbedMailLogo::NAME }}" class="logo" width="176" height="47" alt="{{ trim($slot) }}">
</a>
</td>
</tr>
