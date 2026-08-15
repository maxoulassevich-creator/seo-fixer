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

    /**
     * Коды, которыми сайт «отказывает» запросу: обычно это защита от ботов
     * или временная перегрузка, а не сломанная страница.
     */
    private const REFUSAL_CODES = [403, 429, 503];

    /** @var array<string,array> */
    private $cache = [];

    /** @var array<string,bool> Здоровье хоста, общее для всех экземпляров. */
    private static $hostHealth = [];

    /** @var int */
    private $retries;

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
        $this->retries = max(0, min(5, (int)\COption::GetOptionString('relod.seofixer', 'http_retries', '2')));
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

        // Коды 429 и 5xx часто означают не сломанную страницу, а сработавший
        // антифлуд. Повторяем запрос с нарастающей паузой, прежде чем делать
        // вывод, что страница недоступна.
        $attempts = $this->retries + 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }
            if ($attempt > 1) {
                usleep(min(8, $attempt) * 1000000);
            }

            $result = $this->performRequest($url);
            $result['attempts'] = $attempt;

            $temporary = $result['status'] === 429 || $result['status'] >= 500;
            if (!$temporary || $attempt === $attempts) {
                break;
            }
        }

        $result['refused'] = in_array((int)$result['status'], self::REFUSAL_CODES, true);

        return $this->cache[$url] = $result;
    }

    /**
     * Один HTTP-запрос без повторов.
     */
    private function performRequest(string $url): array
    {
        $result = $this->emptyResult($url);
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
            // Набор заголовков обычного браузера: без него часть сайтов
            // отвечает отказом на любой автоматический запрос.
            $client->setHeader('User-Agent', $this->userAgent());
            $client->setHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
            $client->setHeader('Accept-Language', 'ru-RU,ru;q=0.9,en;q=0.8');
            $client->setHeader('Cache-Control', 'no-cache');

            $body = $client->get($url);
            $result['response_ms'] = (int)round((microtime(true) - $started) * 1000);
            $result['status'] = (int)$client->getStatus();
            $result['effective_url'] = (string)($client->getEffectiveUrl() ?: $url);
            $result['redirected'] = $this->stripFragment($result['effective_url']) !== $this->stripFragment($url);
            $result['x_robots'] = (string)$client->getHeaders()->get('X-Robots-Tag');
            $result['status_text'] = $this->statusText($result['status']);

            if ($body === false) {
                $result['error'] = 'Сервер не ответил или соединение прервалось.';
                return $result;
            }

            $body = (string)$body;
            if (strlen($body) > self::MAX_BODY) {
                $body = substr($body, 0, self::MAX_BODY);
            }

            $result['ok'] = $result['status'] >= 200 && $result['status'] < 400;

            $parsed = $this->parseHtml($body);
            foreach ($parsed as $k => $v) {
                $result[$k] = $v;
            }
        } catch (\Throwable $e) {
            $result['response_ms'] = (int)round((microtime(true) - $started) * 1000);
            $result['error'] = 'Ошибка запроса: ' . $e->getMessage();
        }

        return $result;
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
            'content' => '',
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

        $out['content'] = $this->extractContent($html);

        return $out;
    }

    /**
     * Достаёт осмысленный текст самой страницы — обычно первый абзац.
     *
     * Нужен, чтобы описание страницы строилось из её настоящего содержания,
     * а не из шаблонной фразы: иначе у всех страниц получается почти
     * одинаковый текст, и дубликаты устраняются лишь формально.
     */
    public function extractContent(string $html): string
    {
        // Убираем обвязку: меню, шапку, подвал, формы и скрипты.
        $body = (string)preg_replace(
            '~<(script|style|noscript|nav|header|footer|aside|form|select|button|svg)\b[^>]*>.*?</\1>~is',
            ' ',
            $html
        );

        $skip = '~(cookie|куки|подпис|рассылк|©|все права|©|политик[аи] конфиденциальност|обратн[ыа][йя] звонок|версия для слабовидящих)~iu';

        // Сначала абзацы: они почти всегда и есть контент. Берём несколько
        // подряд — одного часто не хватает на полноценное описание.
        if (preg_match_all('~<p\b[^>]*>(.*?)</p>~is', $body, $matches)) {
            $collected = '';
            foreach ($matches[1] as $paragraph) {
                $text = $this->cleanText($paragraph);
                $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
                if ($len < 40 || preg_match($skip, $text)) {
                    continue;
                }
                $collected .= ($collected !== '' ? ' ' : '') . rtrim($text, ' .') . '.';
                if ((function_exists('mb_strlen') ? mb_strlen($collected, 'UTF-8') : strlen($collected)) >= 400) {
                    break;
                }
            }
            if ($collected !== '') {
                return $collected;
            }
        }

        // Иначе — текст после первого H1.
        if (preg_match('~</h1>(.*)$~is', $body, $m)) {
            $text = $this->cleanText($m[1]);
            $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($len >= 60 && !preg_match($skip, mb_substr($text, 0, 120, 'UTF-8'))) {
                return $text;
            }
        }

        return '';
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

    /**
     * По умолчанию представляемся обычным браузером.
     *
     * С «честным» User-Agent робота многие сайты (защита от ботов, антифлуд
     * Битрикса, настройки хостинга) отвечают 503 или 403, и модуль ошибочно
     * считал живые страницы недоступными.
     */
    private function userAgent(): string
    {
        $custom = trim((string)\COption::GetOptionString('relod.seofixer', 'http_user_agent', ''));
        if ($custom !== '') {
            return $custom;
        }
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
    }

    /**
     * Проверяет, не отвечает ли сайт целиком отказом.
     *
     * Если и запрошенная страница, и корень сайта отдают 403/429/503, дело
     * почти наверняка не в конкретной странице: либо сайт лежит целиком,
     * либо срабатывает защита от автоматических запросов.
     */
    public function siteRefusesRequests(string $url): bool
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $scheme = (string)parse_url($url, PHP_URL_SCHEME);
        if ($host === '') {
            return false;
        }
        if (isset(self::$hostHealth[$host])) {
            return self::$hostHealth[$host];
        }

        $root = ($scheme !== '' ? $scheme : 'https') . '://' . $host . '/';
        // Помечаем заранее, иначе проверка корня уйдёт в рекурсию.
        self::$hostHealth[$host] = false;
        $rootResult = $this->inspect($root);

        return self::$hostHealth[$host] = in_array((int)$rootResult['status'], self::REFUSAL_CODES, true);
    }

    public static function resetHostHealth(): void
    {
        self::$hostHealth = [];
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
            'attempts' => 0,
            'refused' => false,
        ];
    }
}
