<?php
namespace Relod\SeoFixer\Site;

use Bitrix\Main\Web\HttpClient;

/**
 * Проверка страницы «вживую»: модуль сам открывает адрес и смотрит,
 * что на нём сейчас, а не что было записано в отчёте.
 *
 * Возвращает фактические title/description/H1/canonical, код ответа,
 * цепочку редиректов, размер HTML и время ответа.
 */
class LivePageInspector
{
    /** Максимальный размер тела ответа, который читаем (2 МБ). */
    private const MAX_BODY = 2097152;

    /** @var array<string,array> */
    private $cache = [];

    /** @var int */
    private $timeout;

    /** @var int */
    private $delayMs;

    public function __construct(int $timeout = 0, int $delayMs = -1)
    {
        $this->timeout = $timeout > 0
            ? $timeout
            : max(3, min(60, (int)\COption::GetOptionString('relod.seofixer', 'http_timeout', '15')));
        $this->delayMs = $delayMs >= 0
            ? $delayMs
            : max(0, min(5000, (int)\COption::GetOptionString('relod.seofixer', 'http_delay_ms', '150')));
    }

    /**
     * @return array{
     *   ok:bool, url:string, effective_url:string, status:int, status_text:string,
     *   redirected:bool, title:string, description:string, h1:string[], h1_count:int,
     *   canonical:string, meta_robots:string, x_robots:string, html_size:int,
     *   text_size:int, text_ratio:float, response_ms:int, error:string
     * }
     */
    public function inspect(string $url): array
    {
        $url = trim($url);
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }

