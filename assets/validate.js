
// Progressive form validation: friendly messages instead of the browser's default bubble.
(function () {
    function message(field) {
        var v = field.value;
        if (field.validity.valueMissing) return 'Please fill in this field.';
        if (field.validity.patternMismatch) return field.title || 'Please match the requested format.';
        if (field.validity.tooShort) return 'Use at least ' + field.minLength + ' characters.';
        if (field.validity.tooLong) return 'Use at most ' + field.maxLength + ' characters.';
        if (field.validity.rangeUnderflow) return 'Value must be at least ' + field.min + '.';
        if (field.validity.rangeOverflow) return 'Value must be at most ' + field.max + '.';
        if (field.validity.stepMismatch) return 'Use up to 2 decimal places.';
        if (field.validity.typeMismatch) return 'Please enter a valid value.';
        if (field.validity.customError) return field.validationMessage;
        return '';
    }
    function noteFor(field) {
        var wrap = field.closest('.pw-wrap') || field;
        var note = wrap.parentElement.querySelector(':scope > .field-error[data-for="' + field.name + '"]');
        if (!note) { note = document.createElement('small'); note.className = 'field-error'; note.dataset.for = field.name; wrap.insertAdjacentElement('afterend', note); }
        return note;
    }
    function show(field) {
        var msg = message(field);
        field.classList.toggle('invalid', !!msg);
        var note = noteFor(field);
        note.textContent = msg;
        note.classList.toggle('show', !!msg);
    }
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        var fields = Array.from(form.querySelectorAll('input:not([type=hidden]):not([disabled]),select,textarea'));
        fields.forEach(function (field) {
            field.addEventListener('blur', function () { if (field.value !== '' || field.required) show(field); });
            field.addEventListener('input', function () { if (field.classList.contains('invalid')) show(field); });
            field.addEventListener('change', function () { if (field.classList.contains('invalid')) show(field); });
        });
        form.addEventListener('submit', function (e) {
            var first = null;
            fields.forEach(function (field) { show(field); if (field.classList.contains('invalid') && !first) first = field; });
            if (first) { e.preventDefault(); e.stopImmediatePropagation(); first.focus({ preventScroll: false }); first.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        }, true);
    });
})();

// Mobile navigation: slide-out drawer with overlay, body scroll lock and Escape support.
(function () {
    var body = document.body, btn = document.querySelector('[data-menu]'); if (!btn) return;
    function setOpen(on) {
        body.classList.toggle('nav-open', on);
        btn.setAttribute('aria-expanded', on ? 'true' : 'false');
        body.style.overflow = on ? 'hidden' : '';
    }
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', function () { setOpen(!body.classList.contains('nav-open')); });
    document.querySelectorAll('.snav a,.snav [data-chat-open]').forEach(function (a) { a.addEventListener('click', function () { setOpen(false); }); });
    var scrim = document.createElement('div'); scrim.className = 'nav-scrim'; scrim.setAttribute('aria-hidden', 'true');
    scrim.addEventListener('click', function () { setOpen(false); });
    body.appendChild(scrim);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && body.classList.contains('nav-open')) setOpen(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 900 && body.classList.contains('nav-open')) setOpen(false); });
})();
