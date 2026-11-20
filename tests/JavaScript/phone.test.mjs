import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { normalizePhone, phoneError, phoneMessage } from '../../resources/js/phone.js';

const vectors = JSON.parse(readFileSync(new URL('../fixtures/phone-numbers.json', import.meta.url)));

test('il gestionale normalizza i numeri validi come il backend', () => {
    for (const [input, normalized] of Object.entries(vectors.valid)) {
        assert.equal(normalizePhone(input), normalized, input);
        assert.equal(normalizePhone(normalized), normalized, `idempotente: ${normalized}`);
        assert.equal(phoneError(input), null, input);
    }
});

test('il gestionale rifiuta gli stessi numeri non validi del backend', () => {
    for (const input of vectors.invalid) {
        assert.equal(normalizePhone(input), null, input);
        assert.equal(phoneError(input), phoneMessage, input);
    }
    assert.equal(normalizePhone(3331234567), null);
    assert.equal(normalizePhone(null), null);
});
