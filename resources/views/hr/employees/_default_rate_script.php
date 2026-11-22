<?php

declare(strict_types=1);

/*
 * Shared by hr/employees/edit.php and rehire.php. Picking a position fills
 * #daily_rate with that position's default rate (option data-default-rate,
 * from Settings > Positions). A rate HR typed is never overwritten: the field
 * is only filled when it is empty or still holds the last auto-filled value.
 * The server still validates the posted rate (minimum wage .. max). [EMP-023]
 */
?>
<script>
function wbFillDefaultRate(opt) {
    var input = document.getElementById('daily_rate');
    if (!input || !opt) { return; }
    var hint = document.getElementById('daily_rate_hint');
    var def  = opt.getAttribute('data-default-rate') || '';
    var last = input.getAttribute('data-autofilled') || '';
    var cur  = String(input.value || '').trim();
    if (cur !== '' && cur !== last) { return; } // HR typed their own rate
    if (def === '') {
        if (cur !== '' && cur === last) { input.value = ''; }
        input.removeAttribute('data-autofilled');
        if (hint) { hint.style.display = 'none'; hint.textContent = ''; }
    } else {
        input.value = def;
        input.setAttribute('data-autofilled', def);
        if (hint) {
            hint.textContent = 'Filled from this position\'s default rate (₱'
                + Number(def).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ').';
            hint.style.display = 'block';
        }
    }
    input.dispatchEvent(new Event('input', {bubbles: true}));
    input.dispatchEvent(new Event('change', {bubbles: true}));
}
(function () {
    var input = document.getElementById('daily_rate');
    if (!input) { return; }
    input.addEventListener('input', function (e) {
        if (!e.isTrusted) { return; }
        var hint = document.getElementById('daily_rate_hint');
        if (input.value !== (input.getAttribute('data-autofilled') || '') && hint) { hint.style.display = 'none'; }
    });
})();
</script>
