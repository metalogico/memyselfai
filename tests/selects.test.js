import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';

test('application templates do not introduce native select boxes', () => {
    const resources = new URL('../resources/', import.meta.url);
    const templates = readdirSync(resources, { recursive: true })
        .filter((file) => file.endsWith('.vue') || file.endsWith('.blade.php'));

    const nativeSelects = templates.filter((file) => /<select\b/i.test(
        readFileSync(new URL(file, resources), 'utf8'),
    ));

    assert.deepEqual(nativeSelects, [], 'Use the shared Element Plus AppSelect component for dropdowns.');
});
