<?php

namespace MxMigrations\Model;

final class SqlFieldDefinition
{
    /** @return array<string, string> */
    public function parse(string $column, string $sql, ?string $phptype = null): array
    {
        $sql = trim($sql);
        if (!preg_match('~^([a-z]+)(?:\s*\(([^)]*)\))?~i', $sql, $match)) {
            throw new \InvalidArgumentException('Не удалось разобрать тип колонки: ' . $sql);
        }

        $dbtype = strtolower($match[1]);
        $attributes = ['key' => $column, 'dbtype' => $dbtype];
        if (isset($match[2]) && trim($match[2]) !== '') {
            $attributes['precision'] = trim($match[2]);
        }
        if (preg_match('~\bunsigned\b~i', $sql)) {
            $attributes['attributes'] = 'unsigned';
        }
        $attributes['phptype'] = $phptype ?: $this->phpType($dbtype);
        $attributes['null'] = preg_match('~\bnot\s+null\b~i', $sql) ? 'false' : 'true';
        if (preg_match('~\bdefault\s+(\'[^\']*\'|"[^"]*"|[^\s]+)~i', $sql, $default)) {
            $value = trim($default[1], '\'"');
            if (strcasecmp($value, 'null') !== 0) {
                $attributes['default'] = $value;
            }
        }
        if (preg_match('~\bauto_increment\b~i', $sql)) {
            $attributes['generated'] = 'native';
        }

        return $attributes;
    }

    private function phpType(string $dbtype): string
    {
        if (preg_match('~^bool~i', $dbtype)) { return 'boolean'; }
        if (preg_match('~int~i', $dbtype)) { return 'integer'; }
        if (preg_match('~^(decimal|numeric|float|double|real)~i', $dbtype)) { return 'float'; }
        if (preg_match('~^timestamp$~i', $dbtype)) { return 'timestamp'; }
        if (preg_match('~^datetime$~i', $dbtype)) { return 'datetime'; }
        if (preg_match('~^date$~i', $dbtype)) { return 'date'; }
        if (preg_match('~(binary|blob)~i', $dbtype)) { return 'binary'; }
        if (preg_match('~^bit$~i', $dbtype)) { return 'bit'; }

        return 'string';
    }
}
