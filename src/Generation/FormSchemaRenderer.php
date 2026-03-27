<?php

declare(strict_types=1);

namespace Foundry\Generation;

final class FormSchemaRenderer
{
    /**
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,string> $errors
     * @param array<string,mixed> $old
     */
    public function render(string $formId, array $fields, array $errors = [], array $old = [], bool $includeCsrf = true): string
    {
        $lines = [];
        $lines[] = '<form id="' . htmlspecialchars($formId, ENT_QUOTES) . '" method="post" enctype="multipart/form-data">';

        if ($includeCsrf) {
            $lines[] = '  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken ?? \"\", ENT_QUOTES) ?>" />';
        }

        foreach ($this->sortedFields($fields) as $name => $field) {
            $type = (string) ($field['form'] ?? 'text');
            $id = $formId . '__' . $name;
            $label = (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $name)));
            $help = (string) ($field['help'] ?? '');
            $required = (bool) ($field['required'] ?? false);
            $errorKey = '$errors[\'' . addslashes($name) . '\'] ?? \"\"';
            $oldKey = '$old[\'' . addslashes($name) . '\'] ?? \"\"';
            $describedBy = trim($id . '__help ' . $id . '__error');
            $lines[] = '  <div class="fdy-field" data-field="' . htmlspecialchars($name, ENT_QUOTES) . '">';

            if (!in_array($type, ['hidden'], true)) {
                $lines[] = '    <label for="' . htmlspecialchars($id, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . ($required ? ' *' : '') . '</label>';
            }

            $lines = array_merge($lines, $this->renderFieldInput($id, $name, $type, $field, $oldKey, $describedBy));

            if ($help !== '' && $type !== 'hidden') {
                $lines[] = '    <small id="' . htmlspecialchars($id, ENT_QUOTES) . '__help">' . htmlspecialchars($help, ENT_QUOTES) . '</small>';
            }

            if ($type !== 'hidden') {
                $lines[] = '    <?php if ((' . $errorKey . ') !== ""): ?>';
                $lines[] = '      <p id="' . htmlspecialchars($id, ENT_QUOTES) . '__error" class="fdy-error"><?= htmlspecialchars(' . $errorKey . ', ENT_QUOTES) ?></p>';
                $lines[] = '    <?php endif; ?>';
            }

            $lines[] = '  </div>';
        }

        $lines[] = '  <button type="submit">Submit</button>';
        $lines[] = '</form>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    private function sortedFields(array $fields): array
    {
        ksort($fields);

        return $fields;
    }

    /**
     * @param array<string,mixed> $field
     * @return array<int,string>
     */
    private function renderFieldInput(string $id, string $name, string $type, array $field, string $oldKey, string $describedBy): array
    {
        $attrs = [
            'id="' . htmlspecialchars($id, ENT_QUOTES) . '"',
            'name="' . htmlspecialchars($name, ENT_QUOTES) . '"',
            'aria-describedby="' . htmlspecialchars($describedBy, ENT_QUOTES) . '"',
        ];

        if ((bool) ($field['required'] ?? false)) {
            $attrs[] = 'required';
        }

        $maxLength = isset($field['maxLength']) ? (int) $field['maxLength'] : null;
        if ($maxLength !== null && $maxLength > 0) {
            $attrs[] = 'maxlength="' . $maxLength . '"';
        }

        return match ($type) {
            'textarea' => [
                '    <textarea ' . implode(' ', $attrs) . '><?= htmlspecialchars(' . $oldKey . ', ENT_QUOTES) ?></textarea>',
            ],
            'select' => $this->renderSelect($attrs, $field, $oldKey),
            'radio' => $this->renderRadio($name, $field, $oldKey),
            'checkbox' => $this->renderCheckbox($id, $name, $field, $oldKey),
            'datetime' => [
                '    <input type="datetime-local" value="<?= htmlspecialchars(' . $oldKey . ', ENT_QUOTES) ?>" ' . implode(' ', $attrs) . ' />',
            ],
            'hidden' => [
                '    <input type="hidden" value="<?= htmlspecialchars(' . $oldKey . ', ENT_QUOTES) ?>" ' . implode(' ', array_values(array_filter($attrs, static fn(string $attr): bool => !str_starts_with($attr, 'aria-describedby=')))) . ' />',
            ],
            'file' => [
                '    <input type="file" ' . implode(' ', $attrs) . ' />',
            ],
            'tags', 'array' => [
                '    <input type="text" value="<?= htmlspecialchars(is_array(' . $oldKey . ') ? implode(\",\", ' . $oldKey . ') : (string) ' . $oldKey . ', ENT_QUOTES) ?>" ' . implode(' ', $attrs) . ' />',
            ],
            'email', 'password' => [
                '    <input type="' . $type . '" value="<?= htmlspecialchars(' . $oldKey . ', ENT_QUOTES) ?>" ' . implode(' ', $attrs) . ' />',
            ],
            default => [
                '    <input type="text" value="<?= htmlspecialchars(' . $oldKey . ', ENT_QUOTES) ?>" ' . implode(' ', $attrs) . ' />',
            ],
        };
    }

