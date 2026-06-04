<?php
declare(strict_types=1);

function normalize_playlist_title(string $title, string $source = '', string $type = 'url'): string
{
    $title = clean_text($title);
    if ($title !== '' && !metadata_value_is_empty($title)) {
        return $title;
    }

    $host = (string) (parse_url($source, PHP_URL_HOST) ?: '');
    if ($host !== '') {
        return $host;
    }

    return $type === 'file' ? 'Локальный плейлист' : 'Плейлист';
}

function metadata_value_is_empty(string $value): bool
{
    $value = text_lower(trim($value));
    $value = trim($value, " \t\n\r\0\x0B.,;:|/\\[](){}");
    return $value === ''
        || in_array($value, [
            'undefined',
            'unknown',
            'null',
            'none',
            'n/a',
            'na',
            'not set',
            'no country',
            'без страны',
            'неизвестно',
            'разное',
            'other',
            'others',
            'misc',
            '-',
            '--',
            '0',
        ], true);
}

function normalize_country_value(string $value): string
{
    $value = clean_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $value = trim($value, " \t\n\r\0\x0B.,;:|/\\[](){}");
    if (metadata_value_is_empty($value)) {
        return '';
    }

    $upper = strtoupper($value);
    $label = country_code_to_label($upper);
    if ($label !== '') {
        return $label;
    }

    if (preg_match('~\b([a-z]{2,3})\b~i', $value, $match)) {
        $label = country_code_to_label(strtoupper($match[1]));
        if ($label !== '') {
            return $label;
        }
    }

    return $value;
}

function infer_country_from_context(string $country, array $contextParts): string
{
    $normalized = normalize_country_value($country);
    if ($normalized !== '') {
        return $normalized;
    }

    $context = clean_text(implode(' ', array_filter($contextParts, static fn (string $part): bool => trim($part) !== '')));
    if ($context === '') {
        return '';
    }

    if (preg_match('~/(?:countries)/([a-z]{2,3})\.m3u\b~i', $context, $match)) {
        $label = country_code_to_label(strtoupper($match[1]));
        if ($label !== '') {
            return $label;
        }
    }
    return '';
}

function country_code_to_label(string $code): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('~^[A-Z]{2}$~', $code)) {
        return '';
    }

    if (class_exists('Locale')) {
        $label = \Locale::getDisplayRegion('und-' . $code, 'ru');
        $label = clean_text(is_string($label) ? $label : '');
        if ($label !== '' && strtoupper($label) !== $code) {
            return text_ucfirst($label);
        }
    }

    return $code;
}

function country_alias_values(string $country): array
{
    $normalized = normalize_country_value($country);
    if ($normalized === '') {
        return [];
    }

    $values = [$normalized => true, $country => true];
    $upper = strtoupper($country);
    if (preg_match('~^[A-Z]{2}$~', $upper)) {
        $values[$upper] = true;
    }

    return array_values(array_filter(array_keys($values), static fn (string $value): bool => trim($value) !== ''));
}

function country_filter_condition(string $country, array &$params): string
{
    $aliases = country_alias_values($country);
    if (!$aliases) {
        return '0 = 1';
    }

    $placeholders = [];
    foreach ($aliases as $index => $alias) {
        $key = ':country_alias_' . $index;
        $placeholders[] = $key;
        $params[$key] = $alias;
    }

    return 'country IN (' . implode(', ', $placeholders) . ')';
}

function country_compare(string $left, string $right): int
{
    return strnatcasecmp($left, $right);
}

function country_order_case_sql(string $column = 'country'): string
{
    return "CASE WHEN " . $column . " = '' THEN 1 ELSE 0 END";
}
