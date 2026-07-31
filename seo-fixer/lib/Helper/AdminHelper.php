<?php
namespace Relod\SeoFixer\Helper;

class AdminHelper
{
    public static function e($value): string
    {
        return htmlspecialcharsbx((string)$value);
    }

    public static function short($value, int $length = 180): string
    {
        $value = (string)$value;
        if (function_exists('mb_strlen') && mb_strlen($value) <= $length) {
            return $value;
        }
        if (!function_exists('mb_strlen') && strlen($value) <= $length) {
            return $value;
        }
        return function_exists('mb_substr')
            ? mb_substr($value, 0, max(1, $length - 1)) . '…'
            : substr($value, 0, max(1, $length - 1)) . '…';
    }

    public static function nav(): string
    {
        $lang = LANGUAGE_ID;
        return '<p class="relod-seofixer-nav">' .
            '<a href="relod_seofixer.php?lang=' . $lang . '">Обзор</a> | ' .
            '<a href="relod_seofixer_import.php?lang=' . $lang . '">Загрузка отчёта</a> | ' .
            '<a href="relod_seofixer_issues.php?lang=' . $lang . '">Найденные проблемы</a> | ' .
            '<a href="relod_seofixer_changes.php?lang=' . $lang . '">Журнал изменений</a> | ' .
            '<a href="relod_seofixer_errors.php?lang=' . $lang . '">Ошибки и не выполнено</a> | ' .
            '<a href="relod_seofixer_settings.php?lang=' . $lang . '">Настройки</a>' .
            '</p>';
    }

    public static function hint(string $text): string
    {
        return '<div class="relod-seofixer-hint">' . self::e($text) . '</div>';
    }

    public static function style(): string
    {
        return '<style>
            .relod-seofixer-wrap{max-width:100%;overflow-x:auto;padding-bottom:12px;}
            .relod-seofixer-hint{background:#f8f9fb;border:1px solid #dce3ea;padding:10px;margin:10px 0;max-width:1200px;line-height:1.45;}
            .relod-seofixer-nav{margin:6px 0 12px;}
            .relod-seofixer-table{width:100%;border-collapse:collapse;table-layout:fixed;}
            .relod-seofixer-table td,.relod-seofixer-table th{vertical-align:top;white-space:normal!important;word-break:break-word;overflow-wrap:anywhere;line-height:1.35;padding:7px 8px;}
            .relod-seofixer-table code{display:block;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;max-height:130px;overflow:auto;background:#fff;border:1px solid #e5e9ef;padding:5px;}
            .relod-seofixer-actions{margin:14px 0;padding:12px;border:1px solid #dce3ea;background:#fff;max-width:1200px;}
            .relod-seofixer-actions p{margin:7px 0;}
            .relod-seofixer-textarea{width:100%;min-height:68px;box-sizing:border-box;resize:vertical;}
            .relod-seofixer-filter{margin:0 0 15px;padding:10px;background:#fff;border:1px solid #dce3ea;max-width:1200px;}
            .relod-seofixer-badge{display:inline-block;padding:2px 7px;border-radius:10px;background:#eef2f7;border:1px solid #d7dee8;font-size:11px;line-height:16px;}
            .relod-seofixer-badge-low{background:#edf9ee;border-color:#bddfc2;}
            .relod-seofixer-badge-medium{background:#fff8e5;border-color:#eed48b;}
            .relod-seofixer-badge-high,.relod-seofixer-badge-template,.relod-seofixer-badge-manual{background:#fff0f0;border-color:#e3b6b6;}
            .relod-seofixer-small{color:#626c77;font-size:12px;line-height:1.35;}
            .relod-seofixer-danger{color:#b00000;}
            .relod-seofixer-status{font-weight:bold;}
            .relod-seofixer-mono{font-family:monospace;}
            .relod-seofixer-cols{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;}
            .relod-seofixer-card{background:#fff;border:1px solid #dce3ea;padding:12px;min-width:260px;}
        </style>';
    }

    public static function badge(string $text, string $code = ''): string
    {
        $class = 'relod-seofixer-badge';
        if ($code !== '') {
            $class .= ' relod-seofixer-badge-' . preg_replace('/[^a-z0-9_-]+/i', '', $code);
        }
        return '<span class="' . $class . '">' . self::e($text) . '</span>';
    }

    public static function yesNo(bool $value): string
    {
        return $value ? 'Да' : 'Нет';
    }

    public static function decodeJson($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
