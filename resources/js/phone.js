// Telefoni dei destinatari: stessa logica di App\Support\PhoneNumber (casi condivisi in tests/fixtures/phone-numbers.json).
export const phoneMessage = 'Inserisci un numero di telefono valido.';

function fictitious(number) {
    if (/^.(\d)\1+$/.test(number) || /(\d)\1{6}$/.test(number)) return true;
    let ascending = true;
    let descending = true;
    for (let index = 1; index < number.length; index++) {
        const step = Number(number[index]) - Number(number[index - 1]);
        ascending = ascending && (step === 1 || step === -9);
        descending = descending && (step === -1 || step === 9);
    }
    return ascending || descending;
}

export function normalizePhone(value) {
    if (typeof value !== 'string') return null;
    const raw = value.trim();
    if (raw === '' || [...raw].length > 30 || !/^\+?[0-9 ()./-]+$/.test(raw)) return null;
    let digits = raw.replace(/\D/g, '');
    const international = raw.startsWith('+') || digits.startsWith('00');
    if (digits.startsWith('00')) digits = digits.slice(2);
    if (international && !digits.startsWith('39')) {
        return /^[1-9]\d{7,14}$/.test(digits) && !fictitious(digits.slice(1)) ? `+${digits}` : null;
    }
    const national = international ? digits.slice(2) : digits;
    return /^(?:0\d{5,10}|3[1-9]\d{7,8})$/.test(national) && !fictitious(national) ? `+39 ${national}` : null;
}

export const phoneError = value => (normalizePhone(value) ? null : phoneMessage);

/** Campi `input[data-phone]`: messaggio nativo del browser, il valore digitato non viene mai cancellato. */
export function bindPhoneFields(root = document) {
    for (const input of root.querySelectorAll('input[data-phone]')) {
        if (input.dataset.phoneBound) continue;
        input.dataset.phoneBound = 'true';
        const check = () => input.setCustomValidity(input.value.trim() === '' || normalizePhone(input.value) ? '' : phoneMessage);
        input.addEventListener('input', check);
        input.addEventListener('blur', check);
        check();
    }
}
