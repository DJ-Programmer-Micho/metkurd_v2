// Helpers
function getInputElement(i) { return document.getElementById(`digit${i}-input`); }

function focusNextFilledOrLast(idx, max) {
  for (let i = idx; i <= max; i++) {
    const el = getInputElement(i);
    if (el && !el.value) { el.focus(); return; }
  }
  getInputElement(max)?.focus();
}

// Wire up OTP behavior (1..count)
function initOtp(count = 6) {
  for (let i = 1; i <= count; i++) {
    const el = getInputElement(i);
    if (!el) continue;

    // Move on single char, go back on backspace
    el.addEventListener('keydown', (e) => {
      const key = e.key;
      if (key === 'Backspace') {
        if (!el.value && i > 1) getInputElement(i - 1).focus();
        return; // allow default to clear
      }
      if (key === 'ArrowLeft' && i > 1) { e.preventDefault(); getInputElement(i - 1).focus(); }
      if (key === 'ArrowRight' && i < count) { e.preventDefault(); getInputElement(i + 1).focus(); }
    });

    el.addEventListener('input', () => {
      // keep only one digit
      el.value = el.value.replace(/\D/g, '').slice(0, 1);
      if (el.value && i < count) getInputElement(i + 1).focus();
      if (i === count && el.value.length === 1) {
        // last box filled — you can submit here if you want
        // console.log('submit code');
      }
    });

    // Paste on any box: distribute across all
    el.addEventListener('paste', (ev) => {
      ev.preventDefault();
      let clip = (ev.clipboardData || window.clipboardData).getData('text');
      const digits = clip.replace(/\D/g, '').slice(0, count);
      if (!digits) return;

      // Starting index = current box
      let j = 0;
      for (let k = i; k <= count && j < digits.length; k++, j++) {
        const target = getInputElement(k);
        if (target) target.value = digits[j];
      }
      // If more digits than remaining boxes, fill from the start
      for (let k = 1; j < digits.length && k < i; k++, j++) {
        const target = getInputElement(k);
        if (target) target.value = digits[j];
      }

      // Focus next empty (or last)
      focusNextFilledOrLast(1, count);

      // Optionally auto-submit if all filled
      const allFilled = Array.from({ length: count }, (_, idx) => getInputElement(idx + 1)?.value).every(Boolean);
      if (allFilled) {
        // console.log('submit code');
      }
    });
  }
}

// Call once after DOM is ready
initOtp(6);
