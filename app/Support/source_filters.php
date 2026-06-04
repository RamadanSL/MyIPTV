<?php
declare(strict_types=1);

function source_record_is_russian_relevant(array $source): bool
{
    $url = (string) ($source['source'] ?? $source['url'] ?? '');
    if (source_url_is_russian_relevant($url)) {
        return true;
    }

    $country = normalize_country_value((string) ($source['default_country'] ?? $source['country'] ?? ''));
    if (source_country_is_russian_relevant($country)) {
        return true;
    }

    return source_text_is_russian_relevant(implode(' ', [
        (string) ($source['title'] ?? ''),
        $url,
        (string) ($source['default_city'] ?? $source['city'] ?? ''),
    ]));
}

function source_url_is_russian_relevant(string $url): bool
{
    $path = text_lower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if ($path === '') {
        return false;
    }

    $base = text_lower(pathinfo($path, PATHINFO_FILENAME));
    if (str_contains($path, '/countries/')) {
        return in_array($base, russian_relevant_country_codes(), true);
    }

    if (str_contains($path, '/languages/')) {
        return in_array($base, ['ru', 'rus', 'russian'], true);
    }

    return false;
}

function source_country_is_russian_relevant(string $country): bool
{
    $value = text_lower(normalize_country_value($country));
    return in_array($value, ['ru', 'rus'], true)
        || in_array($value, russian_relevant_country_labels(), true)
        || preg_match('~^(?:росси\pL*|русск\pL*|russian)$~u', $value) === 1;
}

function source_text_is_russian_relevant(string $text): bool
{
    $value = text_lower($text);
    if (preg_match('~(?<![\pL\pN])(?:росси\pL*|русск\pL*|russian|rus|belarus|kazakhstan|kyrgyzstan|беларус\pL*|казахстан|киргиз\pL*|кыргыз\pL*)(?![\pL\pN])~u', $value) === 1) {
        return true;
    }

    return preg_match('~/(?:countries)/(?:ru|by|kz|kg)\.m3u\b~i', $value) === 1
        || preg_match('~/(?:languages)/(?:ru|rus|russian)\.m3u\b~i', $value) === 1;
}

function russian_source_title(string $url, string $fallback = ''): string
{
    $path = text_lower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    $base = text_lower(pathinfo($path, PATHINFO_FILENAME));

    if (str_contains($path, '/countries/') && in_array($base, ['ru', 'rus'], true)) {
        $country = country_code_to_label('RU');
        return 'Публичный источник: ' . ($country !== '' ? $country : 'RU');
    }

    if (str_contains($path, '/countries/') && in_array($base, russian_relevant_country_codes(), true)) {
        $country = country_code_to_label(strtoupper($base));
        return 'Публичный источник: ' . ($country !== '' ? $country : strtoupper($base));
    }

    if (str_contains($path, '/languages/') && in_array($base, ['ru', 'rus', 'russian'], true)) {
        return 'Публичный источник: русский язык';
    }

    return normalize_playlist_title($fallback, $url, 'url');
}

function russian_relevant_country_codes(): array
{
    return ['ru', 'rus', 'by', 'kz', 'kg'];
}

function russian_relevant_country_labels(): array
{
    return array_filter(array_map(static fn (string $code): string => text_lower(country_code_to_label(strtoupper($code))), ['ru', 'by', 'kz', 'kg']));
}
