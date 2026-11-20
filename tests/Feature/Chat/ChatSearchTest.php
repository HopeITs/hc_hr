<?php

namespace Tests\Feature\Chat;

use App\Services\Chat\ChatSearchIndex;
use Illuminate\Http\UploadedFile;

class ChatSearchTest extends ChatTestCase
{
    public function test_part_of_an_arabic_word_finds_it_whatever_the_article_or_letter_forms(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $one = $this->chat()->send($a, $chat, 'تم إرسال العقد اليوم');
        $two = $this->chat()->send($b, $chat, 'وصلني عقدين جداد');
        $this->chat()->send($a, $chat, 'اجتماع بكرا الساعة تسعة');

        $ids = $this->search($a, 'عقد')->pluck('id')->sort()->values()->all();
        $this->assertSame([$one->id, $two->id], $ids);

        // "العقد" and "عقد" are the same search.
        $this->assertSame([$one->id, $two->id], $this->search($a, 'العقد')->pluck('id')->sort()->values()->all());
    }

    public function test_hamza_taa_marbuta_and_diacritics_do_not_matter(): void
    {
        [$chat, $a] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, 'أَحْمَد سيرسل الفاتورة');

        $this->assertSame([$message->id], $this->search($a, 'احمد')->pluck('id')->all());
        $this->assertSame([$message->id], $this->search($a, 'فاتوره')->pluck('id')->all());
    }

    public function test_english_is_case_insensitive_and_matches_part_of_a_word(): void
    {
        [$chat, $a] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, 'The Contract is attached');

        $this->assertSame([$message->id], $this->search($a, 'contr')->pluck('id')->all());
        $this->assertSame([$message->id], $this->search($a, 'CONTRACT IS')->pluck('id')->all());
        $this->assertCount(0, $this->search($a, 'invoice'));
    }

    public function test_short_words_match_whole(): void
    {
        [$chat, $a] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, 'Ask HR about it');

        $this->assertSame([$message->id], $this->search($a, 'hr')->pluck('id')->all());
    }

    public function test_attachment_names_are_searchable(): void
    {
        [$chat, $a] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, null, [UploadedFile::fake()->createWithContent('payroll-march.xlsx', 'x')]);

        $this->assertSame([$message->id], $this->search($a, 'payroll')->pluck('id')->all());
    }

    public function test_results_come_only_from_the_searchers_own_chats(): void
    {
        [$mine, $me] = $this->privateChat();
        [$theirs, $other] = $this->privateChat();
        $this->chat()->send($me, $mine, 'budget for March');
        $this->chat()->send($other, $theirs, 'budget for April');

        $this->assertCount(1, $this->search($me, 'budget'));
        $this->assertCount(0, $this->search($me, 'april'));
        $this->assertCount(0, $this->search($me, 'budget', $theirs));
    }

    public function test_edits_and_deletions_are_reflected(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $message = $this->chat()->send($a, $chat, 'old wording');

        $this->chat()->edit($a, $message, 'new phrasing');
        $this->assertCount(0, $this->search($a, 'wording'));
        $this->assertCount(1, $this->search($a, 'phrasing'));

        $this->chat()->deleteForMe($b, $message);
        $this->assertCount(0, $this->search($b, 'phrasing'));
        $this->assertCount(1, $this->search($a, 'phrasing'));

        $this->chat()->deleteForEveryone($a, $message);
        $this->assertCount(0, $this->search($a, 'phrasing'));
    }

    private function search(...$args)
    {
        return app(ChatSearchIndex::class)->search(...$args);
    }
}
