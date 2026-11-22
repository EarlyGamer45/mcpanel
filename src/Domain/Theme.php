<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;

/**
 * Appearance settings: colours, theme mode, fonts, logo and home page texts.
 *
 * Everything an admin can change from Settings → Appearance goes through
 * here, so the views never read raw, unvalidated setting values.
 */
final class Theme
{
    public const MODES = ['dark', 'light', 'auto'];
    public const FONTS = ['system', 'rounded', 'geometric', 'serif', 'mono'];
    public const RADII = ['sharp', 'normal', 'round'];
    public const DENSITIES = ['compact', 'normal', 'comfortable'];
    public const BACKGROUNDS = ['glow', 'grid', 'plain'];
    public const WIDTHS = ['normal', 'wide'];

    /** Home page blocks the admin can show or hide, in display order. */
    public const HOME_SECTIONS = ['announcements', 'plans', 'features', 'categories', 'steps', 'faq', 'cta'];

    /** Maximum size of an uploaded logo or favicon (stored as a data URI). */
    public const MAX_IMAGE_BYTES = 262144;

    private const FONT_STACKS = [
        'system' => '"Segoe UI", system-ui, -apple-system, Roboto, Helvetica, Arial, sans-serif',
        'rounded' => 'ui-rounded, "SF Pro Rounded", "Nunito", "Varela Round", "Segoe UI", system-ui, sans-serif',
        'geometric' => '"Avenir Next", Avenir, Montserrat, Futura, "Century Gothic", "Segoe UI", system-ui, sans-serif',
        'serif' => 'Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif',
        'mono' => 'ui-monospace, "Cascadia Code", "JetBrains Mono", SFMono-Regular, Consolas, monospace',
    ];