        $result = $this->emptyResult($url);

        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            $result['error'] = 'Некорректный адрес для проверки.';
            return $this->cache[$url] = $result;
        }

        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $started = microtime(true);
        try {
            $client = new HttpClient([
                'redirect' => true,
                'redirectMax' => 5,
                'socketTimeout' => $this->timeout,
                'streamTimeout' => $this->timeout,
                'version' => HttpClient::HTTP_1_1,
                'disableSslVerification' => true,
            ]);
            $client->setHeader('User-Agent', $this->userAgent());
            $client->setHeader('Accept', 'text/html,application/xhtml+xml');
            $body = $client->get($url);
            $result['response_ms'] = (int)round((microtime(true) - $started) * 1000);
            $result['status'] = (int)$client->getStatus();
            $result['effective_url'] = (string)($client->getEffectiveUrl() ?: $url);
            $result['redirected'] = $this->stripFragment($result['effective_url']) !== $this->stripFragment($url);
            $result['x_robots'] = (string)$client->getHeaders()->get('X-Robots-Tag');

            if ($body === false) {
                $result['error'] = 'Сервер не ответил или соединение прервалось.';
                return $this->cache[$url] = $result;
            }

            $body = (string)$body;
            if (strlen($body) > self::MAX_BODY) {
                $body = substr($body, 0, self::MAX_BODY);
            }

            $result['ok'] = $result['status'] >= 200 && $result['status'] < 400;
            $result['status_text'] = $this->statusText($result['status']);

            $parsed = $this->parseHtml($body);
            foreach ($parsed as $k => $v) {
                $result[$k] = $v;
            }
        } catch (\Throwable $e) {
            $result['response_ms'] = (int)round((microtime(true) - $started) * 1000);
            $result['error'] = 'Ошибка запроса: ' . $e->getMessage();
        }

        return $this->cache[$url] = $result;
    }

    /**
     * Быстрая проверка только кода ответа (без разбора HTML).
     *
     * @return array{status:int,effective_url:string,ok:bool,response_ms:int,error:string}
     */
    public function checkStatus(string $url): array
    {
        $full = $this->inspect($url);
        return [
            'status' => $full['status'],
            'effective_url' => $full['effective_url'],
            'ok' => $full['ok'],
            'response_ms' => $full['response_ms'],
            'error' => $full['error'],
        ];
    }

    /**
     * Разбирает HTML и достаёт SEO-значимые элементы.
     */
    public function parseHtml(string $html): array
    {
        $out = [
            'title' => '',
            'description' => '',
            'h1' => [],
            'h1_count' => 0,
            'canonical' => '',
            'meta_robots' => '',
            'html_size' => strlen($html),
            'text_size' => 0,
            'text_ratio' => 0.0,
        ];

        $head = $html;
        if (preg_match('~<head\b[^>]*>(.*?)</head>~is', $html, $m)) {
            $head = $m[1];
        }

        if (preg_match('~<title\b[^>]*>(.*?)</title>~is', $head, $m)) {
            $out['title'] = $this->cleanText($m[1]);
        }

        if (preg_match_all('~<meta\b[^>]*>~i', $head, $metas)) {
            foreach ($metas[0] as $tag) {
                $name = $this->attr($tag, 'name');
                $property = $this->attr($tag, 'property');
                $content = $this->attr($tag, 'content');
                $key = strtolower($name !== '' ? $name : $property);
                if ($key === 'description' && $out['description'] === '') {
                    $out['description'] = $this->cleanText($content);
                } elseif ($key === 'robots' && $out['meta_robots'] === '') {
                    $out['meta_robots'] = $this->cleanText($content);
                }
            }
        }

        if (preg_match_all('~<link\b[^>]*>~i', $head, $links)) {
            foreach ($links[0] as $tag) {
                if (strtolower($this->attr($tag, 'rel')) === 'canonical') {
                    $out['canonical'] = trim($this->attr($tag, 'href'));
                    break;
                }
            }
        }

        if (preg_match_all('~<h1\b[^>]*>(.*?)</h1>~is', $html, $h1s)) {
            foreach ($h1s[1] as $h1) {
                $text = $this->cleanText($h1);
                if ($text !== '') {
                    $out['h1'][] = $text;
                }
            }
            $out['h1_count'] = count($h1s[1]);
        }

        $text = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', ' ', $html);
        $text = $this->cleanText((string)$text);
        $out['text_size'] = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $out['text_ratio'] = $out['html_size'] > 0 ? round($out['text_size'] / $out['html_size'], 4) : 0.0;

        return $out;
    }

    private function attr(string $tag, string $name): string
    {
        if (preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*"([^"]*)"~i', $tag, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match("~\\b" . preg_quote($name, '~') . "\\s*=\\s*'([^']*)'~i", $tag, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*([^\s>"\']+)~i', $tag, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private function cleanText(string $value): string
    {
        $value = (string)preg_replace('~<[^>]*>~', ' ', $value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string)preg_replace('~\s+~u', ' ', $value);
        return trim($value);
    }

    private function stripFragment(string $url): string
    {
        return rtrim((string)preg_replace('~#.*$~', '', trim($url)), '/');
    }

    private function userAgent(): string
    {
        $custom = trim((string)\COption::GetOptionString('relod.seofixer', 'http_user_agent', ''));
        return $custom !== '' ? $custom : 'Mozilla/5.0 (compatible; RelodSeoFixer/2.0; +bitrix-module)';
    }

    private function statusText(int $status): string
    {
        if ($status === 0) {
            return 'нет ответа';
        }
        if ($status >= 500) {
            return 'ошибка сервера';
        }
        if ($status === 404) {
            return 'страница не найдена';
        }
        if ($status >= 400) {
            return 'ошибка запроса';
        }
        if ($status >= 300) {
            return 'редирект';
        }
        return 'страница доступна';
    }

    private function emptyResult(string $url): array
    {
        return [
            'ok' => false,
            'url' => $url,
            'effective_url' => $url,
            'status' => 0,
            'status_text' => '',
            'redirected' => false,
            'title' => '',
            'description' => '',
            'h1' => [],
            'h1_count' => 0,
            'canonical' => '',
            'meta_robots' => '',
            'x_robots' => '',
            'html_size' => 0,
            'text_size' => 0,
            'text_ratio' => 0.0,
            'response_ms' => 0,
            'error' => '',
        ];
    }
}
