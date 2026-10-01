<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

/**
 * What the board's video widget does with an address (design/validated/tableau-virtuel.md, §7).
 *
 * No list of domains - the teacher projects the video of their choice, which was asked for
 * explicitly. The known players are turned into their embedded form (YouTube through
 * youtube-nocookie.com, Vimeo, Dailymotion, PeerTube), a direct .mp4/.webm plays in a <video>, and
 * anything else is tried in an iframe. A site may refuse to be framed without the browser saying
 * so reliably, which is why the widget always carries « Ouvrir dans un onglet » as well.
 *
 * Only http(s) is accepted: a `javascript:` address in an iframe's src is the one thing this must
 * never produce.
 */
final class VideoEmbed
{
    public const string PLAYER = 'iframe';
    public const string FILE = 'video';

    /**
     * @return array{kind: string, src: string, url: string}|null null when the address is not one
     */
    public function resolve(string $url): ?array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (false === $parts || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || '' === ($parts['host'] ?? '')) {
            return null;
        }

        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $parts['host']) ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        $embed = match (true) {
            \in_array($host, ['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'], true) => $this->youtube($path, $query),
            'youtu.be' === $host => $this->youtubeId(ltrim($path, '/'), $query),
            'vimeo.com' === $host || 'player.vimeo.com' === $host => preg_match('#/(?:video/)?(\d+)#', $path, $m) ? 'https://player.vimeo.com/video/'.$m[1] : null,
            'dailymotion.com' === $host => preg_match('#/(?:embed/)?video/([a-zA-Z0-9]+)#', $path, $m) ? 'https://www.dailymotion.com/embed/video/'.$m[1] : null,
            'dai.ly' === $host => preg_match('#^/([a-zA-Z0-9]+)#', $path, $m) ? 'https://www.dailymotion.com/embed/video/'.$m[1] : null,
            default => null,
        };
        if (null !== $embed) {
            return ['kind' => self::PLAYER, 'src' => $embed, 'url' => $url];
        }

        // PeerTube: any instance, recognised by its own paths rather than by a list of hosts.
        if (preg_match('#^/(?:w|videos/watch)/([a-zA-Z0-9-]+)#', $path, $m)) {
            $origin = strtolower((string) $parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

            return ['kind' => self::PLAYER, 'src' => $origin.'/videos/embed/'.$m[1], 'url' => $url];
        }

        if (preg_match('/\.(mp4|webm|ogv|m4v)$/i', $path)) {
            return ['kind' => self::FILE, 'src' => $url, 'url' => $url];
        }

        return ['kind' => self::PLAYER, 'src' => $url, 'url' => $url];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function youtube(string $path, array $query): ?string
    {
        if (preg_match('#^/(?:embed|shorts|live|v)/([a-zA-Z0-9_-]{6,})#', $path, $m)) {
            return $this->youtubeId($m[1], $query);
        }
        $id = $query['v'] ?? null;

        return \is_string($id) ? $this->youtubeId($id, $query) : null;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function youtubeId(string $id, array $query): ?string
    {
        if (1 !== preg_match('/^[a-zA-Z0-9_-]{6,20}$/', $id)) {
            return null;
        }

        // « ?t=90 » / « ?t=1m30s » / « ?start=90 »: where the teacher meant the video to begin.
        $start = $query['start'] ?? $query['t'] ?? null;
        $seconds = 0;
        if (\is_string($start) && preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $start, $m)) {
            $seconds = (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        }

        return 'https://www.youtube-nocookie.com/embed/'.$id.($seconds > 0 ? '?start='.$seconds : '');
    }
}