    private const RADIUS_VALUES = ['sharp' => '2px', 'normal' => '6px', 'round' => '12px'];
    private const DENSITY_VALUES = ['compact' => '.82', 'normal' => '1', 'comfortable' => '1.18'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'theme_mode' => 'light',
            'theme_toggle' => true,
            'brand_color' => '#2563eb',
            'accent_color' => '#1d4ed8',
            'font_family' => 'system',
            'radius' => 'normal',
            'density' => 'normal',
            'background_style' => 'plain',
            'layout_width' => 'normal',
            'logo_data' => '',
            'logo_height' => 34,
            'favicon_data' => '',
            'show_brand_name' => true,
            'hero_badge' => '',
            'hero_title' => '',
            'hero_lead' => '',
            'hero_cta' => '',
            'topbar_text' => '',
            'topbar_link' => '',
            'footer_text' => '',
            'custom_css' => '',
            'home_sections' => self::HOME_SECTIONS,
        ];
    }

    /** @return array<string,mixed> validated appearance settings */
    public static function get(): array
    {
        if (!LicenseClient::allowsCustomization()) {
            // Without a valid licence the stored customisation is kept but
            // not applied: the site shows the default look.
            return self::normalize(self::defaults());
        }

        return self::stored();
    }

    /** Saved settings, whatever the licence state (for the admin form). */
    public static function stored(): array
    {
        $values = [];
        foreach (self::defaults() as $key => $default) {
            $values[$key] = App::setting($key, $default);
        }

        return self::normalize($values);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function normalize(array $values): array
    {
        $defaults = self::defaults();
        $out = [];
        $out['theme_mode'] = self::oneOf($values['theme_mode'] ?? null, self::MODES, $defaults['theme_mode']);
        $out['theme_toggle'] = self::bool($values['theme_toggle'] ?? $defaults['theme_toggle']);
        $out['brand_color'] = self::color($values['brand_color'] ?? null, $defaults['brand_color']);
        $out['accent_color'] = self::color($values['accent_color'] ?? null, $defaults['accent_color']);
        $out['font_family'] = self::oneOf($values['font_family'] ?? null, self::FONTS, $defaults['font_family']);
        $out['radius'] = self::oneOf($values['radius'] ?? null, self::RADII, $defaults['radius']);
        $out['density'] = self::oneOf($values['density'] ?? null, self::DENSITIES, $defaults['density']);
        $out['background_style'] = self::oneOf($values['background_style'] ?? null, self::BACKGROUNDS, $defaults['background_style']);
        $out['layout_width'] = self::oneOf($values['layout_width'] ?? null, self::WIDTHS, $defaults['layout_width']);
        $out['logo_data'] = self::imageUri($values['logo_data'] ?? '');
        $out['logo_height'] = max(20, min(80, (int) ($values['logo_height'] ?? $defaults['logo_height'])));
        $out['favicon_data'] = self::imageUri($values['favicon_data'] ?? '');
        $out['show_brand_name'] = self::bool($values['show_brand_name'] ?? $defaults['show_brand_name']);
        foreach (['hero_badge', 'hero_title', 'hero_lead', 'hero_cta', 'topbar_text', 'footer_text'] as $key) {
            $out[$key] = mb_substr(trim((string) ($values[$key] ?? '')), 0, 500);
        }
        $link = trim((string) ($values['topbar_link'] ?? ''));
        $out['topbar_link'] = self::safeLink($link);
        $out['custom_css'] = self::sanitizeCss((string) ($values['custom_css'] ?? ''));
        $sections = $values['home_sections'] ?? $defaults['home_sections'];
        $out['home_sections'] = is_array($sections)
            ? array_values(array_intersect(self::HOME_SECTIONS, array_map('strval', $sections)))
            : $defaults['home_sections'];

        return $out;
    }

    /** CSS custom properties generated from the settings. */
    public static function cssVariables(?array $theme = null): string
    {
        $theme ??= self::get();
        $brand = $theme['brand_color'];
        $accent = $theme['accent_color'];
        $vars = [
            '--brand' => $brand,
            '--brand-ink' => self::inkFor($brand),
            '--accent' => $accent,
            '--accent-ink' => self::inkFor($accent),
            '--radius' => self::RADIUS_VALUES[$theme['radius']],
            '--density' => self::DENSITY_VALUES[$theme['density']],
            '--font' => self::FONT_STACKS[$theme['font_family']],
            '--max' => $theme['layout_width'] === 'wide' ? '1360px' : '1140px',
            '--logo-h' => $theme['logo_height'] . 'px',
        ];
        $css = '';
        foreach ($vars as $name => $value) {
            $css .= $name . ':' . $value . ';';
        }

        return ':root{' . $css . '}';
    }

    /** Black or white, whichever reads better on the given background. */
    public static function inkFor(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);

        return $luminance > 0.36 ? '#0a0f1a' : '#ffffff';
    }

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim(self::color($hex, '#000000'), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function color(mixed $value, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m)) {
            $value = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
    }

    /**
     * Turns an uploaded image into a data URI (PNG, JPEG, WebP, GIF or ICO).
     * SVG is refused: it can carry scripts.
     *
     * @param array<string,mixed> $file entry of $_FILES
     */
    public static function imageFromUpload(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        $path = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($path === '' || !is_file($path) || $size <= 0) {
            return null;
        }
        if ($size > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException(__('image_too_large', ['kb' => (string) intdiv(self::MAX_IMAGE_BYTES, 1024)]));
        }
        $bytes = (string) file_get_contents($path);
        $mime = self::sniffImage($bytes);
        if ($mime === null) {
            throw new \InvalidArgumentException(__('image_invalid'));
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    public static function sniffImage(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, "\x00\x00\x01\x00") => 'image/x-icon',
            default => null,
        };
    }

    /** Only data URIs produced by imageFromUpload() are accepted back. */
    public static function imageUri(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('#^data:image/(png|jpeg|gif|webp|x-icon);base64,[A-Za-z0-9+/=]+$#', $value) ? $value : '';
    }

    /**
     * Custom CSS is admin-only, but it is still printed inside a <style> tag:
     * strip anything that could close the tag or pull remote resources.
     */
    public static function sanitizeCss(string $css): string
    {
        $css = mb_substr($css, 0, 20000);
        $css = str_ireplace(['</style', '<style', '<script', '</script', '<!--', '-->'], '', $css);
        $css = preg_replace('/@import[^;]*;?/i', '', $css) ?? '';
        $css = preg_replace('/expression\s*\(/i', '', $css) ?? '';

        return trim($css);
    }

    public static function safeLink(string $link): string
    {
        if ($link === '') {
            return '';
        }
        if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
            return $link;
        }

        return preg_match('#^https?://[^\s"<>]+$#i', $link) ? $link : '';
    }

    public static function sectionEnabled(string $section, ?array $theme = null): bool
    {
        $theme ??= self::get();

        return in_array($section, $theme['home_sections'], true);
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = (string) $value;

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
