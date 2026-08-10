<?php

namespace MxMigrations\Model;

final class SchemaDocument
{
    private \DOMDocument $document;

    public function __construct(string $path)
    {
        if (!class_exists('DOMDocument')) {
            throw new \RuntimeException('Для работы с xPDO-схемами требуется PHP-расширение DOM.');
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Не удалось прочитать xPDO-схему: ' . $path);
        }
        $this->document = new \DOMDocument();
        $this->document->preserveWhiteSpace = true;
        if (!@$this->document->loadXML($contents)) {
            throw new \RuntimeException('Некорректный XML в схеме: ' . $path);
        }
    }

    public function getPackage(): string
    {
        return trim((string)$this->document->documentElement->getAttribute('package'), '\\');
    }

    public function classForTable(string $table): ?string
    {
        foreach ($this->objects() as $object) {
            if ($object->getAttribute('table') === $table) {
                return $object->getAttribute('class');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $change */
    public function apply(array $change): void
    {
        $class = $this->classForTable((string)($change['table'] ?? '')) ?: (string)$change['class'];
        $object = $this->object($class);
        $recipe = (string)$change['recipe'];
        if ($recipe === 'add-column' || $recipe === 'modify-column') {
            $column = (string)$change['column'];
            $field = $this->field($object, $column);
            $phptype = $field ? $field->getAttribute('phptype') : null;
            $attributes = (new SqlFieldDefinition())->parse($column, (string)$change['type'], $phptype ?: null);
            if ($field && $field->hasAttribute('index')) {
                $attributes['index'] = $field->getAttribute('index');
            }
            if (!$field) {
                $field = $this->document->createElement('field');
                $this->insertBeforeMetadata($object, $field);
            }
            $this->replaceAttributes($field, $attributes);
            return;
        }
        if ($recipe === 'drop-column') {
            $field = $this->field($object, (string)$change['column']);
            if ($field) {
                $object->removeChild($field);
            }
            return;
        }
        if ($recipe === 'add-index') {
            $this->removeIndex($object, (string)$change['name']);
            $index = $this->document->createElement('index');
            $index->setAttribute('alias', (string)$change['name']);
            $index->setAttribute('name', (string)$change['name']);
            $index->setAttribute('primary', 'false');
            $index->setAttribute('unique', !empty($change['unique']) ? 'true' : 'false');
            $index->setAttribute('type', 'BTREE');
            foreach ((array)$change['columns'] as $column) {
                if (!$this->field($object, (string)$column)) {
                    throw new \RuntimeException('Колонки ' . $column . ' нет у объекта ' . $class . '.');
                }
                $node = $this->document->createElement('column');
                $node->setAttribute('key', (string)$column);
                $node->setAttribute('length', '');
                $node->setAttribute('collation', 'A');
                $node->setAttribute('null', 'false');
                $index->appendChild($node);
            }
            $this->insertBeforeRelations($object, $index);
            return;
        }
        if ($recipe === 'drop-index') {
            $this->removeIndex($object, (string)$change['name']);
        }
    }

    public function save(string $path): void
    {
        $contents = $this->document->saveXML();
        if ($contents === false) {
            throw new \RuntimeException('Не удалось собрать XML-схему.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог: ' . $dir);
        }
        if (@file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Не удалось записать xPDO-схему: ' . $path);
        }
    }

    /** @return array<int, \DOMElement> */
    private function objects(): array
    {
        $objects = [];
        foreach ($this->document->getElementsByTagName('object') as $object) {
            if ($object instanceof \DOMElement) { $objects[] = $object; }
        }
        return $objects;
    }

    private function object(string $class): \DOMElement
    {
        foreach ($this->objects() as $object) {
            if ($object->getAttribute('class') === $class) { return $object; }
        }
        throw new \RuntimeException('Объект ' . $class . ' не найден в xPDO-схеме.');
    }

    private function field(\DOMElement $object, string $key): ?\DOMElement
    {
        foreach ($object->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->tagName === 'field' && $node->getAttribute('key') === $key) {
                return $node;
            }
        }
        return null;
    }

    /** @param array<string, string> $attributes */
    private function replaceAttributes(\DOMElement $element, array $attributes): void
    {
        while ($element->attributes->length > 0) {
            $element->removeAttributeNode($element->attributes->item(0));
        }
        foreach ($attributes as $name => $value) { $element->setAttribute($name, $value); }
    }

    private function removeIndex(\DOMElement $object, string $name): void
    {
        foreach (iterator_to_array($object->childNodes) as $node) {
            if ($node instanceof \DOMElement && $node->tagName === 'index'
                && ($node->getAttribute('alias') === $name || $node->getAttribute('name') === $name)) {
                $object->removeChild($node);
            }
        }
    }

    private function insertBeforeMetadata(\DOMElement $object, \DOMElement $node): void
    {
        foreach ($object->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array($child->tagName, ['index', 'aggregate', 'composite'], true)) {
                $object->insertBefore($node, $child);
                return;
            }
        }
        $object->appendChild($node);
    }

    private function insertBeforeRelations(\DOMElement $object, \DOMElement $node): void
    {
        foreach ($object->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array($child->tagName, ['aggregate', 'composite'], true)) {
                $object->insertBefore($node, $child);
                return;
            }
        }
        $object->appendChild($node);
    }
}
