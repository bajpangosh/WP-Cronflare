(function () {
  function getSourceValue(target) {
    const source = document.querySelector('[data-copy-source="' + target + '"]');
    return source ? source.value : '';
  }

  function copyText(target) {
    const value = getSourceValue(target);
    if (!value) {
      return;
    }

    navigator.clipboard.writeText(value).then(function () {
      const button = document.querySelector('[data-copy-target="' + target + '"]');
      if (!button) {
        return;
      }

      const original = button.textContent;
      button.textContent = 'Copied';
      setTimeout(function () {
        button.textContent = original;
      }, 1200);
    });
  }

  document.addEventListener('click', function (event) {
    const copyTarget = event.target.getAttribute('data-copy-target');
    if (copyTarget) {
      copyText(copyTarget);
    }

    if (event.target.hasAttribute('data-toggle-secret')) {
      const input = document.getElementById('wp-cronflare-secret');
      if (!input) {
        return;
      }

      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      event.target.textContent = isPassword ? 'Hide' : 'Show';
    }

    if (event.target.hasAttribute('data-toggle-token')) {
      const input = document.getElementById('wp-cronflare-token');
      if (!input) {
        return;
      }

      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      event.target.textContent = isPassword ? 'Hide' : 'Show';
    }

    if (event.target.hasAttribute('data-toggle-client-secret')) {
      const input = document.getElementById('wp-cronflare-oauth-client-secret');
      if (!input) {
        return;
      }

      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      event.target.textContent = isPassword ? 'Hide' : 'Show';
    }
  });
})();
