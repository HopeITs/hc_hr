<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search over encrypted messages without storing their words.
 *
 * Each message's text is normalised (Arabic letter forms unified, diacritics
 * and "ال" dropped, lower case), cut into three-letter pieces, and only a
 * keyed hash of each piece is stored. A search hashes its own pieces the same
 * way to find candidates, then decrypts just those candidates, in the
 * searcher's own conversations, and keeps the ones that really contain the
 * text. So part of a word matches ("عقد" finds "العقد", "عقدين"), and nothing
 * readable is ever written to the index.
 */
class ChatSearchIndex
{
    /** Without enough pieces to narrow, look through this many recent messages. */
    private const SCAN_LIMIT = 2000;

    public function __construct(private readonly ChatKeys $keys) {}

    /** Re-index one message from its current text (and attachment names). */
    public function index(ChatMessage $message, array $extraTexts = []): void
    {
        DB::table('messenger_search_tokens')->where('message_id', $message->id)->delete();

        $text = trim(implode(' ', array_filter([(string) $message->body, ...$extraTexts])));

        if ($text === '' || $message->isService()) {
            return;
        }

        $rows = collect($this->tokens($text))
            ->map(fn (string $token) => [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'token' => $token,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('messenger_search_tokens')->insert($chunk);
        }
    }

    public function forget(ChatMessage $message): void
    {
        DB::table('messenger_search_tokens')->where('message_id', $message->id)->delete();
    }

    /**
     * Messages that contain the query, newest first, from conversations the
     * user is a current member of (one conversation if given), never ones the
     * user deleted for themself.
     *
     * @return Collection<int, ChatMessage>
     */
    public function search(User $user, string $query, ?ChatConversation $in = null, int $limit = 50): Collection
    {
        $needle = $this->normalise($query);

        if ($needle === '') {
            return collect();
        }

        $conversationIds = $in
            ? [$in->id]
            : ChatConversation::query()->forUser($user)->pluck('id')->all();

        if ($in && ! $in->participantFor($user)) {
            return collect();
        }

        if ($conversationIds === []) {
            return collect();
        }

        $tokens = $this->tokens($query);

        $candidates = ChatMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('type', '!=', ChatMessage::TYPE_SERVICE)
            ->visibleTo($user)
            ->with('attachments')
            ->orderByDesc('id');

        if ($tokens !== []) {
            $candidates->whereIn('id', function ($q) use ($conversationIds, $tokens) {
                $q->select('message_id')
                    ->from('messenger_search_tokens')
                    ->whereIn('conversation_id', $conversationIds)
                    ->whereIn('token', $tokens)
                    ->groupBy('message_id')
                    ->havingRaw('COUNT(DISTINCT token) = ?', [count($tokens)]);
            });
        } else {
            $candidates->limit(self::SCAN_LIMIT);
        }

        $found = collect();

        // Decrypt and confirm in batches, stopping once there are enough.
        foreach ($candidates->lazyByIdDesc(200, 'id') as $message) {
            $haystack = $this->normalise(implode(' ', [
                (string) $message->body,
                ...$message->attachments->map(fn ($a) => (string) $a->original_name)->all(),
            ]));

            if (str_contains($haystack, $needle)) {
                $found->push($message);

                if ($found->count() >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The keyed hashes for a text: every three-letter piece of each word, and
     * shorter words whole.
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $tokens = [];

        foreach ($this->words($text) as $word) {
            $length = mb_strlen($word);

            if ($length < 3) {
                $tokens['w:'.$word] = true;

                continue;
            }

            for ($i = 0; $i <= $length - 3; $i++) {
                $tokens['t:'.mb_substr($word, $i, 3)] = true;
            }
        }

        $key = $this->keys->for(ChatKeys::SEARCH);

        return array_values(array_map(
            fn (string $piece) => substr(bin2hex(hash_hmac('sha256', $piece, $key, true)), 0, 32),
            array_keys($tokens),
        ));
    }

    /** Normalised words joined by single spaces: the form both sides compare in. */
    public function normalise(string $text): string
    {
        return implode(' ', $this->words($text));
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $text = mb_strtolower($text);

        // Arabic-Indic and Persian digits to 0-9.
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        // Diacritics (tashkeel), the superscript alef and tatweel.
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;

        // One form per letter: hamza seats, taa marbuta, alef maqsura.
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(function (string $word): string {
            // The definite article, alone or after و / ب / ف / ك, when enough
            // of the word is left.
            foreach (['وال', 'بال', 'فال', 'كال'] as $prefix) {
                if (str_starts_with($word, $prefix) && mb_strlen($word) >= 5) {
                    return mb_substr($word, 3);
                }
            }

            if (str_starts_with($word, 'ال') && mb_strlen($word) >= 4) {
                return mb_substr($word, 2);
            }

            return $word;
        }, $words));
    }
}
