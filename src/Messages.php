<?php
namespace Funseco\Dedimania;

/** Dedimania chat texts (dedimania.messages) and the small formatters they need. */
final class Messages
{
    public static function get(string $key, string|int|null ...$values): string
    {
        return format_colors(self::fill((string) config("dedimania.messages.$key", ''), $values));
    }

    /** Same text, but a broadcast ("{#server}>>") becomes a private message ("{#server}>"). */
    public static function whisper(string $key, string|int|null ...$values): string
    {
        $text = str_replace('{#server}>> ', '{#server}> ', (string) config("dedimania.messages.$key", ''));

        return format_colors(self::fill($text, $values));
    }

    public static function fill(string $text, array $values): string
    {
        $map = [];
        foreach (array_values($values) as $i => $value) {
            $map['{'.($i + 1).'}'] = (string) $value;
        }

        return strtr($text, $map);
    }

    public static function tell(string $login, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $login);
    }

    public static function broadcast(string $message): void
    {
        \Server::query()->ChatSendServerMessage($message);
    }

    /** Nickname without $ style codes, links and newlines. */
    public static function plain(?string $text): string
    {
        $text = str_replace('$$', "\0", str_replace(["\r", "\n"], '', (string) $text));
        $text = preg_replace('/\$(?:[0-9a-f][^$]{0,2}|[lhp]\[[^\]]*\]|[^$]?)/i', '', $text);

        return str_replace("\0", '$$', $text);
    }

    /** Whole seconds, two-digit hundredths, no minutes; '-' in front when negative. */
    public static function diff(int $ms): string
    {
        $abs = abs($ms);

        return ($ms < 0 ? '-' : '').sprintf('%d.%02d', intdiv($abs, 1000), intdiv($abs % 1000, 10));
    }

    /** R(rank, nick, time): rank.nick[time] */
    public static function fragment(string|int $rank, ?string $nickname, int $score): string
    {
        return self::get('fragment', $rank, self::plain($nickname), format_score($score));
    }

    /** "$l[url]text$l" when the URL is configured, else just the text. */
    public static function link(?string $url, string $text): string
    {
        return $url ? '$l['.$url.']'.$text.'$l' : $text;
    }

    public static function recordsUrl(?string $uid): ?string
    {
        $template = config('dedimania.links.records');

        return $template ? str_replace('{uid}', (string) $uid, $template) : null;
    }
}
