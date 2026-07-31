<?php
namespace Relod\SeoFixer\Helper;

use Relod\SeoFixer\Site\SiteContext;

/**
 * Оформление админских страниц модуля: стили, навигация, карточки,
 * бейджи. Вынесено отдельно, чтобы страницы содержали только логику.
 */
class AdminHelper
{
    public static function e($value): string
    {
        return htmlspecialcharsbx((string)$value);
    }

    public static function short($value, int $length = 180): string
    {
        $value = (string)$value;
        $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($len <= $length) {
            return $value;
        }
        return (function_exists('mb_substr') ? mb_substr($value, 0, $length - 1, 'UTF-8') : substr($value, 0, $length - 1)) . '…';
    }

    public static function len(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * Верхняя навигация по разделам модуля.
     */
    public static function nav(string $current = '', array $params = []): string
    {
        $lang = LANGUAGE_ID;
        $query = '';
        foreach ($params as $k => $v) {
            if ((string)$v !== '') {
                $query .= '&' . rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
            }
        }

        $items = [
            'dashboard' => ['relod_seofixer.php', 'Обзор'],
            'import' => ['relod_seofixer_import.php', 'Загрузка отчёта'],
            'issues' => ['relod_seofixer_issues.php', 'Проблемы'],
            'duplicates' => ['relod_seofixer_duplicates.php', 'Дубликаты title и description'],
            'changes' => ['relod_seofixer_changes.php', 'Журнал изменений'],
            'errors' => ['relod_seofixer_errors.php', 'Не выполнено'],
            'settings' => ['relod_seofixer_settings.php', 'Настройки'],
        ];

        $html = '<div class="sf-nav">';
        foreach ($items as $key => $item) {
            $class = $key === $current ? 'sf-nav-item sf-nav-active' : 'sf-nav-item';
            $html .= '<a class="' . $class . '" href="' . $item[0] . '?lang=' . $lang . $query . '">' . self::e($item[1]) . '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Выпадающий список сайтов Битрикса.
     */
    public static function siteSelect(string $name, string $selected, bool $withAll = true): string
    {
        $html = '<select name="' . self::e($name) . '" class="sf-select">';
        if ($withAll) {
            $html .= '<option value="">Все сайты</option>';
        }
        foreach (SiteContext::listSites() as $id => $site) {
            $label = $site['name'] . ' (' . $id . ')';
            if ($site['domains']) {
                $label .= ' — ' . $site['domains'][0];
            }
            $html .= '<option value="' . self::e($id) . '"' . ($selected === (string)$id ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    public static function hint(string $text): string
    {
        return '<div class="sf-hint">' . self::e($text) . '</div>';
    }

    public static function success(string $text): string
    {
        return '<div class="sf-msg sf-msg-ok">' . self::e($text) . '</div>';
    }

    public static function error(string $text): string
    {
        return '<div class="sf-msg sf-msg-bad">' . self::e($text) . '</div>';
    }

    /**
     * Карточка со счётчиком.
     */
    public static function stat(string $label, $value, string $tone = '', string $href = ''): string
    {
        $inner = '<span class="sf-stat-value">' . self::e((string)$value) . '</span>'
            . '<span class="sf-stat-label">' . self::e($label) . '</span>';
        $class = 'sf-stat' . ($tone !== '' ? ' sf-stat-' . preg_replace('/[^a-z]+/i', '', $tone) : '');
        if ($href !== '') {
            return '<a class="' . $class . '" href="' . self::e($href) . '">' . $inner . '</a>';
        }
        return '<div class="' . $class . '">' . $inner . '</div>';
    }

    public static function badge(string $text, string $tone = '', string $title = ''): string
    {
        $class = 'sf-badge' . ($tone !== '' ? ' sf-badge-' . preg_replace('/[^a-z0-9_-]+/i', '', $tone) : '');
        $attr = $title !== '' ? ' title="' . self::e($title) . '"' : '';
        return '<span class="' . $class . '"' . $attr . '>' . self::e($text) . '</span>';
    }

    /**
     * Полоска приоритета 0–100.
     */
    public static function priorityBar(int $priority): string
    {
        $priority = max(0, min(100, $priority));
        $tone = $priority >= 85 ? 'high' : ($priority >= 60 ? 'mid' : 'low');
        return '<span class="sf-prio sf-prio-' . $tone . '" title="Приоритет ' . $priority . ' из 100">'
            . '<i style="width:' . $priority . '%"></i><b>' . $priority . '</b></span>';
    }

    /**
     * Индикатор длины для title/description.
     */
    public static function lengthMeter(string $value, int $min, int $max): string
    {
        $len = self::len($value);
        if ($len === 0) {
            return '<span class="sf-len sf-len-bad">пусто</span>';
        }
        $tone = ($len >= $min && $len <= $max) ? 'ok' : (($len < $min) ? 'warn' : 'bad');
        return '<span class="sf-len sf-len-' . $tone . '">' . $len . ' симв. (норма ' . $min . '–' . $max . ')</span>';
    }

    public static function decodeJson($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Ссылка на страницу сайта.
     */
    public static function externalLink(string $url, int $maxLen = 70): string
    {
        if (trim($url) === '') {
            return '<span class="sf-muted">—</span>';
        }
        return '<a class="sf-link" href="' . self::e($url) . '" target="_blank" rel="noopener">' . self::e(self::short($url, $maxLen)) . '</a>';
    }

    public static function style(): string
    {
        return <<<'CSS'
<style>
.sf-wrap{max-width:1600px;}
.sf-nav{display:flex;flex-wrap:wrap;gap:2px;margin:0 0 16px;border-bottom:2px solid #dfe4ea;}
.sf-nav-item{padding:9px 15px;text-decoration:none;color:#2a3340;font-size:13px;border:1px solid transparent;border-bottom:none;border-radius:5px 5px 0 0;}
.sf-nav-item:hover{background:#eef2f7;}
.sf-nav-active{background:#fff;border-color:#dfe4ea;font-weight:600;color:#0b5fff;position:relative;top:2px;}
.sf-hint{background:#f2f7ff;border-left:4px solid #0b5fff;padding:11px 14px;margin:12px 0;line-height:1.5;font-size:13px;color:#2a3340;}
.sf-msg{padding:11px 14px;margin:12px 0;border-radius:4px;line-height:1.5;font-size:13px;}
.sf-msg-ok{background:#edf9ee;border:1px solid #b6dfbd;color:#1c5c28;}
.sf-msg-bad{background:#fff0f0;border:1px solid #e3b6b6;color:#8a1f1f;}
.sf-stats{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 20px;}
.sf-stat{background:#fff;border:1px solid #dfe4ea;border-radius:6px;padding:13px 16px;min-width:150px;flex:1 1 150px;text-decoration:none;display:block;transition:.15s;}
a.sf-stat:hover{border-color:#0b5fff;box-shadow:0 1px 6px rgba(11,95,255,.12);}
.sf-stat-value{display:block;font-size:26px;font-weight:700;color:#1a2230;line-height:1.1;}
.sf-stat-label{display:block;font-size:12px;color:#66707d;margin-top:5px;}
.sf-stat-high .sf-stat-value{color:#c0392b;}
.sf-stat-ok .sf-stat-value{color:#1c8a35;}
.sf-stat-warn .sf-stat-value{color:#b8860b;}
.sf-badge{display:inline-block;padding:2px 8px;border-radius:11px;background:#eef2f7;border:1px solid #d7dee8;font-size:11px;line-height:16px;white-space:nowrap;}
.sf-badge-error{background:#fff0f0;border-color:#e3b6b6;color:#8a1f1f;}
.sf-badge-warning{background:#fff8e5;border-color:#eed48b;color:#7a5b00;}
.sf-badge-notice{background:#eef4ff;border-color:#c3d4f5;color:#28457e;}
.sf-badge-info{background:#f0f0f2;border-color:#d8d8dd;color:#555;}
.sf-badge-ok{background:#edf9ee;border-color:#b6dfbd;color:#1c5c28;}
.sf-badge-bad{background:#fff0f0;border-color:#e3b6b6;color:#8a1f1f;}
.sf-badge-warn{background:#fff8e5;border-color:#eed48b;color:#7a5b00;}
.sf-badge-new{background:#eef4ff;border-color:#c3d4f5;color:#28457e;}
.sf-badge-approved{background:#e8f0ff;border-color:#9bb8f0;color:#123a86;font-weight:600;}
.sf-badge-muted{background:#f3f4f6;border-color:#dcdfe4;color:#6b7280;}
.sf-badge-low{background:#edf9ee;border-color:#b6dfbd;color:#1c5c28;}
.sf-badge-medium{background:#fff8e5;border-color:#eed48b;color:#7a5b00;}
.sf-badge-template,.sf-badge-review,.sf-badge-infra{background:#f6f0ff;border-color:#d3c0f0;color:#4b2a80;}
.sf-filter{background:#fff;border:1px solid #dfe4ea;border-radius:6px;padding:12px 14px;margin:0 0 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:13px;}
.sf-filter label{color:#66707d;margin-right:3px;}
.sf-select,.sf-input{border:1px solid #ccd3dd;border-radius:4px;padding:5px 8px;font-size:13px;background:#fff;}
.sf-card{background:#fff;border:1px solid #dfe4ea;border-radius:6px;margin:0 0 10px;padding:0;overflow:hidden;}
.sf-card-approved{border-color:#9bb8f0;box-shadow:inset 3px 0 0 #0b5fff;}
.sf-card-done{opacity:.7;}
.sf-card-head{display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border-bottom:1px solid #eef1f5;background:#fbfcfd;}
.sf-card-head input[type=checkbox]{margin-top:3px;width:16px;height:16px;flex:0 0 auto;}
.sf-card-title{flex:1 1 auto;min-width:0;}
.sf-card-title h3{margin:0 0 4px;font-size:14px;font-weight:600;color:#1a2230;}
.sf-card-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:5px;}
.sf-card-body{padding:12px 14px;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;}
@media (max-width:1100px){.sf-card-body{grid-template-columns:minmax(0,1fr);}}
.sf-block{min-width:0;}
.sf-block h4{margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#8a94a2;font-weight:600;}
.sf-value{background:#f7f8fa;border:1px solid #e5e9ef;border-radius:4px;padding:7px 9px;font-size:13px;line-height:1.45;word-break:break-word;overflow-wrap:anywhere;max-height:150px;overflow:auto;}
.sf-value-empty{color:#a0a6b0;font-style:italic;}
.sf-textarea{width:100%;min-height:64px;box-sizing:border-box;resize:vertical;border:1px solid #ccd3dd;border-radius:4px;padding:7px 9px;font-size:13px;line-height:1.45;font-family:inherit;}
.sf-textarea:focus{border-color:#0b5fff;outline:none;box-shadow:0 0 0 2px rgba(11,95,255,.12);}
.sf-note{font-size:12px;color:#66707d;line-height:1.45;margin-top:5px;}
.sf-verdict{font-size:12px;line-height:1.45;padding:6px 9px;border-radius:4px;background:#f2f7ff;border:1px solid #d5e2fb;color:#28457e;margin-top:6px;}
.sf-verdict-ok{background:#edf9ee;border-color:#b6dfbd;color:#1c5c28;}
.sf-verdict-bad{background:#fff0f0;border-color:#e3b6b6;color:#8a1f1f;}
.sf-explain{font-size:12px;color:#5a6472;line-height:1.5;}
.sf-explain b{color:#2a3340;}
.sf-muted{color:#a0a6b0;}
.sf-link{color:#0b5fff;text-decoration:none;word-break:break-all;}
.sf-link:hover{text-decoration:underline;}
.sf-prio{display:inline-block;position:relative;width:56px;height:16px;background:#eef1f5;border-radius:8px;overflow:hidden;vertical-align:middle;}
.sf-prio i{display:block;height:100%;}
.sf-prio b{position:absolute;left:0;right:0;top:0;bottom:0;text-align:center;font-size:10px;line-height:16px;font-weight:600;color:#2a3340;}
.sf-prio-high i{background:#f5b7b1;}
.sf-prio-mid i{background:#f7dc9f;}
.sf-prio-low i{background:#c9dcf5;}
.sf-len{font-size:11px;padding:1px 7px;border-radius:9px;display:inline-block;}
.sf-len-ok{background:#edf9ee;color:#1c5c28;}
.sf-len-warn{background:#fff8e5;color:#7a5b00;}
.sf-len-bad{background:#fff0f0;color:#8a1f1f;}
.sf-actions{position:sticky;bottom:0;z-index:20;background:#fff;border:1px solid #dfe4ea;border-radius:6px;padding:12px 14px;margin:16px 0 0;box-shadow:0 -2px 10px rgba(0,0,0,.06);}
.sf-actions-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.sf-btn{display:inline-block;padding:7px 14px;border-radius:4px;border:1px solid #ccd3dd;background:#fff;color:#2a3340;font-size:13px;cursor:pointer;line-height:1.3;}
.sf-btn:hover{background:#f2f5f9;border-color:#9aa6b6;}
.sf-btn-primary{background:#0b5fff;border-color:#0b5fff;color:#fff;font-weight:600;}
.sf-btn-primary:hover{background:#0a4fd4;border-color:#0a4fd4;}
.sf-btn-danger{background:#fff;border-color:#e3b6b6;color:#8a1f1f;}
.sf-btn-danger:hover{background:#fff0f0;}
.sf-counter{font-size:13px;color:#66707d;margin-left:auto;}
.sf-counter b{color:#0b5fff;font-size:15px;}
.sf-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dfe4ea;font-size:13px;}
.sf-table th{background:#f7f8fa;text-align:left;padding:9px 11px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#8a94a2;border-bottom:1px solid #e5e9ef;font-weight:600;}
.sf-table td{padding:9px 11px;border-bottom:1px solid #eef1f5;vertical-align:top;line-height:1.45;word-break:break-word;}
.sf-table tr:last-child td{border-bottom:none;}
.sf-table tr:hover td{background:#fbfcfd;}
.sf-group{background:#fff;border:1px solid #dfe4ea;border-radius:6px;margin:0 0 12px;overflow:hidden;}
.sf-group-head{padding:11px 14px;background:#fbfcfd;border-bottom:1px solid #eef1f5;display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
.sf-group-head h3{margin:0;font-size:14px;font-weight:600;flex:1 1 auto;min-width:0;word-break:break-word;}
.sf-empty{background:#fff;border:1px dashed #ccd3dd;border-radius:6px;padding:28px;text-align:center;color:#8a94a2;font-size:14px;}
.sf-form-table{width:100%;max-width:1000px;border-collapse:collapse;background:#fff;border:1px solid #dfe4ea;}
.sf-form-table th{width:32%;text-align:left;padding:12px 14px;background:#fbfcfd;border-bottom:1px solid #eef1f5;font-weight:600;font-size:13px;vertical-align:top;}
.sf-form-table td{padding:12px 14px;border-bottom:1px solid #eef1f5;font-size:13px;line-height:1.5;}
.sf-form-table small{color:#66707d;display:block;margin-top:4px;line-height:1.45;}
.sf-h2{font-size:16px;font-weight:600;margin:22px 0 10px;color:#1a2230;}
</style>
CSS;
    }

    /**
     * Небольшой JS: выделение всех, счётчик выбранных, длина поля.
     */
    public static function script(): string
    {
        return <<<'JS'
<script>
(function(){
  function all(sel,root){return Array.prototype.slice.call((root||document).querySelectorAll(sel));}
  window.sfToggleAll=function(cb){
    all('.sf-check').forEach(function(c){c.checked=cb.checked;});
    sfUpdateCount();
  };
  window.sfUpdateCount=function(){
    var n=all('.sf-check').filter(function(c){return c.checked;}).length;
    all('.sf-selected-count').forEach(function(el){el.textContent=n;});
    all('.sf-check').forEach(function(c){
      var card=c.closest('.sf-card');
      if(card){card.classList.toggle('sf-card-approved',c.checked);}
    });
  };
  document.addEventListener('DOMContentLoaded',function(){
    all('.sf-check').forEach(function(c){c.addEventListener('change',sfUpdateCount);});
    sfUpdateCount();
    all('.sf-textarea').forEach(function(t){
      t.addEventListener('input',function(){
        var meter=t.parentNode.querySelector('.sf-live-len');
        if(meter){meter.textContent=t.value.length+' симв.';}
      });
    });
  });
})();
</script>
JS;
    }
}