    /**
     * @param array<int,string> $attrs
     * @param array<string,mixed> $field
     * @return array<int,string>
     */
    private function renderSelect(array $attrs, array $field, string $oldKey): array
    {
        $lines = [];
        $lines[] = '    <select ' . implode(' ', $attrs) . '>';
        $options = is_array($field['options'] ?? null) ? $field['options'] : (array) ($field['enum'] ?? []);
        foreach ($options as $key => $value) {
            $optionValue = is_string($key) ? $key : (string) $value;
            $optionLabel = is_string($value) ? $value : (string) $optionValue;
            $lines[] = '      <option value="' . htmlspecialchars($optionValue, ENT_QUOTES) . '" <?= ((string) ' . $oldKey . ' === \'' . addslashes($optionValue) . '\') ? "selected" : "" ?>>' . htmlspecialchars($optionLabel, ENT_QUOTES) . '</option>';
        }
        $lines[] = '    </select>';

        return $lines;
    }

    /**
     * @param array<string,mixed> $field
     * @return array<int,string>
     */
    private function renderRadio(string $name, array $field, string $oldKey): array
    {
        $lines = [];
        $options = is_array($field['options'] ?? null) ? $field['options'] : (array) ($field['enum'] ?? []);
        foreach ($options as $key => $value) {
            $optionValue = is_string($key) ? $key : (string) $value;
            $optionLabel = is_string($value) ? $value : (string) $optionValue;
            $lines[] = '    <label><input type="radio" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars($optionValue, ENT_QUOTES) . '" <?= ((string) ' . $oldKey . ' === \'' . addslashes($optionValue) . '\') ? "checked" : "" ?> /> ' . htmlspecialchars($optionLabel, ENT_QUOTES) . '</label>';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $field
     * @return array<int,string>
     */
    private function renderCheckbox(string $id, string $name, array $field, string $oldKey): array
    {
        if ((bool) ($field['multiple'] ?? false)) {
            $lines = [];
            $options = is_array($field['options'] ?? null) ? $field['options'] : (array) ($field['enum'] ?? []);
            foreach ($options as $key => $value) {
                $optionValue = is_string($key) ? $key : (string) $value;
                $optionLabel = is_string($value) ? $value : (string) $optionValue;
                $lines[] = '    <label><input type="checkbox" name="' . htmlspecialchars($name, ENT_QUOTES) . '[]" value="' . htmlspecialchars($optionValue, ENT_QUOTES) . '" <?= in_array(\'' . addslashes($optionValue) . '\', (array) ' . $oldKey . ', true) ? "checked" : "" ?> /> ' . htmlspecialchars($optionLabel, ENT_QUOTES) . '</label>';
            }

            return $lines;
        }

        return [
            '    <input type="checkbox" id="' . htmlspecialchars($id, ENT_QUOTES) . '" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="1" <?= ((bool) ' . $oldKey . ') ? "checked" : "" ?> />',
        ];
    }
}
