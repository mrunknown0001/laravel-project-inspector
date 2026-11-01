// Copy-to-clipboard buttons. The page works without this script.
document.querySelectorAll('[data-copy]').forEach((button) => {
  const target = document.querySelector(button.getAttribute('data-copy'));
  if (!target || !navigator.clipboard) {
    button.hidden = true;
    return;
  }
  button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(target.textContent.trim());
      button.textContent = 'Copied';
      button.classList.add('copied');
    } catch {
      button.textContent = 'Press Ctrl+C';
    }
    setTimeout(() => {
      button.textContent = 'Copy';
      button.classList.remove('copied');
    }, 1800);
  });
});
